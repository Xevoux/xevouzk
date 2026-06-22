// note-store.js — penyimpanan note terenkripsi (localStorage). Helper bersama
// untuk withdraw & pool-balance. Diekstrak dari polygon-withdraw.js (perilaku
// identik); ditambah opsi includeUsed untuk perhitungan saldo pool.
import { ethers } from 'ethers';

export const NOTE_PREFIX = 'xevouzk_note_v1_';
export const NOTE_USED_PREFIX = 'xevouzk_used_v1_';

export async function deriveNoteEncryptionKey(email, password) {
    const enc = new TextEncoder();
    const baseKey = await crypto.subtle.importKey(
        'raw', enc.encode(password), 'PBKDF2', false, ['deriveKey']
    );
    return crypto.subtle.deriveKey(
        {
            name: 'PBKDF2',
            salt: enc.encode(`xevouzk-note-v1:${email.toLowerCase()}`),
            iterations: 100000,
            hash: 'SHA-256',
        },
        baseKey,
        { name: 'AES-GCM', length: 256 },
        false,
        ['encrypt', 'decrypt']
    );
}

export async function decryptNote(ciphertextB64, key) {
    const combined = Uint8Array.from(atob(ciphertextB64), c => c.charCodeAt(0));
    const iv = combined.slice(0, 12);
    const ct = combined.slice(12);
    const pt = await crypto.subtle.decrypt({ name: 'AES-GCM', iv }, key, ct);
    return JSON.parse(new TextDecoder().decode(pt));
}

/**
 * List note key di localStorage. Default mengecualikan note yang sudah ditandai
 * used secara lokal. Set includeUsed:true untuk perhitungan saldo pool (sumber
 * kebenaran spendable adalah on-chain isCommitmentActive, bukan flag lokal).
 */
export function listNoteKeys({ includeUsed = false } = {}) {
    const keys = [];
    for (let i = 0; i < localStorage.length; i++) {
        const k = localStorage.key(i);
        if (k && k.startsWith(NOTE_PREFIX)) {
            if (includeUsed) { keys.push(k); continue; }
            const usedKey = NOTE_USED_PREFIX + k.slice(NOTE_PREFIX.length);
            if (!localStorage.getItem(usedKey)) keys.push(k);
        }
    }
    return keys;
}

/**
 * Decrypt + return list notes. Note yang gagal didekripsi (password salah) di-skip.
 */
export async function listNotes(email, password, { includeUsed = false } = {}) {
    const key = await deriveNoteEncryptionKey(email, password);
    const noteKeys = listNoteKeys({ includeUsed });
    const notes = [];
    for (const storageKey of noteKeys) {
        try {
            const ciphertext = localStorage.getItem(storageKey);
            const note = await decryptNote(ciphertext, key);
            notes.push({
                storage_key: storageKey,
                commitment: note.commitment,
                salt: note.salt,
                amount_wei: note.amount_wei,
                amount_matic: ethers.formatEther(note.amount_wei),
                owner_shield_pub: note.owner_shield_pub ?? null,
                source: note.source ?? 'deposit',
                deposit_tx: note.deposit_tx,
                created_at: note.created_at,
            });
        } catch (e) {
            console.warn(`Skip note ${storageKey} (decrypt failed — wrong password?)`, e.message);
        }
    }
    return notes;
}

async function deriveAesKey(email, password) {
    return deriveNoteEncryptionKey(email, password);
}
async function encryptNoteRecord(note, key) {
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const ct = await crypto.subtle.encrypt(
        { name: 'AES-GCM', iv }, key, new TextEncoder().encode(JSON.stringify(note))
    );
    const combined = new Uint8Array(iv.length + ct.byteLength);
    combined.set(iv, 0); combined.set(new Uint8Array(ct), iv.length);
    return btoa(String.fromCharCode(...combined));
}

/**
 * Simpan note (source 'change' | 'received') ke localStorage. Idempoten by commitment.
 * @param {object} rec - {commitment, salt, amount_wei, owner_shield_pub, source, src_tx}
 */
export async function saveNoteRecord(rec, email, password) {
    const storageKey = `${NOTE_PREFIX}${BigInt(rec.commitment).toString(16).padStart(64, '0').slice(0, 16)}`;
    if (localStorage.getItem(storageKey)) return storageKey; // idempoten
    const key = await deriveAesKey(email, password);
    const note = {
        commitment: String(rec.commitment),
        salt: String(rec.salt),
        amount_wei: String(rec.amount_wei),
        owner_shield_pub: rec.owner_shield_pub != null ? String(rec.owner_shield_pub) : null,
        source: rec.source || 'received',
        deposit_tx: rec.src_tx || null,
        created_at: new Date().toISOString(),
    };
    localStorage.setItem(storageKey, await encryptNoteRecord(note, key));
    // Backup terenkripsi best-effort (lintas device). Hook opsional → tanpa import
    // (hindari circular dep note-store↔note-backup). ciphertext sama dgn localStorage.
    try {
        const ciphertext = localStorage.getItem(storageKey);
        if (window.NoteBackup && ciphertext) {
            window.NoteBackup.pushBackup(rec.commitment, rec.salt, ciphertext); // fire-and-forget
        }
    } catch (e) { console.warn('pushBackup hook gagal:', e.message); }
    return storageKey;
}
