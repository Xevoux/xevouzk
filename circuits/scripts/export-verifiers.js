/**
 * Export Solidity Verifiers Script
 * Generates Solidity verifier contracts from zkey files
 */

const snarkjs = require("snarkjs");
const fs = require("fs");
const path = require("path");

// auth_proof tidak dipakai (Schnorr menggantikan Groth16 untuk autentikasi).
const circuits = ["balance_check", "private_transfer", "withdraw"];

const keysDir = path.join(__dirname, "..", "keys");
const contractsDir = path.join(__dirname, "..", "..", "contracts", "contracts", "verifiers");

async function main() {
    // Ensure contracts directory exists
    if (!fs.existsSync(contractsDir)) {
        fs.mkdirSync(contractsDir, { recursive: true });
    }

    console.log("Generating Solidity verifier contracts...\n");

    for (const circuit of circuits) {
        const zkeyPath = path.join(keysDir, `${circuit}_final.zkey`);
        const contractName = circuit
            .split("_")
            .map(word => word.charAt(0).toUpperCase() + word.slice(1))
            .join("") + "Verifier";
        const solidityPath = path.join(contractsDir, `${contractName}.sol`);

        if (fs.existsSync(zkeyPath)) {
            try {
                // Generate Solidity code
                let solidityCode = await snarkjs.zKey.exportSolidityVerifier(
                    zkeyPath,
                    {
                        groth16: fs.readFileSync(
                            path.join(__dirname, "..", "node_modules", "snarkjs", "templates", "verifier_groth16.sol.ejs"),
                            "utf8"
                        )
                    }
                );

                // Update contract name
                solidityCode = solidityCode.replace(
                    /contract Groth16Verifier/g,
                    `contract ${contractName}`
                );

                fs.writeFileSync(solidityPath, solidityCode);
                console.log(`✓ ${contractName}.sol`);
            } catch (error) {
                console.log(`✗ ${contractName}.sol (${error.message})`);
            }
        } else {
            console.log(`✗ ${circuit}_final.zkey (not found)`);
        }
    }

    // Generate combined verifier interface
    const interfaceCode = `// SPDX-License-Identifier: MIT
pragma solidity ^0.8.20;

/**
 * @title IZKVerifier
 * @dev Interface for ZK-SNARK verifiers
 */
interface IZKVerifier {
    function verifyProof(
        uint[2] calldata _pA,
        uint[2][2] calldata _pB,
        uint[2] calldata _pC,
        uint[] calldata _pubSignals
    ) external view returns (bool);
}
`;
    
    fs.writeFileSync(path.join(contractsDir, "IZKVerifier.sol"), interfaceCode);
    console.log(`✓ IZKVerifier.sol (interface)`);

    console.log("\nDone!");
}

main().catch(console.error);

