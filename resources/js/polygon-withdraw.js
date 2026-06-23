// Non-custodial withdraw dari commitment pool.
//
// Browser
// 1. List notes dari localStorage (encrypted)
// 2. User pilih note (atau auto pick) → decrypt {commitment, secret, salt, amount}
// 3. Generate Groth16 proof pakai snarkjs.fullProve(withdraw circuit, witness)
// - public: [commitment, nullifier, recipient, amount]
// - private: [shieldPriv, salt] (commitment = Poseidon(amount, Poseidon(shieldPriv), salt))
// 4. verifikasi proof LOKAL (zk-verify.js) — tanpa round-trip ke server
// 5. Sign tx ZKPayment.withdraw(a, b, c, pubSignals) pakai user key
// 6. POST raw hex ke /payment/relay
// 7. Mark note used di localStorage
//
// Load
// <script type="module" src="{{ asset('js/polygon-withdraw.js') }}"></script>

import { ethers } from 'ethers';
import { buildPoseidon } from 'circomlibjs';
import * as snarkjs from 'snarkjs';
import { amoyFloorFees } from './payment-relay.js';
import { recordEvent } from './record-event.js';
import { deriveShieldKeypair } from './shield-key.js';
import { verifyProofLocal } from './zk-verify.js';
import {
    NOTE_PREFIX, NOTE_USED_PREFIX,
    deriveNoteEncryptionKey, decryptNote, listNoteKeys, listNotes,
} from './note-store.js';

const POLYGON_AMOY_CHAIN_ID = 80002;
const POLYGON_RPC_URL = 'https://rpc-amoy.polygon.technology/';
const WITHDRAW_WASM = '/zk/withdraw/withdraw.wasm';
const WITHDRAW_ZKEY = '/zk/withdraw/withdraw_final.zkey';

let poseidon = null;
async function initPoseidon() {
    if (!poseidon) poseidon = await buildPoseidon();
    return poseidon;
}

/**
 * Mark note as used di localStorage (untuk anti UI replay).
 */
function markNoteUsed(storageKey, withdrawTxHash) {
    const usedKey = NOTE_USED_PREFIX + storageKey.slice(NOTE_PREFIX.length);
    localStorage.setItem(usedKey, JSON.stringify({
        used_at: new Date().toISOString(),
        withdraw_tx: withdrawTxHash,
    }));
}

/**
 * Generate Groth16 withdraw proof + sign + relay.
 *
 * @param {object} opts
 * @param {string} opts.email
 * @param {string} opts.password
 * @param {string} opts.storageKey - note key di localStorage
 * @param {string} opts.recipientAddress - 0x... target EOA
 * @param {string} opts.contractAddress - ZKPayment v2 address
 * @param {string} opts.csrfToken
 * @returns {Promise<{success, tx_hash?, error?, nullifier?}>}
 */
