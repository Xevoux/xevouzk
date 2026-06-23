// private-send.js — orkestrasi transfer privat (pool): parse kode penerima,
// pilih note pool terkecil yang cukup, lalu transferFromPool. Satu sumber kebenaran
// dipakai oleh alur Scan QR (qr-scanner.js) DAN Transfer Manual (form privat).
//
// Tidak ada secret yang keluar dari browser. Verifikasi yang mengikat tetap on-chain.
import { parseUri } from './xevou-uri.js';
import { unpackZkpub } from './shield-key.js';
import { listNotes } from './note-store.js';
import { recordEvent } from './record-event.js';

/**
 * Ubah kode penerima → {shieldPub, encPub}. Menerima:
 *   - URI privat:  xevouzk:private-transfer?zkpub=<b64url>[&amount=..]
 *   - zkpub mentah: <b64url 64 byte> (tanpa prefix)
 * Menolak kode plain (alamat publik) dan kode tak valid dengan pesan jelas.
 *
 * @param {string} text
 * @returns {{shieldPub: bigint, encPub: Uint8Array, amount?: string}}
 */
export function parseRecipientCode(text) {
    const raw = (text || '').trim();
    if (!raw) throw new Error('Kode penerima kosong.');

    let zkpub = null;
    let amount;
    const parsed = parseUri(raw);
    if (parsed) {
        if (parsed.mode === 'plain') {
            throw new Error('Kode itu QR Plain (alamat publik 0x), bukan viewing key. Untuk transfer privat minta QR Privat penerima.');
        }
        zkpub = parsed.zkpub;
        amount = parsed.amount;
    } else {
        // Bukan URI xevouzk: → anggap zkpub mentah (base64url). unpackZkpub akan
        // memvalidasi panjang 64 byte (shieldPub‖encPub) dan melempar bila salah.
        zkpub = raw;
    }

    const { shieldPub, encPub } = unpackZkpub(zkpub);
    return { shieldPub, encPub, amount };
}

/**
 * Pilih note pool terkecil yang ≥ jumlah lalu transferFromPool. Memakai
 * window.PolygonTransfer (di-set oleh polygon-transfer.js).
 *
 * @param {object} opts
 * @param {string} opts.email
 * @param {string} opts.password
 * @param {bigint|string} opts.recipientShieldPub
 * @param {Uint8Array|string} opts.recipientEncPub
 * @param {string|number} opts.amountMatic
 * @param {string} opts.contractAddress
 * @param {string} opts.csrfToken
 * @param {function} [opts.log] - logger opsional (msg, type)
 * @returns {Promise<{success:boolean, tx_hash?:string, error?:string, note_matic?:string}>}
 */
export async function sendPrivate({
    email, password, recipientShieldPub, recipientEncPub,
    amountMatic, contractAddress, csrfToken, log,
}) {
    const say = (m, t) => { if (typeof log === 'function') log(m, t); };

    if (!window.PolygonTransfer) throw new Error('Modul transfer belum siap (coba hard-refresh).');
    if (!/^0x[a-fA-F0-9]{40}$/.test(contractAddress || '')) {
        throw new Error('POLYGON_CONTRACT_ADDRESS belum di-set di .env.');
    }
    const amount = parseFloat(amountMatic);
    if (!(amount > 0)) throw new Error('Jumlah transfer harus lebih dari 0.');

    // Pool berbasis note: satu transfer membelanjakan SATU note ≥ jumlah; sisanya
    // jadi note kembalian. Pilih note tunggal terkecil yang cukup (hemat fragmentasi).
    say('Dekripsi note pool…', 'warning');
    const notes = await listNotes(email, password, { includeUsed: false });
    const sufficient = notes
        .filter((n) => parseFloat(n.amount_matic) >= amount)
        .sort((a, b) => parseFloat(a.amount_matic) - parseFloat(b.amount_matic));
    if (sufficient.length === 0) {
        throw new Error(`Tidak ada note pool tunggal ≥ ${amount} MATIC. Deposit lebih besar dulu (transfer tidak bisa menggabung beberapa note).`);
    }
    const chosen = sufficient[0];
    say(`Note terpilih: ${chosen.amount_matic} MATIC`, 'success');

    say('Generate Groth16 proof + privateTransfer (5–30 dtk)…', 'warning');
    const r = await window.PolygonTransfer.transferFromPool({
        email, password, storageKey: chosen.storage_key,
        recipientShieldPub, recipientEncPub,
        transferAmountMatic: String(amount), contractAddress, csrfToken,
    });

    // PRIVASI: server hanya menerima receipt_ref opaque (turunan senderSalt rahasia),
    // BUKAN tx_hash → baris DB tak bisa di-JOIN ke chain. Nominal & penerima juga tak
    // dikirim. Link explorer disimpan LOKAL di browser pengirim saja. Best-effort.
    if (r && r.success && r.tx_hash) {
        // Link explorer LOKAL untuk pengirim. Key = send_ref (== transaction_hash baris
        // DB) agar halaman Riwayat bisa mencocokkan & menampilkan link 'view' tanpa server
        // pernah tahu tx_hash. send_ref opaque (turunan salt rahasia) → aman di DOM sendiri.
        try {
            if (r.send_ref) {
                localStorage.setItem(`xevouzk_sent_v1_${r.send_ref}`, JSON.stringify({ tx_hash: r.tx_hash, ts: Date.now() }));
            }
        } catch (e) { /* localStorage opsional */ }
        await recordEvent({ type: 'private_transfer', receiptRef: r.send_ref, csrfToken });
    }

    return { ...r, note_matic: chosen.amount_matic };
}

if (typeof window !== 'undefined') {
    window.PrivateSend = { parseRecipientCode, sendPrivate };
    window.dispatchEvent(new Event('private-send-ready'));
}
