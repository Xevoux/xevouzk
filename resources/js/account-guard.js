// resources/js/account-guard.js
// Verifikasi password yang dimasukkan pasca-login benar milik akun yang login,
// TANPA mengirim password ke server. Semua kunci (Polygon/Schnorr/shield/enc/note)
// turun deterministik dari (email,password) — password salah TIDAK gagal, ia hanya
// menurunkan identitas lain. Maka cocokkan Schnorr pubkey turunan vs zk_public_key
// akun (nilai publik) yang ditanam di <meta name="account-schnorr-pub">.
import { derivePrivateKey, derivePublicKey } from './schnorr-auth.js';

const meta = (n) => document.querySelector(`meta[name="${n}"]`)?.content || '';

/** @returns {boolean} true jika (email,password) menurunkan pubkey == pubkey akun. */
export function verify(email, password) {
    const expected = meta('account-schnorr-pub').toLowerCase();
    if (!expected) return true; // tanpa anchor (halaman tanpa meta) → jangan blokir
    try {
        const pub = derivePublicKey(derivePrivateKey(String(email).toLowerCase(), password));
        return pub.toLowerCase() === expected;
    } catch {
        return false;
    }
}

/** Verifikasi pakai email dari <meta name="user-email">. Salah → alert + false. */
export function assertPassword(password) {
    if (verify(meta('user-email'), password)) return true;
    alert('Password salah untuk akun ini. Operasi dibatalkan.');
    return false;
}

if (typeof window !== 'undefined') {
    window.AccountGuard = { verify, assertPassword };
    window.dispatchEvent(new Event('account-guard-ready'));
}
