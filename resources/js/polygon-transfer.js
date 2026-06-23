// Non-custodial private transfer — belanjakan note pool → mint kembalian + note penerima,
// kirim note penerima sebagai memo terenkripsi di event on-chain.
//
// Browser:
// 1. Decrypt note pengirim → amountIn, senderSalt, senderCommitment
// 2. Derive shieldPriv (shield-key) + Polygon key (polygon-key)
// 3. Pilih changeSalt + recipientSalt acak; recipientCommitment = Poseidon(amt, recipientShieldPub, recipientSalt)
// 4. Groth16 fullProve(private_transfer)
// 5. ECIES memo {amount_wei, salt, commitment} ke recipientEncPub
// 6. verifikasi proof LOKAL (zk-verify.js) — tanpa round-trip ke server
// 7. Sign privateTransfer(a,b,c,pubSignals,memo) → /payment/relay
// 8. Simpan note kembalian + mark note lama used

import { ethers } from 'ethers';
import { buildPoseidon } from 'circomlibjs';
import * as snarkjs from 'snarkjs';
import { amoyFloorFees } from './payment-relay.js';
import { deriveShieldKeypair } from './shield-key.js';
import { eciesEncrypt } from './note-crypto.js';
import { verifyProofLocal } from './zk-verify.js';
import {
    NOTE_PREFIX, NOTE_USED_PREFIX,
    deriveNoteEncryptionKey, decryptNote, saveNoteRecord,
} from './note-store.js';
import { recordEvent } from './record-event.js';

const POLYGON_AMOY_CHAIN_ID = 80002;
const POLYGON_RPC_URL = 'https://rpc-amoy.polygon.technology/';
// RPC khusus SCAN dana masuk. eth_getLogs di RPC resmi Amoy dibatasi ~100 blok
// → scan dari deploy block mustahil (~5000 query, pasti rate-limit → "0 note"
// palsu). publicnode mengizinkan range 10.000 blok dan CORS '*' (aman dari
// browser) → ~49 query untuk seluruh rentang. Bisa di-override via meta zk-scan-rpc-url.
const SCAN_RPC_URL = 'https://polygon-amoy-bor-rpc.publicnode.com';
const SCAN_MAX_RANGE = 10000; // batas getLogs publicnode: (to - from) ≤ 10000
const FIELD = BigInt('21888242871839275222246405745257275088548364400416034343698204186575808495617');
const TRANSFER_WASM = '/zk/private_transfer/private_transfer.wasm';
const TRANSFER_ZKEY = '/zk/private_transfer/private_transfer_final.zkey';

let poseidon = null;
async function initPoseidon() {
    if (!poseidon) poseidon = await buildPoseidon();
    return poseidon;
}

function randomFieldElement() {
    const bytes = new Uint8Array(32); crypto.getRandomValues(bytes);
    let bn = 0n; for (const b of bytes) bn = (bn << 8n) | BigInt(b);
    return bn % FIELD;
}

function markNoteUsed(storageKey, txHash) {
    const usedKey = NOTE_USED_PREFIX + storageKey.slice(NOTE_PREFIX.length);
    localStorage.setItem(usedKey, JSON.stringify({ used_at: new Date().toISOString(), transfer_tx: txHash }));
}

/**
 * @param {object} opts {email,password,storageKey,recipientShieldPub(bigint|string),
 *   recipientEncPub(Uint8Array|hex),transferAmountMatic,contractAddress,csrfToken}
 * @returns {Promise<{success,tx_hash?,error?,nullifier?,change_storage_key?}>}
 */
