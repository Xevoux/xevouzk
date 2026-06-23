// zk-verify.js — verifikasi Groth16 di sisi klien (tanpa round-trip ke server).
// Menggantikan POST /payment/{transfer,withdraw}/verify yang dulu mengikat
// akun↔commitment/nullifier di server. Binding yang mengikat tetap on-chain;
// ini hanya pra-cek hemat gas. vkey statis sama dengan yang dipakai verifier kontrak.
import * as snarkjs from 'snarkjs';

const vKeyCache = {};

/** Muat verification_key.json untuk sebuah circuit (cache per-circuit). */
export async function loadVerificationKey(circuit) {
    if (!vKeyCache[circuit]) {
        const res = await fetch(`/zk/${circuit}/verification_key.json`);
        if (!res.ok) throw new Error(`Gagal memuat vkey ${circuit}: HTTP ${res.status}`);
        vKeyCache[circuit] = await res.json();
    }
    return vKeyCache[circuit];
}

/** Verifikasi proof Groth16 lokal. Return true/false. Melempar bila vkey gagal dimuat. */
export async function verifyProofLocal(circuit, proof, publicSignals) {
    const vKey = await loadVerificationKey(circuit);
    return snarkjs.groth16.verify(vKey, publicSignals, proof);
}

if (typeof window !== 'undefined') {
    window.ZkVerify = { verifyProofLocal, loadVerificationKey };
    window.dispatchEvent(new Event('zk-verify-ready'));
}
