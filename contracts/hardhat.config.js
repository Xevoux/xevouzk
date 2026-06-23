require("@nomicfoundation/hardhat-toolbox");
require("dotenv").config();

/** @type import('hardhat/config').HardhatUserConfig */
module.exports = {
  solidity: {
    version: "0.8.20",
    settings: {
      optimizer: {
        enabled: true,
        runs: 200
      }
    }
  },
  gasReporter: {
    enabled: process.env.REPORT_GAS === "true",
    currency: "USD",
    gasPrice: 35 // gwei — samakan dengan jaringan Amoy (networks.amoy.gasPrice)
  },
  networks: {
    // Polygon Amoy Testnet (pengganti Mumbai yang sudah dihentikan)
    amoy: {
      url: process.env.POLYGON_AMOY_RPC_URL || "https://rpc-amoy.polygon.technology/",
      accounts: process.env.DEPLOYER_PRIVATE_KEY ? [process.env.DEPLOYER_PRIVATE_KEY] : [],
      chainId: 80002,
      gasPrice: 35000000000 // 35 gwei (increased for network requirements)
    },
    // Polygon Mainnet
    polygon: {
      url: process.env.POLYGON_MAINNET_RPC_URL || "https://polygon-rpc.com/",
      accounts: process.env.DEPLOYER_PRIVATE_KEY ? [process.env.DEPLOYER_PRIVATE_KEY] : [],
      chainId: 137,
      gasPrice: 50000000000 // 50 gwei
    },
    // Local development
    hardhat: {
      chainId: 1337
    }
  },
  etherscan: {
    apiKey: process.env.POLYGONSCAN_API_KEY || "",
    customChains: [
      {
        network: "polygonAmoy",
        chainId: 80002,
        urls: {
          apiURL: "https://api-amoy.polygonscan.com/api",
          browserURL: "https://amoy.polygonscan.com"
        }
      }
    ]
  },
  paths: {
    sources: "./contracts",
    tests: "./test",
    cache: "./cache",
    artifacts: "./artifacts"
  }
};
