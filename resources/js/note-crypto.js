// note-crypto.js — kunci enkripsi x25519 turunan (email,password) + ECIES memo.
// Domain-separated dari shield_v1/polygon_v1/schnorr_v1 dengan label xevou_enc_v1.
// encPriv tak pernah keluar perangkat. encPub boleh publik (bagian dari zkpub).
import { x25519 } from '@noble/curves/ed25519';
import { sha256 } from '@noble/hashes/sha256';
import { utf8ToBytes } from '@noble/hashes/utils';

// crypto.subtle: browser → window.crypto; node ≥18 → globalThis.crypto.
const subtle = (globalThis.crypto && globalThis.crypto.subtle) || null;

function bytesToHex(bytes) {
    return '0x' + Array.from(bytes).map(b => b.toString(16).padStart(2, '0')).join('');
}
function hexToBytes(hex) {
    const h = hex.startsWith('0x') ? hex.slice(2) : hex;
    const out = new Uint8Array(h.length / 2);
    for (let i = 0; i < out.length; i++) out[i] = parseInt(h.slice(i * 2, i * 2 + 2), 16);
    return out;
}

/** @returns {Promise<{encPriv: Uint8Array, encPub: Uint8Array}>} */
export async function deriveEncKeypair(email, password) {
    const encPriv = sha256(utf8ToBytes(`xevou_enc_v1:${email.toLowerCase()}:${password}`));
    const encPub = x25519.getPublicKey(encPriv);
    return { encPriv, encPub };
}

async function aesKeyFromShared(shared) {
    const raw = sha256(shared);
    return subtle.importKey('raw', raw, 'AES-GCM', false, ['encrypt', 'decrypt']);
}

/**
 * ECIES encrypt note JSON ke recipientEncPub. memo = ephPub(32)‖iv(12)‖ciphertext, hex.
 * @param {object} note - {amount_wei, salt, commitment}
 * @param {Uint8Array|string} recipientEncPub - 32B (Uint8Array atau hex)
 * @returns {Promise<string>} 0x-hex memo
 */
export async function eciesEncrypt(note, recipientEncPub) {
    const pub = typeof recipientEncPub === 'string' ? hexToBytes(recipientEncPub) : recipientEncPub;
    const eph = x25519.utils.randomPrivateKey();
    const ephPub = x25519.getPublicKey(eph);
    const shared = x25519.getSharedSecret(eph, pub);
    const key = await aesKeyFromShared(shared);
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const ct = new Uint8Array(await subtle.encrypt(
        { name: 'AES-GCM', iv }, key, new TextEncoder().encode(JSON.stringify(note))
    ));
    const memo = new Uint8Array(32 + 12 + ct.length);
    memo.set(ephPub, 0); memo.set(iv, 32); memo.set(ct, 44);
    return bytesToHex(memo);
}

/**
 * ECIES decrypt memo dengan encPriv. Throw kalau bukan untuk kita (auth tag gagal).
 * @returns {Promise<object>} note JSON
 */
export async function eciesDecrypt(memoHex, encPriv) {
    const memo = typeof memoHex === 'string' ? hexToBytes(memoHex) : memoHex;
    const ephPub = memo.slice(0, 32);
    const iv = memo.slice(32, 44);
    const ct = memo.slice(44);
    const shared = x25519.getSharedSecret(encPriv, ephPub);
    const key = await aesKeyFromShared(shared);
    const pt = await subtle.decrypt({ name: 'AES-GCM', iv }, key, ct);
    return JSON.parse(new TextDecoder().decode(pt));
}

export { bytesToHex, hexToBytes };

if (typeof window !== 'undefined') {
    window.NoteCrypto = { deriveEncKeypair, eciesEncrypt, eciesDecrypt, bytesToHex, hexToBytes };
    window.dispatchEvent(new Event('note-crypto-ready'));
}
