/**
 * Trusted Setup Script
 * Generates proving and verification keys for a circuit
 * 
 * Usage: node scripts/setup.js <circuit_name>
 * Example: node scripts/setup.js balance_check
 */

const snarkjs = require("snarkjs");
const fs = require("fs");
const path = require("path");

async function main() {
    const circuitName = process.argv[2];
    
    if (!circuitName) {
        console.error("Usage: node setup.js <circuit_name>");
        console.error("Example: node setup.js balance_check");
        process.exit(1);
    }

    const buildDir = path.join(__dirname, "..", "build", circuitName);
    const keysDir = path.join(__dirname, "..", "keys");
    const ptauPath = path.join(__dirname, "..", "ptau", "pot14_final.ptau");

    // Ensure directories exist
    if (!fs.existsSync(keysDir)) {
        fs.mkdirSync(keysDir, { recursive: true });
    }

    const r1csPath = path.join(buildDir, `${circuitName}.r1cs`);
    const zkey0Path = path.join(keysDir, `${circuitName}_0000.zkey`);
    const zkeyFinalPath = path.join(keysDir, `${circuitName}_final.zkey`);
    const vkeyPath = path.join(keysDir, `${circuitName}_verification_key.json`);

    console.log("============================================");
    console.log(`ZK-SNARK Trusted Setup: ${circuitName}`);
    console.log("============================================\n");

    // Check if r1cs exists
    if (!fs.existsSync(r1csPath)) {
        console.error(`Error: R1CS file not found: ${r1csPath}`);
        console.error("Please compile the circuit first: npm run compile:<circuit_name>");
        process.exit(1);
    }

    // Check if ptau exists
    if (!fs.existsSync(ptauPath)) {
        console.error(`Error: Powers of Tau file not found: ${ptauPath}`);
        console.error("Please download it first: npm run download:ptau");
        process.exit(1);
    }

    try {
        // Step 1: Generate initial zkey (Phase 1)
        console.log("Step 1: Running Groth16 setup (Phase 1)...");
        await snarkjs.zKey.newZKey(r1csPath, ptauPath, zkey0Path);
        console.log("✓ Initial zkey generated\n");

        // Step 2: Contribute to ceremony (Phase 2)
        console.log("Step 2: Contributing to ceremony (Phase 2)...");
        await snarkjs.zKey.contribute(
            zkey0Path,
            zkeyFinalPath,
            "XevouZK Contribution",
            // In production, use secure random entropy
            `${Date.now()}_${Math.random().toString(36)}_zkpayment_entropy`
        );
        console.log("✓ Contribution added\n");

        // Step 3: Export verification key
        console.log("Step 3: Exporting verification key...");
        const vkey = await snarkjs.zKey.exportVerificationKey(zkeyFinalPath);
        fs.writeFileSync(vkeyPath, JSON.stringify(vkey, null, 2));
        console.log(`✓ Verification key saved to: ${vkeyPath}\n`);

        // Step 4: Verify the zkey
        console.log("Step 4: Verifying zkey...");
        const verified = await snarkjs.zKey.verifyFromR1cs(r1csPath, ptauPath, zkeyFinalPath);
        if (verified) {
            console.log("✓ ZKey verification passed!\n");
        } else {
            console.error("✗ ZKey verification failed!");
            process.exit(1);
        }

        // Clean up intermediate file
        if (fs.existsSync(zkey0Path)) {
            fs.unlinkSync(zkey0Path);
        }

        console.log("============================================");
        console.log("Trusted Setup Complete!");
        console.log("============================================");
        console.log(`\nOutputs:`);
        console.log(`  - Final zkey: ${zkeyFinalPath}`);
        console.log(`  - Verification key: ${vkeyPath}`);
        console.log(`\nNext steps:`);
        console.log(`  1. npm run export:verifiers - Generate Solidity verifier`);
        console.log(`  2. Copy keys to production server`);

    } catch (error) {
        console.error("Setup failed:", error.message);
        process.exit(1);
    }
}

main().then(() => {
    process.exit(0);
}).catch((err) => {
    console.error(err);
    process.exit(1);
});

