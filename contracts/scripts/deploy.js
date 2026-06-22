/**
 * + Deploy script untuk XevouZK pasca + .
 *
 * Urutan deploy (4 kontrak)
 * 1. BalanceCheckVerifier (Groth16 verifier untuk balance_check circuit)
 * 2. PrivateTransferVerifier (Groth16 verifier untuk private_transfer circuit)
 * 3. WithdrawVerifier (Groth16 verifier untuk withdraw circuit —)
 * 4. ZKPayment(BV, TV, WV) (main contract, inject 3 verifier)
 *
 * Usage
 * npx hardhat run scripts/deploy.js --network amoy
 *
 * PRA-SYARAT
 * - Trusted setup sudah dijalankan untuk SEMUA 3 circuit (lihat docs/DEPLOY-GUIDE.md)
 * - VK di Groth16Verifier.sol sudah di-replace dari placeholder (auto via export-verifiers.js)
 * - Akun deployer di-funded Amoy MATIC (~0.4 cukup untuk 4 kontrak)
 * - .env: DEPLOYER_PRIVATE_KEY, POLYGON_AMOY_RPC_URL, POLYGONSCAN_API_KEY
 */
const hre = require("hardhat");
const fs = require("fs");
const path = require("path");

async function main() {
    console.log("=== XevouZK Deployment ( + ) ===\n");

    const [deployer] = await hre.ethers.getSigners();
    console.log("Network:", hre.network.name);
    console.log("Deployer:", deployer.address);

    const balance = await hre.ethers.provider.getBalance(deployer.address);
    console.log("Balance:", hre.ethers.formatEther(balance), "MATIC\n");

    if (balance === 0n) {
        throw new Error("Deployer wallet empty. Fund from https://faucet.polygon.technology/");
    }

    //
    // 1. BalanceCheckVerifier
    //
    console.log("1/4 Deploying BalanceCheckVerifier...");
    const BalanceCheckVerifier = await hre.ethers.getContractFactory("BalanceCheckVerifier");
    const balanceVerifier = await BalanceCheckVerifier.deploy();
    await balanceVerifier.waitForDeployment();
    const balanceVerifierAddress = await balanceVerifier.getAddress();
    console.log("    →", balanceVerifierAddress, "\n");

    //
    // 2. PrivateTransferVerifier
    //
    console.log("2/4 Deploying PrivateTransferVerifier...");
    const PrivateTransferVerifier = await hre.ethers.getContractFactory("PrivateTransferVerifier");
    const transferVerifier = await PrivateTransferVerifier.deploy();
    await transferVerifier.waitForDeployment();
    const transferVerifierAddress = await transferVerifier.getAddress();
    console.log("    →", transferVerifierAddress, "\n");

    //
    // 3. WithdrawVerifier
    //
    console.log("3/4 Deploying WithdrawVerifier...");
    const WithdrawVerifier = await hre.ethers.getContractFactory("WithdrawVerifier");
    const withdrawVerifier = await WithdrawVerifier.deploy();
    await withdrawVerifier.waitForDeployment();
    const withdrawVerifierAddress = await withdrawVerifier.getAddress();
    console.log("    →", withdrawVerifierAddress, "\n");

    //
    // 4. ZKPayment(balanceVerifier, transferVerifier, withdrawVerifier)
    //
    console.log("4/4 Deploying ZKPayment v2 ( note-based commitment pool)...");
    const ZKPayment = await hre.ethers.getContractFactory("ZKPayment");
    const zkPayment = await ZKPayment.deploy(
        balanceVerifierAddress,
        transferVerifierAddress,
        withdrawVerifierAddress
    );
    await zkPayment.waitForDeployment();
    const zkPaymentAddress = await zkPayment.getAddress();
    console.log("    →", zkPaymentAddress, "\n");

    //
    // Sanity check post-deploy
    //
    console.log("Verifying constructor wiring...");
    const owner = await zkPayment.owner();
    const bvInContract = await zkPayment.balanceVerifier();
    const tvInContract = await zkPayment.transferVerifier();
    const wvInContract = await zkPayment.withdrawVerifier();
    console.log("  owner            :", owner);
    console.log("  balanceVerifier  :", bvInContract);
    console.log("  transferVerifier :", tvInContract);
    console.log("  withdrawVerifier :", wvInContract);

    if (owner.toLowerCase() !== deployer.address.toLowerCase()) {
        throw new Error("Owner mismatch — deployment corrupted");
    }
    if (bvInContract.toLowerCase() !== balanceVerifierAddress.toLowerCase()) {
        throw new Error("balanceVerifier wiring wrong");
    }
    if (tvInContract.toLowerCase() !== transferVerifierAddress.toLowerCase()) {
        throw new Error("transferVerifier wiring wrong");
    }
    if (wvInContract.toLowerCase() !== withdrawVerifierAddress.toLowerCase()) {
        throw new Error("withdrawVerifier wiring wrong");
    }
    console.log("  ✓ all wiring correct\n");

    // invariant check at fresh deploy
    const totalDeposited = await zkPayment.totalDeposited();
    const totalWithdrawn = await zkPayment.totalWithdrawn();
    const txCount = await zkPayment.transactionCount();
    console.log("Initial state:");
    console.log("  totalDeposited   :", totalDeposited.toString(), "(expected 0)");
    console.log("  totalWithdrawn   :", totalWithdrawn.toString(), "(expected 0)");
    console.log("  transactionCount :", txCount.toString(), "(expected 0)");
    if (totalDeposited !== 0n || totalWithdrawn !== 0n || txCount !== 0n) {
        throw new Error("Initial state not zero — contract state corrupted at deploy");
    }
    console.log("  ✓ fresh state confirmed\n");

    //
    // Persist deployment info
    //
    const deploymentInfo = {
        network: hre.network.name,
        chainId: (await hre.ethers.provider.getNetwork()).chainId.toString(),
        deployer: deployer.address,
        timestamp: new Date().toISOString(),
        scheme: "Tornado-light commitment pool",
        contracts: {
            BalanceCheckVerifier: balanceVerifierAddress,
            PrivateTransferVerifier: transferVerifierAddress,
            WithdrawVerifier: withdrawVerifierAddress,
            ZKPayment: zkPaymentAddress,
        },
    };

    const outDir = path.join(__dirname, "..", "deployments");
    fs.mkdirSync(outDir, { recursive: true });
    const outPath = path.join(outDir, `${hre.network.name}.json`);
    fs.writeFileSync(outPath, JSON.stringify(deploymentInfo, null, 2));
    console.log("Deployment info →", outPath, "\n");

    //
    // Summary + next steps
    //
    console.log("=== SUMMARY ===");
    console.log("Network          :", hre.network.name);
    console.log("Chain ID         :", deploymentInfo.chainId);
    console.log("BalanceVerifier  :", balanceVerifierAddress);
    console.log("TransferVerifier :", transferVerifierAddress);
    console.log("WithdrawVerifier :", withdrawVerifierAddress);
    console.log("ZKPayment v2     :", zkPaymentAddress);
    console.log("===============\n");

    console.log("Next steps:");
    console.log("  1. Update .env (root project):");
    console.log("       POLYGON_CONTRACT_ADDRESS=" + zkPaymentAddress);
    console.log("       POLYGON_BALANCE_VERIFIER_ADDRESS=" + balanceVerifierAddress);
    console.log("       POLYGON_TRANSFER_VERIFIER_ADDRESS=" + transferVerifierAddress);
    console.log("       POLYGON_WITHDRAW_VERIFIER_ADDRESS=" + withdrawVerifierAddress);
    console.log("  2. php artisan config:clear");
    if (hre.network.name === "amoy" || hre.network.name === "polygon") {
        console.log("  3. Verify di PolygonScan:");
        console.log(`     npx hardhat verify --network ${hre.network.name} ${balanceVerifierAddress}`);
        console.log(`     npx hardhat verify --network ${hre.network.name} ${transferVerifierAddress}`);
        console.log(`     npx hardhat verify --network ${hre.network.name} ${withdrawVerifierAddress}`);
        console.log(`     npx hardhat verify --network ${hre.network.name} ${zkPaymentAddress} ${balanceVerifierAddress} ${transferVerifierAddress} ${withdrawVerifierAddress}`);
    }
}

main()
    .then(() => process.exit(0))
    .catch((err) => {
        console.error("\n✗ Deployment failed:", err.message);
        process.exit(1);
    });