export async function transferFromPool({
    email, password, storageKey, recipientShieldPub, recipientEncPub,
    transferAmountMatic, contractAddress, csrfToken,
}) {
    if (!window.PolygonKey) throw new Error('PolygonKey module belum dimuat');
    if (typeof snarkjs === 'undefined' || !snarkjs.groth16) throw new Error('snarkjs belum dimuat');
    if (!/^0x[a-fA-F0-9]{40}$/.test(contractAddress)) throw new Error('Contract address tidak valid');

    await initPoseidon();

    // 1. Decrypt note pengirim
    const key = await deriveNoteEncryptionKey(email, password);
    const ciphertext = localStorage.getItem(storageKey);
    if (!ciphertext) throw new Error('Note tidak ditemukan di localStorage');
    const note = await decryptNote(ciphertext, key);

    const amountIn = BigInt(note.amount_wei);
    const senderSalt = BigInt(note.salt);
    const senderCommitment = BigInt(note.commitment);
    const transferAmount = ethers.parseEther(String(transferAmountMatic));
    if (transferAmount <= 0n) throw new Error('Jumlah transfer harus > 0');
    if (transferAmount > amountIn) throw new Error('Note tidak cukup untuk jumlah transfer');

    // 2. Derive kunci pengirim
    const { shieldPriv, shieldPub: senderShieldPub } = await deriveShieldKeypair(email, password);
    const recipientShieldPubBn = BigInt(recipientShieldPub);

    // 3. Salt + commitment baru
    const changeSalt = randomFieldElement();
    const recipientSalt = randomFieldElement();
    const change = amountIn - transferAmount;
    const newSelfCommitment = poseidon.F.toObject(poseidon([change, senderShieldPub, changeSalt]));
    const recipientCommitment = poseidon.F.toObject(poseidon([transferAmount, recipientShieldPubBn, recipientSalt]));
    const nullifier = poseidon.F.toObject(poseidon([shieldPriv, senderCommitment]));

    // Ref opaque untuk riwayat kirim. WAJIB diturunkan dari rahasia yang TAK PERNAH
    // on-chain (senderSalt) — JANGAN pakai nullifier (public di event PrivateTransfer,
    // bisa direkomputasi → tautan pulih). senderCommitment on-chain, senderSalt rahasia.
    const sendRef = ethers.sha256(ethers.toUtf8Bytes(
        `xevou-send-v1:${senderCommitment.toString()}:${senderSalt.toString()}`,
    )).slice(2); // buang prefix 0x → 64 hex

    // 4. Groth16 proof
    const witness = {
        amountIn: amountIn.toString(),
        senderShieldPriv: shieldPriv.toString(),
        senderSalt: senderSalt.toString(),        // salt note lama (untuk verifikasi senderCommitment)
        transferAmount: transferAmount.toString(),
        changeSalt: changeSalt.toString(),
        recipientShieldPub: recipientShieldPubBn.toString(),
        recipientSalt: recipientSalt.toString(),
        senderCommitment: senderCommitment.toString(),
        nullifier: nullifier.toString(),
        newSelfCommitment: newSelfCommitment.toString(),
        recipientCommitment: recipientCommitment.toString(),
    };
    let proof, publicSignals;
    try {
        const r = await snarkjs.groth16.fullProve(witness, TRANSFER_WASM, TRANSFER_ZKEY);
        proof = r.proof; publicSignals = r.publicSignals;
    } catch (e) { throw new Error(`snarkjs fullProve gagal: ${e.message}`); }

    // 5. ECIES memo note penerima
    const memo = await eciesEncrypt({
        amount_wei: transferAmount.toString(),
        salt: recipientSalt.toString(),
        commitment: recipientCommitment.toString(),
    }, recipientEncPub);

    // 6. Verifikasi proof LOKAL (hemat gas kalau invalid). Tak ada round-trip ke
    // server → server tak lagi melihat commitment/nullifier pengirim.
    try {
        const ok = await verifyProofLocal('private_transfer', proof, publicSignals);
        if (!ok) return { success: false, error: 'Transfer proof invalid — kontrak akan tolak tx ini, jangan submit.' };
    } catch (e) {
        console.warn('Verify lokal gagal (lanjut, binding tetap on-chain):', e.message);
    }

    // 7. Sign + relay
    const { privateKey, address } = window.PolygonKey.deriveWallet(email, password);
    const provider = new ethers.JsonRpcProvider(POLYGON_RPC_URL, { chainId: POLYGON_AMOY_CHAIN_ID, name: 'amoy' });
    const wallet = new ethers.Wallet('0x' + privateKey, provider);
    const iface = new ethers.Interface([
        'function privateTransfer(uint256[2] a, uint256[2][2] b, uint256[2] c, uint256[4] pubSignals, bytes encryptedNote)',
    ]);
    const a = [proof.pi_a[0], proof.pi_a[1]];
    const b = [[proof.pi_b[0][1], proof.pi_b[0][0]], [proof.pi_b[1][1], proof.pi_b[1][0]]];
    const c = [proof.pi_c[0], proof.pi_c[1]];
    const data = iface.encodeFunctionData('privateTransfer', [a, b, c, publicSignals, memo]);

    const [feeData, nonce] = await Promise.all([
        provider.getFeeData(), provider.getTransactionCount(address, 'pending'),
    ]);
    const { maxFeePerGas, maxPriorityFeePerGas } = amoyFloorFees(feeData);
    // Estimasi gas: privateTransfer + Groth16 pairing + 2 commitment SSTORE + calldata memo
    // ~600-750k. Pakai 800k headroom.
    const tx = {
        to: contractAddress, value: 0n, data, nonce, chainId: POLYGON_AMOY_CHAIN_ID,
        type: 2, maxFeePerGas, maxPriorityFeePerGas, gasLimit: 800000n,
    };
    const signedTx = await wallet.signTransaction(tx);

    const response = await fetch('/payment/relay', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
            'X-CSRF-TOKEN': csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ raw_tx: signedTx }),
    });
    const body = await response.json().catch(() => ({}));
    if (!response.ok || !body.success) {
        return { success: false, error: body.message || body.error || `HTTP ${response.status}` };
    }

    // 8. Simpan note kembalian + mark note lama used
    let changeStorageKey = null;
    if (change > 0n) {
        changeStorageKey = await saveNoteRecord({
            commitment: newSelfCommitment.toString(), salt: changeSalt.toString(),
            amount_wei: change.toString(), owner_shield_pub: senderShieldPub.toString(),
            source: 'change', src_tx: body.tx_hash,
        }, email, password);
    }
    markNoteUsed(storageKey, body.tx_hash);

    return { success: true, tx_hash: body.tx_hash, nullifier: nullifier.toString(), send_ref: sendRef, change_storage_key: changeStorageKey };
}

