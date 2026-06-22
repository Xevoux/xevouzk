// Non-custodial Polygon wallet key derivation di browser.
//
// Derive secp256k1 keypair + EIP-55 address dari (email, password) secara
// deterministik. Label "polygon_v1" memberi domain separation dari kunci
// Schnorr (lihat schnorr-auth.js yang pakai label "schnorr_v1").
//
// Load di Blade dengan
// <script type="module" src="{{ asset('js/polygon-key.js') }}"></script>
// Setelah load, tersedia sebagai window.PolygonKey + event 'polygon-key-ready'.

import { secp256k1 } from '@noble/curves/secp256k1';
import { sha256 } from '@noble/hashes/sha256';
import { keccak_256 } from '@noble/hashes/sha3';
import { bytesToHex, hexToBytes, utf8ToBytes } from '@noble/hashes/utils';

const N = secp256k1.CURVE.n;

function reduceToScalar(bytes32) {
    let bn = 0n;
    for (const b of bytes32) bn = (bn << 8n) | BigInt(b);
    bn = bn % N;
    if (bn === 0n) bn = 1n;
    return bn;
}

function bnToHex64(bn) {
    return bn.toString(16).padStart(64, '0');
}

export function derivePrivateKey(email, password) {
    const seedBytes = sha256(utf8ToBytes(`polygon_v1:${email.toLowerCase()}:${password}`));
    return bnToHex64(reduceToScalar(seedBytes));
}

/** Return uncompressed public key: "04" + x(64) + y(64) = 130 hex chars. */
export function derivePublicKey(privateKeyHex) {
    const privBytes = hexToBytes(privateKeyHex.padStart(64, '0'));
    const pubBytes = secp256k1.getPublicKey(privBytes, false);
    return bytesToHex(pubBytes);
}

/** EIP-55 checksum encode lowercase hex address (40 chars, tanpa 0x). */
function toChecksumAddress(addrLower) {
    const hashBytes = keccak_256(utf8ToBytes(addrLower));
    const hashHex = bytesToHex(hashBytes);
    let out = '0x';
    for (let i = 0; i < 40; i++) {
        const c = addrLower[i];
        const nibble = parseInt(hashHex[i], 16);
        out += nibble >= 8 ? c.toUpperCase() : c;
    }
    return out;
}

/** Derive Polygon address dari public key (uncompressed "04..."). */
export function deriveAddress(publicKeyHex) {
    if (publicKeyHex.length !== 130 || !publicKeyHex.startsWith('04')) {
        throw new Error('Public key harus uncompressed format (04 + x + y, 130 hex chars)');
    }
    const xy = publicKeyHex.slice(2);
    const hashBytes = keccak_256(hexToBytes(xy));
    const addrLower = bytesToHex(hashBytes).slice(-40);
    return toChecksumAddress(addrLower);
}

/** Helper: derive lengkap dari (email, password). */
export function deriveWallet(email, password) {
    const privateKey = derivePrivateKey(email, password);
    const publicKey = derivePublicKey(privateKey);
    const address = deriveAddress(publicKey);
    return { privateKey, publicKey, address };
}

if (typeof window !== 'undefined') {
    window.PolygonKey = {
        derivePrivateKey,
        derivePublicKey,
        deriveAddress,
        deriveWallet,
    };
    window.dispatchEvent(new Event('polygon-key-ready'));
}
