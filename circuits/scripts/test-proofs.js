/**
 * Test Proofs Script — local Groth16 generate + verify offline (follow-up).
 *
 * Circuit proof generation + verify test (balance_check, private_transfer, withdraw).
 * auth_proof tidak dipakai (Schnorr menggantikan Groth16 untuk autentikasi).
 *
 * Usage: node scripts/test-proofs.js
 */

const snarkjs = require("snarkjs");
const fs = require("fs");
const path = require("path");
const { buildPoseidon } = require("circomlibjs");

let poseidon = null;
async function getPoseidon() {
    if (!poseidon) poseidon = await buildPoseidon();
    return poseidon;
}

function fail(msg) {
    console.log(`  ✗ ${msg}`);
    return false;
}

async function testCircuit(name, inputs, expectedPublicSignals = null) {
    console.log(`\n=== ${name} ===`);
    const wasmPath = path.join(__dirname, "..", "build", name, `${name}_js`, `${name}.wasm`);
    const zkeyPath = path.join(__dirname, "..", "keys", `${name}_final.zkey`);
    const vkeyPath = path.join(__dirname, "..", "keys", `${name}_verification_key.json`);

    if (!fs.existsSync(wasmPath)) return fail(`wasm missing: ${wasmPath}`);
    if (!fs.existsSync(zkeyPath)) return fail(`zkey missing: ${zkeyPath}`);
    if (!fs.existsSync(vkeyPath)) return fail(`vkey missing: ${vkeyPath}`);

    try {
        console.log("  generating proof...");
        const t0 = Date.now();
        const { proof, publicSignals } = await snarkjs.groth16.fullProve(inputs, wasmPath, zkeyPath);
        console.log(`  ✓ proof generated in ${Date.now() - t0}ms`);

        const vkey = JSON.parse(fs.readFileSync(vkeyPath, "utf8"));
        const verified = await snarkjs.groth16.verify(vkey, publicSignals, proof);
        if (!verified) return fail("snarkjs.verify returned false");
        console.log("  ✓ proof verified");

        if (expectedPublicSignals && JSON.stringify(publicSignals) !== JSON.stringify(expectedPublicSignals)) {
            return fail(`publicSignals mismatch.\n    expected: ${JSON.stringify(expectedPublicSignals)}\n    got:      ${JSON.stringify(publicSignals)}`);
        }

        // Negative test: flip a byte in proof.pi_c → should reject
        const tampered = JSON.parse(JSON.stringify(proof));
        tampered.pi_c[0] = tampered.pi_c[0] === "1" ? "2" : "1";
        const tamperedResult = await snarkjs.groth16.verify(vkey, publicSignals, tampered);
        if (tamperedResult) return fail("tampered proof was accepted (should reject)");
        console.log("  ✓ tampered proof rejected (invalid case)");

        return true;
    } catch (e) {
        return fail(e.message);
    }
}

