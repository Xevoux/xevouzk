/**
 * End-to-end on-chain test: deposit → privateTransfer → withdraw (recipient note).
 *
 * Membuktikan lifecycle commitment-pool private transfer dengan REAL Groth16 proof di Amoy:
 *   1. Sender A deposit MATIC → commitmentA = Poseidon(amount, shieldPubA, saltA)
 *   2. A privateTransfer ke B: burn commitmentA, mint newSelfCommitment (kembalian A)
 *      + recipientCommitment (note B), emit EncryptedNote(memo)
 *   3. B "menemukan" note-nya (di klien lewat scanIncomingNotes; di sini nilainya diketahui)
 *      lalu withdraw recipientCommitment → MATIC keluar ke EOA
 *   4. Double-spend: resubmit withdraw nullifier sama → revert
 *
 * Catatan: ECIES encrypt/decrypt + trial-decrypt scan sudah diuji terpisah
 * (resources/js/__tests__/note-crypto.test.mjs). Script ini fokus ke settlement on-chain.
 * Memo di sini diisi bytes acak hanya untuk memicu + memverifikasi event EncryptedNote.
 *
 * Usage: cd contracts && npx hardhat run scripts/test-transfer-e2e.js --network amoy
 */
const hre = require("hardhat");
const path = require("path");
const fs = require("fs");

const snarkjs = require(path.join(__dirname, "..", "..", "circuits", "node_modules", "snarkjs"));
const { buildPoseidon } = require(path.join(__dirname, "..", "..", "circuits", "node_modules", "circomlibjs"));

const CIRCUITS_DIR = path.join(__dirname, "..", "..", "circuits");
const FIELD = BigInt("21888242871839275222246405745257275088548364400416034343698204186575808495617");

function fail(msg) { console.log(`✗ ${msg}`); process.exit(1); }

function randomField() {
    const b = require("crypto").randomBytes(32);
    let v = 0n;
    for (const x of b) v = (v << 8n) | BigInt(x);
    return v % FIELD;
}

function formatForSolidity(proof, publicSignals) {
    return {
        a: [proof.pi_a[0], proof.pi_a[1]],
        b: [
            [proof.pi_b[0][1], proof.pi_b[0][0]],
            [proof.pi_b[1][1], proof.pi_b[1][0]],
        ],
        c: [proof.pi_c[0], proof.pi_c[1]],
        pubSignals: publicSignals,
    };
}

async function proveTransfer(P, w) {
    return snarkjs.groth16.fullProve(
        w,
        path.join(CIRCUITS_DIR, "build", "private_transfer", "private_transfer_js", "private_transfer.wasm"),
        path.join(CIRCUITS_DIR, "keys", "private_transfer_final.zkey"),
    );
}
async function proveWithdraw(w) {
    return snarkjs.groth16.fullProve(
        w,
        path.join(CIRCUITS_DIR, "build", "withdraw", "withdraw_js", "withdraw.wasm"),
        path.join(CIRCUITS_DIR, "keys", "withdraw_final.zkey"),
    );
}

