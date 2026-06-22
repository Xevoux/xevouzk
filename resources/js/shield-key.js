// shield-key.js — derive shielded keypair (BN254/Poseidon) dari (email, password).
// shieldPriv = reduceField(sha256("shield_v1:email:password")); shieldPub = Poseidon(shieldPriv).
// Domain-separated dari polygon_v1 (polygon-key.js) & schnorr_v1 (schnorr-auth.js).
import { buildPoseidon } from 'circomlibjs';
import { sha256 } from '@noble/hashes/sha256';
import { utf8ToBytes } from '@noble/hashes/utils';

const FIELD = 21888242871839275222246405745257275088548364400416034343698204186575808495617n;

let poseidon = null;
async function initPoseidon() {
    if (!poseidon) poseidon = await buildPoseidon();
    return poseidon;
}

function reduceField(bytes32) {
    let bn = 0n;
    for (const b of bytes32) bn = (bn << 8n) | BigInt(b);
    bn = bn % FIELD;
    if (bn === 0n) bn = 1n;
    return bn;
}

/** @returns {Promise<{shieldPriv: bigint, shieldPub: bigint}>} */
export async function deriveShieldKeypair(email, password) {
    await initPoseidon();
    const seed = sha256(utf8ToBytes(`shield_v1:${email.toLowerCase()}:${password}`));
    const shieldPriv = reduceField(seed);
    const shieldPub = poseidon.F.toObject(poseidon([shieldPriv]));
    return { shieldPriv, shieldPub };
}

// zkpub majemuk = base64url( shieldPub(32B BE) ‖ encPub(32B x25519) ).
// shieldPub untuk bentuk recipientCommitment; encPub untuk ECIES delivery.
function fieldToBytes32(bn) {
    const out = new Uint8Array(32);
    let v = BigInt(bn);
    for (let i = 31; i >= 0; i--) { out[i] = Number(v & 0xffn); v >>= 8n; }
    return out;
}
function bytes32ToField(bytes) {
    let v = 0n;
    for (const b of bytes) v = (v << 8n) | BigInt(b);
    return v;
}
function b64urlEncode(bytes) {
    let bin = ''; for (const b of bytes) bin += String.fromCharCode(b);
    return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}
function b64urlDecode(str) {
    const s = str.replace(/-/g, '+').replace(/_/g, '/');
    const bin = atob(s); const out = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
    return out;
}

/** @param {bigint} shieldPub @param {Uint8Array} encPub @returns {string} zkpub */
export function packZkpub(shieldPub, encPub) {
    const buf = new Uint8Array(64);
    buf.set(fieldToBytes32(shieldPub), 0);
    buf.set(encPub, 32);
    return b64urlEncode(buf);
}

/** @param {string} zkpub @returns {{shieldPub: bigint, encPub: Uint8Array}} */
export function unpackZkpub(zkpub) {
    const buf = b64urlDecode(zkpub);
    if (buf.length !== 64) throw new Error('zkpub harus 64 byte (shieldPub‖encPub)');
    return { shieldPub: bytes32ToField(buf.slice(0, 32)), encPub: buf.slice(32, 64) };
}

if (typeof window !== 'undefined') {
    window.ShieldKey = { deriveShieldKeypair, packZkpub, unpackZkpub };
    window.dispatchEvent(new Event('shield-key-ready'));
}