async function main() {
    console.log("================================================");
    console.log("ZK-SNARK Proof Tests (balance_check + private_transfer + withdraw)");
    console.log("================================================");

    const p = await getPoseidon();

    //
    // balance_check: prove balance >= minAmount without revealing balance
    //
    const balance = BigInt(1_000_000);
    const balanceSalt = BigInt(123456789);
    const minAmount = BigInt(500_000);
    const balanceCommitment = p.F.toString(p([balance, balanceSalt]));

    const balanceOk = await testCircuit("balance_check", {
        balance: balance.toString(),
        salt: balanceSalt.toString(),
        minAmount: minAmount.toString(),
        balanceCommitment: balanceCommitment,
    });

    //
    // private_transfer (shielded-keypair)
    // senderCommitment   = Poseidon(amountIn, senderShieldPub, senderSalt)
    // nullifier          = Poseidon(senderShieldPriv, senderCommitment)
    // newSelfCommitment  = Poseidon(change, senderShieldPub, changeSalt)
    // recipientCommitment= Poseidon(transferAmount, recipientShieldPub, recipientSalt)
    //
    const amountIn = BigInt("1000000000000000000");       // 1 MATIC wei
    const transferAmount = BigInt("300000000000000000");  // 0.3 MATIC wei
    const senderShieldPriv = BigInt("12345678901234567890");
    const senderSalt = BigInt("11223344");
    const changeSalt = BigInt("55667788");
    const recipientShieldPriv = BigInt("98765432109876543210");
    const recipientSalt = BigInt("44332211");

    const senderShieldPub = p.F.toString(p([senderShieldPriv]));
    const recipientShieldPub = p.F.toString(p([recipientShieldPriv]));
    const senderCommitment = p.F.toString(p([amountIn, BigInt(senderShieldPub), senderSalt]));
    const change = amountIn - transferAmount;
    const nullifier = p.F.toString(p([senderShieldPriv, BigInt(senderCommitment)]));
    const newSelfCommitment = p.F.toString(p([change, BigInt(senderShieldPub), changeSalt]));
    const recipientCommitment = p.F.toString(p([transferAmount, BigInt(recipientShieldPub), recipientSalt]));

    const transferOk = await testCircuit("private_transfer", {
        amountIn: amountIn.toString(),
        senderShieldPriv: senderShieldPriv.toString(),
        senderSalt: senderSalt.toString(),
        transferAmount: transferAmount.toString(),
        changeSalt: changeSalt.toString(),
        recipientShieldPub: recipientShieldPub,
        recipientSalt: recipientSalt.toString(),
        senderCommitment,
        nullifier,
        newSelfCommitment,
        recipientCommitment,
    }, [senderCommitment, nullifier, newSelfCommitment, recipientCommitment]);

    //
    // withdraw (model shielded-keypair)
    // shieldPub = Poseidon(shieldPriv)
    // commitment = Poseidon(amount, shieldPub, salt)
    // nullifier = Poseidon(shieldPriv, commitment)
    //
    const wShieldPriv = BigInt("12345678901234567890");
    const wSalt = BigInt("98765432109876543210");
    const wAmount = BigInt("1000000000000000000"); // 1 MATIC wei
    const wRecipient = BigInt("0x16a747E428a954328bd3cb67963fa85f4175e6a4");
    const wShieldPub = p.F.toString(p([wShieldPriv]));
    const wCommitment = p.F.toString(p([wAmount, BigInt(wShieldPub), wSalt]));
    const wNullifier = p.F.toString(p([wShieldPriv, BigInt(wCommitment)]));

    const withdrawOk = await testCircuit("withdraw", {
        shieldPriv: wShieldPriv.toString(),
        salt: wSalt.toString(),
        commitment: wCommitment,
        nullifier: wNullifier,
        recipient: wRecipient.toString(),
        amount: wAmount.toString(),
    });

    // Negative withdraw: shieldPriv salah → commitment mismatch (witness gen gagal)
    console.log("\n=== withdraw (wrong shieldPriv — commitment mismatch) ===");
    try {
        await snarkjs.groth16.fullProve({
            shieldPriv: (wShieldPriv + BigInt(1)).toString(),
            salt: wSalt.toString(),
            commitment: wCommitment,
            nullifier: wNullifier,
            recipient: wRecipient.toString(),
            amount: wAmount.toString(),
        },
        path.join(__dirname, "..", "build", "withdraw", "withdraw_js", "withdraw.wasm"),
        path.join(__dirname, "..", "keys", "withdraw_final.zkey"));
        console.log("  ✗ wrong shieldPriv was accepted (BUG — keamanan transfer bocor)");
        process.exit(1);
    } catch (e) {
        console.log("  ✓ wrong shieldPriv rejected (hanya pemilik bisa spend)");
    }

    //
    // Negative: balance < minAmount → witness generation gagal
    //
    console.log("\n=== balance_check (insufficient balance) ===");
    try {
        await snarkjs.groth16.fullProve({
            balance: "100",
            salt: balanceSalt.toString(),
            minAmount: "999999",
            balanceCommitment: p.F.toString(p([BigInt(100), balanceSalt])),
        },
        path.join(__dirname, "..", "build", "balance_check", "balance_check_js", "balance_check.wasm"),
        path.join(__dirname, "..", "keys", "balance_check_final.zkey"));
        console.log("  ✗ insufficient balance was accepted (BUG)");
        process.exit(1);
    } catch (e) {
        console.log("  ✓ insufficient balance rejected (witness gen failed as expected)");
    }

    console.log("\n================================================");
    const allOk = balanceOk && transferOk && withdrawOk;
    console.log(allOk ? "✓ ALL TESTS PASSED" : "✗ SOME TESTS FAILED");
    console.log("================================================");

    process.exit(allOk ? 0 : 1);
}

main().catch((e) => { console.error(e); process.exit(1); });
