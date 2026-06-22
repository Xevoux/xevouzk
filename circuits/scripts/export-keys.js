/**
 * Export Keys Script
 * Copies verification keys to Laravel storage for server-side verification
 */

const fs = require("fs");
const path = require("path");

// auth_proof legacy dihapus (Schnorr menggantikan auth Groth16); withdraw baru.
const circuits = ["balance_check", "private_transfer", "withdraw"];

const keysDir = path.join(__dirname, "..", "keys");
const storageDir = path.join(__dirname, "..", "..", "storage", "app", "zk-keys");

// Ensure storage directory exists
if (!fs.existsSync(storageDir)) {
    fs.mkdirSync(storageDir, { recursive: true });
}

console.log("Exporting verification keys to Laravel storage...\n");

for (const circuit of circuits) {
    const vkeyPath = path.join(keysDir, `${circuit}_verification_key.json`);
    const destPath = path.join(storageDir, `${circuit}_verification_key.json`);

    if (fs.existsSync(vkeyPath)) {
        fs.copyFileSync(vkeyPath, destPath);
        console.log(`✓ ${circuit}_verification_key.json`);
    } else {
        console.log(`✗ ${circuit}_verification_key.json (not found)`);
    }
}

// Also copy wasm files for client-side proof generation
const publicDir = path.join(__dirname, "..", "..", "public", "zk");

if (!fs.existsSync(publicDir)) {
    fs.mkdirSync(publicDir, { recursive: true });
}

console.log("\nExporting WASM and zkey files to public folder...\n");

for (const circuit of circuits) {
    const wasmDir = path.join(__dirname, "..", "build", circuit, `${circuit}_js`);
    const wasmPath = path.join(wasmDir, `${circuit}.wasm`);
    const zkeyPath = path.join(keysDir, `${circuit}_final.zkey`);

    const destWasmDir = path.join(publicDir, circuit);
    
    if (!fs.existsSync(destWasmDir)) {
        fs.mkdirSync(destWasmDir, { recursive: true });
    }

    if (fs.existsSync(wasmPath)) {
        fs.copyFileSync(wasmPath, path.join(destWasmDir, `${circuit}.wasm`));
        console.log(`✓ ${circuit}/${circuit}.wasm`);
    } else {
        console.log(`✗ ${circuit}/${circuit}.wasm (not found)`);
    }

    if (fs.existsSync(zkeyPath)) {
        fs.copyFileSync(zkeyPath, path.join(destWasmDir, `${circuit}_final.zkey`));
        console.log(`✓ ${circuit}/${circuit}_final.zkey`);
    } else {
        console.log(`✗ ${circuit}/${circuit}_final.zkey (not found)`);
    }

    // Copy verification key to public too (for client-side verification)
    const vkeyPath = path.join(keysDir, `${circuit}_verification_key.json`);
    if (fs.existsSync(vkeyPath)) {
        fs.copyFileSync(vkeyPath, path.join(destWasmDir, `verification_key.json`));
        console.log(`✓ ${circuit}/verification_key.json`);
    }
}

console.log("\nDone!");