// v2: bump prefix untuk meng-INVALIDASI cursor lama yang mungkin "teracun" —
// versi v1 menaikkan cursor ke `latest` walau sebagian range gagal (rate-limit),
// menandai blok yang belum benar-benar dipindai sebagai sudah dipindai sehingga
// note penerima permanen terlewat. v2 mulai bersih dari deployBlock.
const SCAN_CURSOR_PREFIX = 'xevouzk_scan_block_v2_';
const TRANSFER_ABI = [
    'event EncryptedNote(uint256 indexed recipientCommitment, bytes memo)',
    'function isCommitmentActive(uint256 commitment) view returns (bool)',
];

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/**
 * Error yang TAK ADA gunanya di-retry: salah parameter (range terlalu lebar),
 * method ditolak proxy, atau upstream belum dikonfigurasi. Retry 4× untuk ini
 * cuma menunda lalu melapor "RPC sibuk" yang menyesatkan — lempar segera.
 */
// ethers v6 sering membungkus error RPC jadi "could not coalesce error" dan
// menaruh pesan/kode ASLI di e.error / e.info.error. Karena itu KUMPULKAN semua
// sumber pesan (jangan ambil yang pertama truthy) agar deteksi tak meleset.
function errText(e) {
    return [e?.shortMessage, e?.message, e?.error?.message, e?.info?.error?.message]
        .filter(Boolean).join(' ').toLowerCase();
}
function errCode(e) {
    return e?.error?.code ?? e?.info?.error?.code ?? e?.code;
}

function isNonRetryable(e) {
    const msg = errText(e);
    return errCode(e) === -32602
        || msg.includes('method not allowed')
        || msg.includes('invalid params')
        || msg.includes('not configured');
}

/**
 * Error karena RANGE eth_getLogs terlalu lebar / hasil melebihi cap (bukan
 * gangguan transien). Dipakai probe untuk MEMPERKECIL range, bukan menyerah.
 * Cakupan frasa lintas-provider: official Amoy ("block range exceeds configured
 * limit", code -32000), Alchemy ("Log response size exceeded ... up to a 2K block
 * range ... cap of 10K logs", "query returned more than 10000 results", free tier
 * "up to a 10 block range"), drpc free ("ranges over 10000 blocks are not supported
 * on freetier", code 35). Tanpa ini probe salah mengira limit drpc = gangguan
 * transien → berhenti tanpa mengecilkan range → cursor mandek (scan "0 note" palsu).
 */
