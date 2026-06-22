// Non-custodial deposit ke commitment pool.
//
// Browser
// 1. Derive shielded keypair (shieldPub dari password) + salt random — note milik user
// 2. commitment = Poseidon(amount_wei, shieldPub, salt) (circuit-compatible: withdraw.circom)
// 3. Sign tx ZKPayment.deposit{value: amount_wei}(commitment) pakai user key
// (derive dari password via polygon-key.js)
// 4. Encrypt note + save ke localStorage + backup terenkripsi ke server SEBELUM relay
// (salt aman lebih dulu — anti-hilang dana)
// 5. POST raw hex ke /payment/relay (generic relay), lalu tunggu konfirmasi on-chain
//
// Load
// <script type="module" src="{{ asset('js/polygon-deposit.js') }}"></script>

import { ethers } from 'ethers';
import { buildPoseidon } from 'circomlibjs';
import { amoyFloorFees } from './payment-relay.js';
import { recordEvent } from './record-event.js';
import { deriveShieldKeypair } from './shield-key.js';
import { saveNoteRecord } from './note-store.js';

const POLYGON_AMOY_CHAIN_ID = 80002;
const POLYGON_RPC_URL = 'https://rpc-amoy.polygon.technology/';
const FIELD = BigInt('21888242871839275222246405745257275088548364400416034343698204186575808495617');

let poseidon = null;
async function initPoseidon() {
    if (!poseidon) poseidon = await buildPoseidon();
    return poseidon;
}

function randomFieldElement() {
    const bytes = new Uint8Array(32);
    crypto.getRandomValues(bytes);
    let bn = 0n;
    for (const b of bytes) bn = (bn << 8n) | BigInt(b);
    return bn % FIELD;
}


/**
 * Sign + relay deposit ke ZKPayment.deposit{value}(commitment).
 *
 * @param {object} opts
 * @param {string} opts.email
 * @param {string} opts.password
 * @param {string|number} opts.amountMatic - jumlah deposit dalam MATIC
 * @param {string} opts.contractAddress - alamat ZKPayment v2
 * @param {string} opts.csrfToken
 * @returns {Promise<{success:boolean, tx_hash?:string, commitment?:string, storage_key?:string, error?:string}>}
 */
export async function depositToPool({ email, password, amountMatic, contractAddress, csrfToken }) {
    if (!window.PolygonKey) {
        throw new Error('PolygonKey module belum dimuat');
    }
    if (!/^0x[a-fA-F0-9]{40}$/.test(contractAddress)) {
        throw new Error('contractAddress tidak valid (harus EIP-55 0x...)');
    }

    await initPoseidon();

    // 1. Derive shielded keypair + salt acak
    const { shieldPub } = await deriveShieldKeypair(email, password);
    const salt = randomFieldElement();
    const amountWei = ethers.parseEther(String(amountMatic));

    // 2. commitment = Poseidon(amount_wei, shieldPub, salt) (sesuai withdraw.circom)
    const commitment = poseidon.F.toObject(poseidon([amountWei, shieldPub, salt]));

    // 3. Sign tx ZKPayment.deposit(commitment) {value: amountWei}
    const { privateKey, address } = window.PolygonKey.deriveWallet(email, password);

    const provider = new ethers.JsonRpcProvider(POLYGON_RPC_URL, {
        chainId: POLYGON_AMOY_CHAIN_ID,
        name: 'amoy',
    });
    const wallet = new ethers.Wallet('0x' + privateKey, provider);

    const iface = new ethers.Interface([
        'function deposit(uint256 commitment) payable',
    ]);
    const data = iface.encodeFunctionData('deposit', [commitment]);

    const [feeData, nonce] = await Promise.all([
        provider.getFeeData(),
        provider.getTransactionCount(address, 'pending'),
    ]);

    // Amoy menolak priority fee < 25 gwei → floor ke 30 gwei.
    const { maxFeePerGas, maxPriorityFeePerGas } = amoyFloorFees(feeData);

    // Estimasi gas — deposit ~50-80k, kita pakai 150k headroom
    const tx = {
        to: contractAddress,
        value: amountWei,
        data,
        nonce,
        chainId: POLYGON_AMOY_CHAIN_ID,
        type: 2,
        maxFeePerGas,
        maxPriorityFeePerGas,
        gasLimit: 150000n,
    };

    const signedTx = await wallet.signTransaction(tx);

    // 4. Simpan + backup note SEBELUM relay → salt aman lebih dulu (anti-hilang).
    // src_tx null dulu (belum ada tx); tak masalah untuk withdraw (butuh salt saja).
    let storageKey;
    try {
        storageKey = await saveNoteRecord({
            commitment: commitment.toString(), salt: salt.toString(),
            amount_wei: amountWei.toString(), owner_shield_pub: shieldPub.toString(),
            source: 'deposit', src_tx: null,
        }, email, password);
    } catch (e) { console.warn('Simpan note deposit gagal:', e.message); }

    // 5. POST ke /payment/relay (generic relay)
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

    const txHash = body.tx_hash;

    // 6. Tunggu tx di-mined sebelum klaim sukses. `eth_sendRawTransaction` cuma
    // memasukkan tx ke mempool — `activeCommitments[commitment]` baru true setelah
    // blok ter-mined (~beberapa detik di Amoy). Tanpa menunggu, saldo pool yang
    // dihitung langsung sesudahnya akan kosong (isCommitmentActive=false).
    try {
        const receipt = await provider.waitForTransaction(txHash, 1, 120000);
        if (!receipt) {
            return {
                success: false, confirmed: false, tx_hash: txHash,
                commitment: commitment.toString(), storage_key: storageKey,
                error: 'Konfirmasi on-chain melebihi 120 dtk. Note sudah tersimpan — saldo akan muncul otomatis saat tx ter-mined.',
            };
        }
        if (receipt.status !== 1) {
            return {
                success: false, confirmed: true, tx_hash: txHash,
                error: 'Transaksi deposit revert on-chain (status 0). Cek saldo gas / commitment.',
            };
        }
    } catch (e) {
        return {
            success: false, confirmed: false, tx_hash: txHash,
            commitment: commitment.toString(), storage_key: storageKey,
            error: 'Gagal konfirmasi tx: ' + e.message + '. Note sudah tersimpan; cek saldo pool nanti.',
        };
    }

    // Catat ke riwayat (deposit = data publik on-chain). Best-effort.
    await recordEvent({ type: 'deposit', polygonTxHash: txHash, amountMatic, csrfToken });

    return {
        success: true,
        confirmed: true,
        tx_hash: txHash,
        commitment: commitment.toString(),
        storage_key: storageKey,
    };
}

if (typeof window !== 'undefined') {
    window.PolygonDeposit = { depositToPool };
    window.dispatchEvent(new Event('polygon-deposit-ready'));
}
