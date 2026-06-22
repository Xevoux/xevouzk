// Schnorr signature client — secp256k1 + Fiat-Shamir.
// Wajib hasilkan output byte-identik dengan app/Services/SchnorrService.php.
//
// Load di Blade dengan: <script type="module" src="{{ asset('js/schnorr-auth.js') }}"></script>
// Setelah modul ter-load, akan tersedia sebagai window.Schnorr.

import { secp256k1 } from '@noble/curves/secp256k1';
import { sha256 } from '@noble/hashes/sha256';
import { bytesToHex, hexToBytes, utf8ToBytes, concatBytes } from '@noble/hashes/utils';

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
    const seedBytes = sha256(utf8ToBytes(`schnorr_v1:${email.toLowerCase()}:${password}`));
    return bnToHex64(reduceToScalar(seedBytes));
}

export function derivePublicKey(privateKeyHex) {
    const privBytes = hexToBytes(privateKeyHex.padStart(64, '0'));
    const pubBytes = secp256k1.getPublicKey(privBytes, true);
    return bytesToHex(pubBytes);
}

export function sign(privateKeyHex, message) {
    const privBn = BigInt('0x' + privateKeyHex);
    const privBytes = hexToBytes(privateKeyHex.padStart(64, '0'));
    const msgBytes = utf8ToBytes(message);

    const kBn = reduceToScalar(sha256(concatBytes(privBytes, msgBytes)));
    const R = secp256k1.ProjectivePoint.BASE.multiply(kBn);
    const rBytes = R.toRawBytes(true);
    const rHex = bytesToHex(rBytes);

    const pubHex = derivePublicKey(privateKeyHex);
    const pubBytes = hexToBytes(pubHex);

    const eBn = reduceToScalar(sha256(concatBytes(rBytes, pubBytes, msgBytes)));

    const sBn = (kBn + eBn * privBn) % N;
    return rHex + bnToHex64(sBn);
}

if (typeof window !== 'undefined') {
    window.Schnorr = { derivePrivateKey, derivePublicKey, sign };
    window.dispatchEvent(new Event('schnorr-ready'));
}