function isRangeError(e) {
    const msg = errText(e);
    return errCode(e) === 35                   // drpc free: batas range getLogs
        || msg.includes('block range')
        || msg.includes('response size')
        || msg.includes('more than')
        || msg.includes('too large')
        || msg.includes('exceeds')
        || msg.includes('cap of')
        || msg.includes('limited to')
        || msg.includes('ranges over')         // drpc: "ranges over 10000 blocks ..."
        || msg.includes('not supported on')    // drpc: "... not supported on freetier"
        || msg.includes('freetier');
}

/**
 * queryFilter dengan retry + backoff. RPC bisa rate-limit (429) transien saat
 * banyak range query beruntun; sekali coba lalu menyerah membuat scan melaporkan
 * "0 note" PALSU. Error non-transien (mis. -32602) di-lempar segera (fail-fast).
 */
async function queryWithRetry(contract, from, to, { retries = 4 } = {}) {
    let lastErr;
    for (let attempt = 0; attempt <= retries; attempt++) {
        try {
            return await contract.queryFilter(contract.filters.EncryptedNote(), from, to);
        } catch (e) {
            lastErr = e;
            if (isNonRetryable(e) || isRangeError(e)) throw e; // range/param salah → jangan retry
            if (attempt < retries) await sleep(400 * (attempt + 1)); // 400→800→1200→1600ms
        }
    }
    throw lastErr;
}

/**
 * Scan event EncryptedNote, trial-decrypt dengan encPriv penerima, simpan note yang
 * cocok, dan (best-effort) catat ke riwayat sebagai `private_receive`.
 *
 * Robust terhadap rate-limit RPC:
 *  - retry+backoff per range (queryWithRetry),
 *  - TIDAK meracuni cursor: bila sebuah range tetap gagal, cursor hanya maju
 *    sampai blok terakhir yang sukses kontigu — range yang gagal dipindai ulang
 *    pada scan berikutnya (tidak hilang permanen),
 *  - melaporkan incomplete/failedRanges agar UI tidak menampilkan "0 note" palsu.
 *
 * @param {object} opts {email,password,contractAddress,fromBlock?,deployBlock?,rpcUrl?,csrfToken?}
 * @returns {Promise<{found:number, scannedTo:number, incomplete:boolean, failedRanges:number}>}
 */
