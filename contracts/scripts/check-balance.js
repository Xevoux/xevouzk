// Quick balance check sebelum deploy.
const hre = require("hardhat");

async function main() {
    const [signer] = await hre.ethers.getSigners();
    const balance = await hre.ethers.provider.getBalance(signer.address);
    const eth = hre.ethers.formatEther(balance);
    console.log(`Network : ${hre.network.name}`);
    console.log(`Address : ${signer.address}`);
    console.log(`Balance : ${eth} MATIC`);
    if (parseFloat(eth) < 0.05) {
        console.log("⚠️  Balance LOW. Top up dari https://faucet.polygon.technology/");
        process.exit(2);
    }
    console.log("✓ Sufficient balance for deploy");
}

main().catch((e) => { console.error(e.message); process.exit(1); });
