// resources/js/note-backup.js
// Backup note terenkripsi ke server (lintas device + anti-hilang). Server hanya
// menerima ciphertext (AES-GCM, identik localStorage) + ref opaque — tak pernah
// password/salt/nominal/commitment mentah.
import { ethers } from 'ethers';
import { listNotes, decryptNote, deriveNoteEncryptionKey, NOTE_PREFIX } from './note-store.js';

const PENDING_KEY = 'xevouzk_backup_pending_v1';
const meta = (n) => document.querySelector(`meta[name="${n}"]`)?.content || '';

/** ref opaque = sha256("xevou-note-backup-v1:"+commitment+":"+salt) → 64 hex. */
export function computeRef(commitment, salt) {
    return ethers.sha256(ethers.toUtf8Bytes(
        `xevou-note-backup-v1:${String(commitment)}:${String(salt)}`,
    )).slice(2);
}

function loadPending() {
    try { return JSON.parse(localStorage.getItem(PENDING_KEY) || '[]'); } catch { return []; }
}
function savePending(list) { localStorage.setItem(PENDING_KEY, JSON.stringify(list)); }
function emitPending() {
    window.dispatchEvent(new CustomEvent('backup-pending', { detail: { pending: loadPending().length > 0 } }));
}
function enqueue(item) {
    const list = loadPending();
    if (!list.some((x) => x.ref === item.ref)) { list.push(item); savePending(list); }
    emitPending();
}

async function postBatch(notes) {
    const res = await fetch('/notes/backup', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json', 'Accept': 'application/json',
            'X-CSRF-TOKEN': meta('csrf-token'), 'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ notes }),
    });
    if (!res.ok) throw new Error(`backup HTTP ${res.status}`);
    return res.json();
}

/** Push satu blob; gagal → antri. Best-effort: TIDAK pernah throw ke pemanggil. */
export async function pushBackup(commitment, salt, ciphertext) {
    const item = { ref: computeRef(commitment, salt), ciphertext };
    try { await postBatch([item]); }
    catch (e) { enqueue(item); console.warn('pushBackup antri:', e.message); }
}

/** Kirim ulang antrian (tak butuh password). Panggil saat dashboard load. */
export async function flushPending() {
    const list = loadPending();
    if (list.length === 0) return;
    try { await postBatch(list); savePending([]); emitPending(); }
    catch (e) { console.warn('flushPending gagal (coba lagi nanti):', e.message); }
}

/**
 * Sinkron dua-arah (butuh password). Satu GET lalu:
 *  - PULL: dekripsi blob server → merge ke localStorage (hanya bila belum ada);
 *  - SWEEP: push note lokal yang ref-nya belum ada di server (termasuk note lama).
 * Idempoten; best-effort (tak throw).
 */
export async function syncOnLogin(email, password) {
    const emailLc = String(email).toLowerCase();
    let serverNotes = [];
    try {
        const res = await fetch('/notes/backup', {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!res.ok) { console.warn('syncOnLogin GET non-ok:', res.status); return; }
        serverNotes = (await res.json()).notes || [];
    } catch (e) { console.warn('syncOnLogin GET gagal:', e.message); return; }

    // PULL
    const key = await deriveNoteEncryptionKey(emailLc, password);
    for (const blob of serverNotes) {
        try {
            const note = await decryptNote(blob.ciphertext, key);
            const sk = `${NOTE_PREFIX}${BigInt(note.commitment).toString(16).padStart(64, '0').slice(0, 16)}`;
            if (!localStorage.getItem(sk)) localStorage.setItem(sk, blob.ciphertext);
        } catch { /* bukan untuk kunci ini / korup → skip */ }
    }

    // SWEEP
    const serverRefs = new Set(serverNotes.map((n) => n.ref));
    const localNotes = await listNotes(emailLc, password, { includeUsed: true });
    const toPush = [];
    for (const n of localNotes) {
        if (n.salt == null) continue;
        const ref = computeRef(n.commitment, n.salt);
        if (!serverRefs.has(ref)) {
            const ciphertext = localStorage.getItem(n.storage_key);
            if (ciphertext) toPush.push({ ref, ciphertext });
        }
    }
    if (toPush.length) {
        try { await postBatch(toPush); }
        catch (e) { toPush.forEach(enqueue); console.warn('sweep antri:', e.message); }
    }
}

if (typeof window !== 'undefined') {
    window.NoteBackup = { computeRef, pushBackup, flushPending, syncOnLogin };
    window.dispatchEvent(new Event('note-backup-ready'));
}