export async function scanIncomingNotes({
    email, password, contractAddress, fromBlock, deployBlock, rpcUrl, csrfToken,
}) {
    if (!/^0x[a-fA-F0-9]{40}$/.test(contractAddress)) throw new Error('Contract address tidak valid');
    await initPoseidon();
    const { deriveEncKeypair, eciesDecrypt } = window.NoteCrypto
        ? window.NoteCrypto
        : await import('./note-crypto.js');
    const { encPriv } = await deriveEncKeypair(email, password);
    const { shieldPub } = await deriveShieldKeypair(email, password);

    const provider = new ethers.JsonRpcProvider(
        rpcUrl || SCAN_RPC_URL, { chainId: POLYGON_AMOY_CHAIN_ID, name: 'amoy' },
    );
    const contract = new ethers.Contract(contractAddress, TRANSFER_ABI, provider);

    const cursorKey = SCAN_CURSOR_PREFIX + contractAddress.toLowerCase();
    const latest = await provider.getBlockNumber();
    // Lantai scan = blok deploy kontrak. Tanpa ini scan mulai dari 0 → puluhan
    // juta blok di Amoy → praktis menggantung / kena rate-limit RPC. Cursor
    // tersimpan mempercepat scan berikutnya, tapi tak boleh di bawah deployBlock.
    const floor = Math.max(0, Number(deployBlock) || 0);
    let start = fromBlock != null ? fromBlock : Math.max(floor, Number(localStorage.getItem(cursorKey) || 0));
    if (start < 0) start = 0;

    let found = 0;
    let failedRanges = 0;
    let firstFailedFrom = null; // blok awal range gagal pertama → batas cursor aman

    // Proses satu batch event: trial-decrypt → verifikasi commitment → simpan note.
    const handleEvents = async (events) => {
        for (const ev of events) {
            const memo = ev.args.memo;
            const recipientCommitment = ev.args.recipientCommitment;
            let note;
            try { note = await eciesDecrypt(memo, encPriv); } catch { continue; } // bukan untuk kita
            // Verifikasi commitment cocok: Poseidon(amount, shieldPub, salt)
            const recompute = poseidon.F.toObject(poseidon([BigInt(note.amount_wei), shieldPub, BigInt(note.salt)]));
            if (recompute.toString() !== recipientCommitment.toString()) continue;
            // Pastikan masih aktif (belum di-spend penerima)
            let active = true;
            try { active = await contract.isCommitmentActive(recipientCommitment); } catch {}
            if (!active) continue;
            await saveNoteRecord({
                commitment: recipientCommitment.toString(), salt: note.salt,
                amount_wei: note.amount_wei, owner_shield_pub: shieldPub.toString(),
                source: 'received', src_tx: ev.transactionHash,
            }, email, password);
            found++;
            // Catat ke riwayat sebagai terima PRIVAT (best-effort). PRIVASI (gap §3.I/M1):
            // TIDAK mengirim tx_hash ke server (mencegah link penerima↔pengirim di DB).
            // Kirim receipt_ref opaque = sha256(commitment‖salt); salt rahasia (hanya di
            // memo ECIES, tak pernah on-chain) → server tak bisa rekomputasi/menautkan.
            // Deterministik per note → idempoten walau scan diulang.
            if (csrfToken) {
                const receiptRef = ethers.sha256(ethers.toUtf8Bytes(
                    `xevou-receive-v1:${recipientCommitment.toString()}:${note.salt}`,
                )).slice(2); // buang prefix 0x → 64 hex
                await recordEvent({ type: 'private_receive', receiptRef, csrfToken });
            }
        }
    };

    // PROBE range. RPC ber-API-key (Alchemy) membatasi getLogs pada JUMLAH HASIL,
    // bukan lebar blok → kontrak ber-event jarang seperti ini bisa dipindai seluruh
    // riwayatnya dalam 1 kueri, bukan ratusan kueri 10k (penyebab scan makan 10+
    // menit). Coba range BESAR→KECIL; pakai yang PERTAMA diterima sebagai STEP untuk
    // sisa scan. Range error → kecilkan; gagal transien → tandai range gagal. Tak
    // pernah lebih buruk dari STEP 10k lama (provider berbatas tetap turun ke 10k/100).
    const fullSpan = latest - start + 1;
    const candidates = [...new Set([fullSpan, 1000000, 100000, SCAN_MAX_RANGE, 2000, 500, 100])]
        .filter((c) => c >= 1 && c <= fullSpan)
        .sort((a, b) => b - a);
    if (candidates.length === 0) candidates.push(Math.max(1, fullSpan));

    let from = start;
    let STEP = SCAN_MAX_RANGE;
    for (const cand of candidates) {
        const to = Math.min(start + cand - 1, latest);
        try {
            await handleEvents(await queryWithRetry(contract, start, to));
            STEP = cand;          // range diterima → pakai utk sisa scan
            from = to + 1;        // [start, to] sudah dipindai oleh probe
            break;
        } catch (e) {
            if (isRangeError(e)) continue;   // range kebesaran → coba lebih kecil
            // Gagal transien pada blok awal → tandai gagal; resume di scan berikutnya.
            console.warn('probe queryFilter gagal', start, to, e.message);
            failedRanges++; firstFailedFrom = start;
            from = latest + 1;               // hentikan; jangan majukan cursor
            break;
        }
    }

    // Scan sisa dengan STEP yang sudah terbukti diterima provider.
    for (; from <= latest; from += STEP) {
        const to = Math.min(from + STEP - 1, latest);
        try {
            await handleEvents(await queryWithRetry(contract, from, to));
        } catch (e) {
            // Gagal walau sudah retry → catat, JANGAN majukan cursor melewati gap.
            console.warn('queryFilter gagal (setelah retry)', from, to, e.message);
            failedRanges++;
            if (firstFailedFrom === null) firstFailedFrom = from;
        }
    }

    // Cursor hanya maju sampai blok terakhir yang BENAR-BENAR sukses kontigu.
    const scannedTo = firstFailedFrom !== null ? firstFailedFrom - 1 : latest;
    localStorage.setItem(cursorKey, String(Math.max(start - 1, scannedTo)));
    return { found, scannedTo, incomplete: firstFailedFrom !== null, failedRanges };
}

if (typeof window !== 'undefined') {
    window.PolygonTransfer = { transferFromPool, scanIncomingNotes };
    window.dispatchEvent(new Event('polygon-transfer-ready'));
}
