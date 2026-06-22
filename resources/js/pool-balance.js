// pool-balance.js — hitung saldo pool (privat) dari note deposit, SEPENUHNYA di
// browser. Tidak ada amount/secret yang dikirim ke server. Untuk tiap note,
// status spendable ditentukan on-chain via isCommitmentActive (bukan flag lokal).
import { ethers } from 'ethers';
import { listNotes } from './note-store.js';

const POLYGON_AMOY_CHAIN_ID = 80002;
const POLYGON_RPC_URL = 'https://rpc-amoy.polygon.technology/';
const ABI = ['function isCommitmentActive(uint256 commitment) view returns (bool)'];

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/**
 * isCommitmentActive dengan retry+backoff. RPC publik Amoy kadang rate-limit
 * (429) transien; tanpa retry, satu kegagalan membuat note dikeluarkan dari
 * total → saldo pool "berkedip" turun-naik tiap re-tally 15 dtk. Retry membuat
 * hambatan transien tertangani sehingga angka saldo stabil.
 */
async function isActiveWithRetry(contract, commitment, { retries = 3 } = {}) {
    let lastErr;
    for (let attempt = 0; attempt <= retries; attempt++) {
        try {
            return await contract.isCommitmentActive(commitment);
        } catch (e) {
            lastErr = e;
            if (attempt < retries) await sleep(350 * (attempt + 1)); // 350→700→1050ms
        }
    }
    throw lastErr;
}

/**
 * Tally saldo dari list note yang SUDAH didekripsi (tidak butuh password). Untuk
 * tiap note, status spendable ditentukan on-chain via isCommitmentActive. Dipakai
 * computePoolBalance dan juga refresh berkala (live) di dashboard tanpa prompt ulang.
 *
 * @param {{commitment:string,amount_wei:string}[]} notes
 * @returns {Promise<{total_wei:bigint,total_matic:string,active_count:number,
 * total_count:number,rpc_failed:boolean,notes:object[]}>}
 */
export async function tallyActiveNotes({ notes, contractAddress }) {
    if (!/^0x[a-fA-F0-9]{40}$/.test(contractAddress)) {
        throw new Error('contractAddress tidak valid');
    }
    const total_count = notes.length;
    if (total_count === 0) {
        return { total_wei: 0n, total_matic: '0.0', active_count: 0, total_count: 0, rpc_failed: false, notes: [] };
    }

    const provider = new ethers.JsonRpcProvider(POLYGON_RPC_URL, {
        chainId: POLYGON_AMOY_CHAIN_ID, name: 'amoy',
    });
    const contract = new ethers.Contract(contractAddress, ABI, provider);

    let total_wei = 0n;
    let active_count = 0;
    let rpc_failed = false;
    for (const note of notes) {
        try {
            const active = await isActiveWithRetry(contract, BigInt(note.commitment));
            if (active) {
                total_wei += BigInt(note.amount_wei);
                active_count++;
            }
        } catch (e) {
            // RPC gagal (setelah retry) untuk note ini → tandai tak terverifikasi,
            // jangan klaim final.
            rpc_failed = true;
            console.warn('isCommitmentActive gagal:', note.commitment, e.message);
        }
    }

    return {
        total_wei,
        total_matic: ethers.formatEther(total_wei),
        active_count,
        total_count,
        rpc_failed,
        // Hanya field yang dibutuhkan untuk re-tally berkala — tanpa salt/secret.
        notes: notes.map((n) => ({ commitment: String(n.commitment), amount_wei: String(n.amount_wei) })),
    };
}

/**
 * Dekripsi note (butuh password) lalu tally. Return juga `notes` agar caller bisa
 * cache & re-tally live via tallyActiveNotes tanpa prompt password lagi.
 *
 * @returns {Promise<{total_wei:bigint,total_matic:string,active_count:number,
 * total_count:number,rpc_failed:boolean,notes:object[]}>}
 */
export async function computePoolBalance({ email, password, contractAddress }) {
    // includeUsed:true → biar on-chain yang memutuskan, bukan flag used lokal.
    const notes = await listNotes(email, password, { includeUsed: true });
    return tallyActiveNotes({ notes, contractAddress });
}

if (typeof window !== 'undefined') {
    window.PoolBalance = { computePoolBalance, tallyActiveNotes };
    window.dispatchEvent(new Event('pool-balance-ready'));
}