export async function withdrawFromPool({
    email, password, storageKey, recipientAddress, contractAddress, csrfToken,
}) {
    if (!window.PolygonKey) {
        throw new Error('PolygonKey module belum dimuat');
    }
    if (typeof snarkjs === 'undefined' || !snarkjs.groth16) {
        throw new Error('snarkjs belum dimuat (cek script load di Blade)');
    }
    if (!/^0x[a-fA-F0-9]{40}$/.test(recipientAddress)) {
        throw new Error('Recipient address harus format 0x... EIP-55');
    }
    if (!/^0x[a-fA-F0-9]{40}$/.test(contractAddress)) {
        throw new Error('Contract address tidak valid');
    }

    await initPoseidon();

    // 1. Load + decrypt note
    const key = await deriveNoteEncryptionKey(email, password);
    const ciphertext = localStorage.getItem(storageKey);
    if (!ciphertext) {
        throw new Error('Note tidak ditemukan di localStorage');
    }
    const note = await decryptNote(ciphertext, key);

    const amountWei = BigInt(note.amount_wei);
    const salt = BigInt(note.salt);
    const commitment = BigInt(note.commitment);
    const recipientUint = BigInt(recipientAddress);

    // Derive shieldPriv pemilik note (dari password — tak pernah disimpan)
    const { shieldPriv } = await deriveShieldKeypair(email, password);

    // 2. nullifier = Poseidon(shieldPriv, commitment)
    const nullifier = poseidon.F.toObject(poseidon([shieldPriv, commitment]));

    // 3. Groth16 witness (sesuai withdraw.circom)
    const witness = {
        shieldPriv: shieldPriv.toString(),
        salt: salt.toString(),
        commitment: commitment.toString(),
        nullifier: nullifier.toString(),
        recipient: recipientUint.toString(),
        amount: amountWei.toString(),
    };

    let proof, publicSignals;
    try {
        const result = await snarkjs.groth16.fullProve(witness, WITHDRAW_WASM, WITHDRAW_ZKEY);
        proof = result.proof;
        publicSignals = result.publicSignals;
    } catch (e) {
        throw new Error(`snarkjs fullProve gagal: ${e.message}`);
    }

    // 4. Verifikasi proof LOKAL (hemat gas kalau invalid). Tanpa round-trip server.
    try {
        const ok = await verifyProofLocal('withdraw', proof, publicSignals);
        if (!ok) {
            return {
                success: false,
                error: 'Withdraw proof invalid — kontrak akan tolak tx ini, jangan submit.',
            };
        }
    } catch (e) {
        console.warn('Verify lokal gagal (lanjut, binding tetap on-chain):', e.message);
    }

    // 5. Encode + sign withdraw tx
    const { privateKey, address } = window.PolygonKey.deriveWallet(email, password);

    const provider = new ethers.JsonRpcProvider(POLYGON_RPC_URL, {
        chainId: POLYGON_AMOY_CHAIN_ID,
        name: 'amoy',
    });
    const wallet = new ethers.Wallet('0x' + privateKey, provider);

    const iface = new ethers.Interface([
        'function withdraw(uint256[2] a, uint256[2][2] b, uint256[2] c, uint256[4] pubSignals)',
    ]);

    // snarkjs output: proof.pi_a/pi_b/pi_c masing-masing 3 elemen (Z koordinat ke-3 = 1).
    // Solidity verifier expect [pi_a[0], pi_a[1]] saja (2-elemen, terlewatkan Z).
    const a = [proof.pi_a[0], proof.pi_a[1]];
    const b = [
        [proof.pi_b[0][1], proof.pi_b[0][0]], // swap karena BN254 G2 endianness
        [proof.pi_b[1][1], proof.pi_b[1][0]],
    ];
    const c = [proof.pi_c[0], proof.pi_c[1]];
    const pubSignalsBn = publicSignals.map(s => s);

    const data = iface.encodeFunctionData('withdraw', [a, b, c, pubSignalsBn]);

    const [feeData, nonce] = await Promise.all([
        provider.getFeeData(),
        provider.getTransactionCount(address, 'pending'),
    ]);

    // Amoy menolak priority fee < 25 gwei → floor ke 30 gwei.
    const { maxFeePerGas, maxPriorityFeePerGas } = amoyFloorFees(feeData);

    // Estimasi gas: withdraw + Groth16 pairing ~400-600k. Pakai 700k headroom.
    const tx = {
        to: contractAddress,
        value: 0n,
        data,
        nonce,
        chainId: POLYGON_AMOY_CHAIN_ID,
        type: 2,
        maxFeePerGas,
        maxPriorityFeePerGas,
        gasLimit: 700000n,
    };

    const signedTx = await wallet.signTransaction(tx);

    // 6. POST ke /payment/relay
    const response = await fetch('/payment/relay', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ raw_tx: signedTx }),
    });

    const body = await response.json().catch(() => ({}));
    if (!response.ok || !body.success) {
        return {
            success: false,
            error: body.message || body.error || `HTTP ${response.status}`,
        };
    }

    // 7. Mark note as used
    markNoteUsed(storageKey, body.tx_hash);

    // Catat ke riwayat (withdraw = recipient + amount publik on-chain). Best-effort.
    await recordEvent({
        type: 'withdraw', polygonTxHash: body.tx_hash,
        amountMatic: ethers.formatEther(amountWei), receiverAddress: recipientAddress, csrfToken,
    });

    return {
        success: true,
        tx_hash: body.tx_hash,
        nullifier: nullifier.toString(),
    };
}

if (typeof window !== 'undefined') {
    window.PolygonWithdraw = { withdrawFromPool, listNotes, listNoteKeys };
    window.dispatchEvent(new Event('polygon-withdraw-ready'));
}
