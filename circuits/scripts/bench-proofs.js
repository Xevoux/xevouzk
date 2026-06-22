/**
 * Bench Proofs — ukur WAKTU pembangkitan proof Groth16 per circuit, N kali,
 * lalu cetak statistik (mean / median / p95 / min / max / stdev) + jumlah
 * constraint per circuit. Untuk bab Performa TA (lihat docs/PENGUJIAN.md §2).
 *
 * Memakai witness yang sama dengan scripts/test-proofs.js (skema shielded-keypair).
 * Hanya mengukur `groth16.fullProve` (witness-gen + prove) — bukan verify.
 *
 * Usage:
 *   node scripts/bench-proofs.js [N]      (default N=15; 1 run warmup dibuang)
 */

const snarkjs = require("snarkjs");
const fs = require("fs");
const path = require("path");
const os = require("os");
const { buildPoseidon } = require("circomlibjs");

const N = Math.max(1, parseInt(process.argv[2] || "15", 10));

function stats(xs) {
    const s = [...xs].sort((a, b) => a - b);
    const n = s.length;
    const sum = s.reduce((a, b) => a + b, 0);
    const mean = sum / n;
    const median = n % 2 ? s[(n - 1) / 2] : (s[n / 2 - 1] + s[n / 2]) / 2;
    const p95 = s[Math.min(n - 1, Math.ceil(0.95 * n) - 1)];
    const variance = s.reduce((a, b) => a + (b - mean) ** 2, 0) / n;
    return { mean, median, p95, min: s[0], max: s[n - 1], stdev: Math.sqrt(variance) };
}

const r0 = (x) => Math.round(x);

async function constraintCount(name) {
    const r1cs = path.join(__dirname, "..", "build", name, `${name}.r1cs`);
    if (!fs.existsSync(r1cs)) return null;
    try {
        const info = await snarkjs.r1cs.info(r1cs);
        return info.nConstraints ?? info.nConstraint ?? null;
    } catch { return null; }
}

async function benchCircuit(name, inputs) {
    const wasm = path.join(__dirname, "..", "build", name, `${name}_js`, `${name}.wasm`);
    const zkey = path.join(__dirname, "..", "keys", `${name}_final.zkey`);
    if (!fs.existsSync(wasm) || !fs.existsSync(zkey)) {
        console.log(`  ! lewati ${name} (artefak build/keys hilang)`);
        return null;
    }
    // 1 warmup (dibuang — biaya muat wasm/zkey + JIT pertama)
    await snarkjs.groth16.fullProve(inputs, wasm, zkey);
    const samples = [];
    for (let i = 0; i < N; i++) {
        const t0 = process.hrtime.bigint();
        await snarkjs.groth16.fullProve(inputs, wasm, zkey);
        samples.push(Number(process.hrtime.bigint() - t0) / 1e6); // ms
    }
    return { samples, nConstraints: await constraintCount(name) };
}

async function main() {
    const p = await buildPoseidon();
    const F = p.F;

    // --- balance_check ---
    const bBalance = BigInt(1_000_000), bSalt = BigInt(123456789), bMin = BigInt(500_000);
    const bCommit = F.toString(p([bBalance, bSalt]));
    const balanceInputs = {
        balance: bBalance.toString(), salt: bSalt.toString(),
        minAmount: bMin.toString(), balanceCommitment: bCommit,
    };

    // --- private_transfer (shielded-keypair) ---
    const amountIn = BigInt("1000000000000000000");
    const transferAmount = BigInt("300000000000000000");
    const sPriv = BigInt("12345678901234567890"), sSalt = BigInt("11223344"), cSalt = BigInt("55667788");
    const rPriv = BigInt("98765432109876543210"), rSalt = BigInt("44332211");
    const sPub = F.toString(p([sPriv])), rPub = F.toString(p([rPriv]));
    const sCommit = F.toString(p([amountIn, BigInt(sPub), sSalt]));
    const change = amountIn - transferAmount;
    const transferInputs = {
        amountIn: amountIn.toString(), senderShieldPriv: sPriv.toString(), senderSalt: sSalt.toString(),
        transferAmount: transferAmount.toString(), changeSalt: cSalt.toString(),
        recipientShieldPub: rPub, recipientSalt: rSalt.toString(),
        senderCommitment: sCommit,
        nullifier: F.toString(p([sPriv, BigInt(sCommit)])),
        newSelfCommitment: F.toString(p([change, BigInt(sPub), cSalt])),
        recipientCommitment: F.toString(p([transferAmount, BigInt(rPub), rSalt])),
    };

    // --- withdraw ---
    const wPriv = BigInt("12345678901234567890"), wSalt = BigInt("98765432109876543210");
    const wAmount = BigInt("1000000000000000000");
    const wRecipient = BigInt("0x16a747E428a954328bd3cb67963fa85f4175e6a4");
    const wPub = F.toString(p([wPriv]));
    const wCommit = F.toString(p([wAmount, BigInt(wPub), wSalt]));
    const withdrawInputs = {
        shieldPriv: wPriv.toString(), salt: wSalt.toString(), commitment: wCommit,
        nullifier: F.toString(p([wPriv, BigInt(wCommit)])),
        recipient: wRecipient.toString(), amount: wAmount.toString(),
    };

    const circuits = [
        ["balance_check", balanceInputs],
        ["private_transfer", transferInputs],
        ["withdraw", withdrawInputs],
    ];

    console.log("================================================");
    console.log(`Bench proof generation — N=${N} (warmup dibuang)`);
    console.log(`Node ${process.version} | ${os.cpus()[0].model} | ${os.cpus().length} vCPU | ${(os.totalmem() / 1e9).toFixed(1)} GB RAM`);
    console.log("================================================");

    const rows = [];
    for (const [name, inputs] of circuits) {
        process.stdout.write(`\n${name}: menjalankan ${N}x ... `);
        const r = await benchCircuit(name, inputs);
        if (!r) continue;
        const st = stats(r.samples);
        console.log("selesai");
        rows.push({ name, ...st, nConstraints: r.nConstraints });
    }

    console.log("\n================================================");
    console.log("Hasil (ms):");
    console.log("circuit            | constraints | mean | median |  p95 |  min |  max | stdev");
    console.log("-------------------|-------------|------|--------|------|------|------|------");
    for (const r of rows) {
        console.log(
            `${r.name.padEnd(18)} | ${String(r.nConstraints ?? "?").padStart(11)} | ` +
            `${String(r0(r.mean)).padStart(4)} | ${String(r0(r.median)).padStart(6)} | ` +
            `${String(r0(r.p95)).padStart(4)} | ${String(r0(r.min)).padStart(4)} | ` +
            `${String(r0(r.max)).padStart(4)} | ${r.stdev.toFixed(1).padStart(5)}`,
        );
    }
    console.log("================================================");
    process.exit(0);
}

main().catch((e) => { console.error(e); process.exit(1); });