async function main() {
    console.log("=== Private Transfer E2E (deposit → transfer → withdraw) ===\n");

    const deploymentPath = path.join(__dirname, "..", "deployments", `${hre.network.name}.json`);
    if (!fs.existsSync(deploymentPath)) fail(`No deployment for ${hre.network.name}`);
    const deployment = JSON.parse(fs.readFileSync(deploymentPath, "utf8"));
    const zkPaymentAddr = deployment.contracts.ZKPayment;
    console.log(`ZKPayment: ${zkPaymentAddr}`);

    const [signer] = await hre.ethers.getSigners();
    console.log(`Signer (relayer + funded): ${signer.address}`);
    const zkPayment = await hre.ethers.getContractAt("ZKPayment", zkPaymentAddr);
    const P = await buildPoseidon();
    const F = (x) => P.F.toObject(x);

    // Shielded keypairs (deterministic test values; di klien diturunkan dari password)
    const senderShieldPriv = randomField();
    const recipientShieldPriv = randomField();
    const senderShieldPub = F(P([senderShieldPriv]));
    const recipientShieldPub = F(P([recipientShieldPriv]));

    const depositAmount = hre.ethers.parseEther("0.02");
    const transferAmount = hre.ethers.parseEther("0.01");
    const change = depositAmount - transferAmount;

    // ---------------- 1. DEPOSIT ----------------
    console.log("\n[1/4] Deposit ke pool (A)...");
    const saltA = randomField();
    const commitmentA = F(P([depositAmount, senderShieldPub, saltA]));
    const poolBefore = await zkPayment.getContractBalance();
    let tx = await zkPayment.deposit(commitmentA, { value: depositAmount });
    let rc = await tx.wait();
    console.log(`  deposit tx: ${tx.hash} (block ${rc.blockNumber})`);
    if (!(await zkPayment.isCommitmentActive(commitmentA))) fail("commitmentA tidak aktif setelah deposit");
    console.log("  ✓ commitmentA aktif");

    // ---------------- 2. PRIVATE TRANSFER ----------------
    console.log("\n[2/4] Private transfer A → B (real Groth16 proof)...");
    const changeSalt = randomField();
    const recipientSalt = randomField();
    const newSelfCommitment = F(P([change, senderShieldPub, changeSalt]));
    const recipientCommitment = F(P([transferAmount, recipientShieldPub, recipientSalt]));
    const nullifierT = F(P([senderShieldPriv, commitmentA]));

    const tWitness = {
        amountIn: depositAmount.toString(),
        senderShieldPriv: senderShieldPriv.toString(),
        senderSalt: saltA.toString(),
        transferAmount: transferAmount.toString(),
        changeSalt: changeSalt.toString(),
        recipientShieldPub: recipientShieldPub.toString(),
        recipientSalt: recipientSalt.toString(),
        senderCommitment: commitmentA.toString(),
        nullifier: nullifierT.toString(),
        newSelfCommitment: newSelfCommitment.toString(),
        recipientCommitment: recipientCommitment.toString(),
    };
    const tProof = await proveTransfer(P, tWitness);
    const tSol = formatForSolidity(tProof.proof, tProof.publicSignals);
    const memo = "0x" + require("crypto").randomBytes(120).toString("hex"); // placeholder ECIES blob

    tx = await zkPayment.privateTransfer(tSol.a, tSol.b, tSol.c, tSol.pubSignals, memo);
    rc = await tx.wait();
    console.log(`  transfer tx: ${tx.hash} (gas ${rc.gasUsed})`);

    if (await zkPayment.isCommitmentActive(commitmentA)) fail("commitmentA masih aktif (harus burned)");
    if (!(await zkPayment.isCommitmentActive(newSelfCommitment))) fail("newSelfCommitment tidak aktif");
    if (!(await zkPayment.isCommitmentActive(recipientCommitment))) fail("recipientCommitment tidak aktif");
    const nullTHex = "0x" + nullifierT.toString(16).padStart(64, "0");
    if (!(await zkPayment.isNullifierUsed(nullTHex))) fail("nullifier transfer tidak ter-mark");
    const encEv = rc.logs.find((l) => l.fragment && l.fragment.name === "EncryptedNote");
    if (!encEv) fail("event EncryptedNote tidak ter-emit");
    if (encEv.args.recipientCommitment !== recipientCommitment) fail("EncryptedNote.recipientCommitment salah");
    if (encEv.args.memo !== memo) fail("EncryptedNote.memo salah");
    console.log("  ✓ burn old + mint 2 commitment + nullifier marked + EncryptedNote(memo) emit");

    // ---------------- 3. WITHDRAW note penerima ----------------
    // (Di klien: B menjalankan scanIncomingNotes, ECIES-decrypt memo → dapat {amount, salt}.
    //  Di sini nilai itu sudah diketahui script.)
    console.log("\n[3/4] B withdraw recipientCommitment → EOA...");
    const withdrawTo = signer.address; // kembalikan MATIC ke deployer (hemat dana test)
    const nullifierW = F(P([recipientShieldPriv, recipientCommitment]));
    const wWitness = {
        shieldPriv: recipientShieldPriv.toString(),
        salt: recipientSalt.toString(),
        commitment: recipientCommitment.toString(),
        nullifier: nullifierW.toString(),
        recipient: BigInt(withdrawTo).toString(),
        amount: transferAmount.toString(),
    };
    const wProof = await proveWithdraw(wWitness);
    const wSol = formatForSolidity(wProof.proof, wProof.publicSignals);

    const poolBeforeW = await zkPayment.getContractBalance();
    tx = await zkPayment.withdraw(wSol.a, wSol.b, wSol.c, wSol.pubSignals);
    rc = await tx.wait();
    console.log(`  withdraw tx: ${tx.hash} (gas ${rc.gasUsed})`);
    if (await zkPayment.isCommitmentActive(recipientCommitment)) fail("recipientCommitment masih aktif (harus burned)");
    const nullWHex = "0x" + nullifierW.toString(16).padStart(64, "0");
    if (!(await zkPayment.isNullifierUsed(nullWHex))) fail("nullifier withdraw tidak ter-mark");
    const poolAfterW = await zkPayment.getContractBalance();
    if (poolBeforeW - poolAfterW !== transferAmount) fail(`pool tidak berkurang sebesar transferAmount (${poolBeforeW - poolAfterW})`);
    console.log(`  ✓ MATIC keluar ${hre.ethers.formatEther(transferAmount)} + commitment burned + nullifier marked`);

    // ---------------- 4. Double-spend guard ----------------
    console.log("\n[4/4] Double-spend: resubmit withdraw nullifier sama...");
    try {
        await zkPayment.withdraw.staticCall(wSol.a, wSol.b, wSol.c, wSol.pubSignals);
        fail("replay withdraw diterima (BUG)");
    } catch (e) {
        const m = (e.message || "").toString();
        if (m.includes("Nullifier already used") || m.includes("Commitment not active")) {
            console.log("  ✓ replay ditolak (" + (m.includes("Nullifier") ? "Nullifier already used" : "Commitment not active") + ")");
        } else {
            console.log("  ⚠ ditolak dengan alasan lain: " + m.substring(0, 120));
        }
    }

    const poolInvariant = await zkPayment.getContractBalance();
    console.log("\n===================================");
    console.log("✓ FASE 2b PRIVATE TRANSFER E2E PROVEN ON-CHAIN");
    console.log("===================================");
    console.log(`  pool balance now: ${hre.ethers.formatEther(poolInvariant)} MATIC (sisa note kembalian A masih terkunci)`);
    console.log(`  deposit  : https://amoy.polygonscan.com/address/${zkPaymentAddr}`);
    console.log(`  change note (A) commitment: ${newSelfCommitment.toString()}`);
}

main().then(() => process.exit(0)).catch((e) => { console.error("Error:", e); process.exit(1); });
