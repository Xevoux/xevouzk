/**
 * + + Hardhat behavioral test untuk ZKPayment v2.
 *
 * Note-based commitment pool (Tornado-light)
 * deposit(commitment) → privateTransfer (burn old + mint two new) → withdraw (burn + transfer MATIC)
 *
 * Pakai 3 mock verifier (selalu return true/false) supaya bisa di-test tanpa real
 * trusted-setup keys. Real Groth16 verifier di-test ketika circuit baru (withdraw.circom)
 * + trusted setup + deploy selesai (E).
 *
 * Run
 * cd contracts && npm install && npx hardhat test
 */
const { expect } = require("chai");
const { ethers } = require("hardhat");

describe("ZKPayment v2 ( + + )", function () {
    let zkPayment;
    let balanceVerifier;
    let transferVerifier;
    let withdrawVerifier;
    let owner;
    let user;
    let recipient;

    // Dummy proof components — encoding correct, signature ok, content irrelevant
    // karena mock verifier ignore inputs.
    const dummyA = [1, 2];
    const dummyB = [[1, 2], [3, 4]];
    const dummyC = [5, 6];
    const MEMO = "0x" + "cd".repeat(88); // ephPub(32)+iv(12)+ct dummy

    // Commitment values yang dipakai across test (decimal, sesuai uint256)
    const C_DEPOSIT = 0x1111n;
    const C_NEW_SELF = 0x2222n;
    const C_RECIPIENT = 0x3333n;
    const NULLIFIER_TRANSFER = "0x" + "aa".repeat(32);
    const NULLIFIER_WITHDRAW = "0x" + "bb".repeat(32);

    beforeEach(async function () {
        [owner, user, recipient] = await ethers.getSigners();

        const MockVerifier = await ethers.getContractFactory("MockVerifier");
        balanceVerifier = await MockVerifier.deploy(true);
        await balanceVerifier.waitForDeployment();
        transferVerifier = await MockVerifier.deploy(true);
        await transferVerifier.waitForDeployment();

        const MockWithdrawVerifier = await ethers.getContractFactory("MockWithdrawVerifier");
        withdrawVerifier = await MockWithdrawVerifier.deploy(true);
        await withdrawVerifier.waitForDeployment();

        const ZKPayment = await ethers.getContractFactory("ZKPayment");
        zkPayment = await ZKPayment.deploy(
            await balanceVerifier.getAddress(),
            await transferVerifier.getAddress(),
            await withdrawVerifier.getAddress()
        );
        await zkPayment.waitForDeployment();
    });

    // Helper: bangun pubSignals untuk privateTransfer dengan oldCommitment yang sudah deposit
    function transferSignals(oldCommitment, nullifier, newSelf, recipientCom) {
        return [
            oldCommitment,
            nullifier,
            newSelf,
            recipientCom,
        ];
    }

    function withdrawSignals(commitment, nullifier, recipientAddr, amountWei) {
        return [
            commitment,
            nullifier,
            BigInt(recipientAddr), // address as uint
            amountWei,
        ];
    }

    describe("constructor", function () {
        it("rejects zero address balance verifier", async function () {
            const ZKPayment = await ethers.getContractFactory("ZKPayment");
            await expect(
                ZKPayment.deploy(
                    ethers.ZeroAddress,
                    await transferVerifier.getAddress(),
                    await withdrawVerifier.getAddress()
                )
            ).to.be.revertedWith("Invalid balance verifier");
        });

        it("rejects zero address transfer verifier", async function () {
            const ZKPayment = await ethers.getContractFactory("ZKPayment");
            await expect(
                ZKPayment.deploy(
                    await balanceVerifier.getAddress(),
                    ethers.ZeroAddress,
                    await withdrawVerifier.getAddress()
                )
            ).to.be.revertedWith("Invalid transfer verifier");
        });

        it("rejects zero address withdraw verifier", async function () {
            const ZKPayment = await ethers.getContractFactory("ZKPayment");
            await expect(
                ZKPayment.deploy(
                    await balanceVerifier.getAddress(),
                    await transferVerifier.getAddress(),
                    ethers.ZeroAddress
                )
            ).to.be.revertedWith("Invalid withdraw verifier");
        });

        it("wires owner + all three verifiers correctly", async function () {
            expect(await zkPayment.owner()).to.equal(owner.address);
            expect(await zkPayment.balanceVerifier()).to.equal(await balanceVerifier.getAddress());
            expect(await zkPayment.transferVerifier()).to.equal(await transferVerifier.getAddress());
            expect(await zkPayment.withdrawVerifier()).to.equal(await withdrawVerifier.getAddress());
        });
    });

    describe("deposit ( AC-4)", function () {
        it("accepts MATIC + activates commitment + tracks totalDeposited", async function () {
            const value = ethers.parseEther("0.5");
            const tx = await zkPayment.connect(user).deposit(C_DEPOSIT, { value });
            const receipt = await tx.wait();

            expect(await zkPayment.isCommitmentActive(C_DEPOSIT)).to.be.true;
            expect(await zkPayment.totalDeposited()).to.equal(value);
            expect(await zkPayment.getContractBalance()).to.equal(value);

            const event = receipt.logs.find(l => l.fragment && l.fragment.name === "Deposit");
            expect(event.args.user).to.equal(user.address);
            expect(event.args.commitment).to.equal(C_DEPOSIT);
            expect(event.args.amount).to.equal(value);
        });

        it("reverts when value == 0", async function () {
            await expect(
                zkPayment.connect(user).deposit(C_DEPOSIT, { value: 0 })
            ).to.be.revertedWith("Deposit amount must be greater than 0");
        });

        it("reverts when commitment == 0", async function () {
            await expect(
                zkPayment.connect(user).deposit(0, { value: ethers.parseEther("0.1") })
            ).to.be.revertedWith("Invalid commitment");
        });

        it("reverts when commitment already active (double-deposit)", async function () {
            await zkPayment.connect(user).deposit(C_DEPOSIT, { value: ethers.parseEther("0.1") });
            await expect(
                zkPayment.connect(user).deposit(C_DEPOSIT, { value: ethers.parseEther("0.1") })
            ).to.be.revertedWith("Commitment already active");
        });
    });

    describe("privateTransfer v2 ( AC-5 — burn old + mint two new)", function () {
        beforeEach(async function () {
            // Pre-deposit supaya old commitment aktif
            await zkPayment.connect(user).deposit(C_DEPOSIT, { value: ethers.parseEther("0.5") });
        });

        it("burns old commitment + mints two new + marks nullifier + emits event", async function () {
            const signals = transferSignals(C_DEPOSIT, NULLIFIER_TRANSFER, C_NEW_SELF, C_RECIPIENT);
            const tx = await zkPayment.privateTransfer(dummyA, dummyB, dummyC, signals, MEMO);
            const receipt = await tx.wait();

            // Old commitment burned
            expect(await zkPayment.isCommitmentActive(C_DEPOSIT)).to.be.false;
            // Two new commitments minted
            expect(await zkPayment.isCommitmentActive(C_NEW_SELF)).to.be.true;
            expect(await zkPayment.isCommitmentActive(C_RECIPIENT)).to.be.true;
            // Nullifier marked
            expect(await zkPayment.isNullifierUsed(NULLIFIER_TRANSFER)).to.be.true;
            // Counter incremented
            expect(await zkPayment.transactionCount()).to.equal(1);

            const event = receipt.logs.find(l => l.fragment && l.fragment.name === "PrivateTransfer");
            expect(event.args.nullifier).to.equal(NULLIFIER_TRANSFER);
            expect(event.args.oldCommitment).to.equal(C_DEPOSIT);
            expect(event.args.newSelfCommitment).to.equal(C_NEW_SELF);
            expect(event.args.recipientCommitment).to.equal(C_RECIPIENT);
        });

        it("emits EncryptedNote(recipientCommitment, memo)", async function () {
            const signals = transferSignals(C_DEPOSIT, NULLIFIER_TRANSFER, C_NEW_SELF, C_RECIPIENT);
            const tx = await zkPayment.privateTransfer(dummyA, dummyB, dummyC, signals, MEMO);
            const receipt = await tx.wait();
            const ev = receipt.logs.find(l => l.fragment && l.fragment.name === "EncryptedNote");
            expect(ev).to.not.be.undefined;
            expect(ev.args.recipientCommitment).to.equal(C_RECIPIENT);
            expect(ev.args.memo).to.equal(MEMO);
        });

        it("reverts when old commitment not active", async function () {
            const signals = transferSignals(0x9999n, NULLIFIER_TRANSFER, C_NEW_SELF, C_RECIPIENT);
            await expect(
                zkPayment.privateTransfer(dummyA, dummyB, dummyC, signals, MEMO)
            ).to.be.revertedWith("Old commitment not active");
        });

        it("reverts when nullifier reused (double-spend)", async function () {
            const signals1 = transferSignals(C_DEPOSIT, NULLIFIER_TRANSFER, C_NEW_SELF, C_RECIPIENT);
            await zkPayment.privateTransfer(dummyA, dummyB, dummyC, signals1, MEMO);

            // Bikin deposit baru supaya old commitment aktif lagi
            await zkPayment.connect(user).deposit(0x4444n, { value: ethers.parseEther("0.1") });
            const signals2 = transferSignals(0x4444n, NULLIFIER_TRANSFER, 0x5555n, 0x6666n);
            await expect(
                zkPayment.privateTransfer(dummyA, dummyB, dummyC, signals2, MEMO)
            ).to.be.revertedWith("Nullifier already used");
        });

        it("reverts when verifier returns false", async function () {
            await transferVerifier.setShouldVerify(false);
            const signals = transferSignals(C_DEPOSIT, NULLIFIER_TRANSFER, C_NEW_SELF, C_RECIPIENT);
            await expect(
                zkPayment.privateTransfer(dummyA, dummyB, dummyC, signals, MEMO)
            ).to.be.revertedWith("Invalid ZK proof");
        });

        it("reverts when newSelfCommitment == 0", async function () {
            const signals = transferSignals(C_DEPOSIT, NULLIFIER_TRANSFER, 0n, C_RECIPIENT);
            await expect(
                zkPayment.privateTransfer(dummyA, dummyB, dummyC, signals, MEMO)
            ).to.be.revertedWith("Invalid commitment");
        });

        it("reverts when new commitments collide", async function () {
            const signals = transferSignals(C_DEPOSIT, NULLIFIER_TRANSFER, C_NEW_SELF, C_NEW_SELF);
            await expect(
                zkPayment.privateTransfer(dummyA, dummyB, dummyC, signals, MEMO)
            ).to.be.revertedWith("Commitments must differ");
        });

        it("reverts when newSelfCommitment already exists", async function () {
            await zkPayment.connect(user).deposit(C_NEW_SELF, { value: ethers.parseEther("0.1") });
            const signals = transferSignals(C_DEPOSIT, NULLIFIER_TRANSFER, C_NEW_SELF, C_RECIPIENT);
            await expect(
                zkPayment.privateTransfer(dummyA, dummyB, dummyC, signals, MEMO)
            ).to.be.revertedWith("New commitment already exists");
        });

        it("pool balance unchanged after transfer (zero-sum)", async function () {
            const depositValue = ethers.parseEther("0.5");
            const before = await zkPayment.getContractBalance();
            const signals = transferSignals(C_DEPOSIT, NULLIFIER_TRANSFER, C_NEW_SELF, C_RECIPIENT);
            await zkPayment.privateTransfer(dummyA, dummyB, dummyC, signals, MEMO);
            const after = await zkPayment.getContractBalance();
            expect(after).to.equal(before);
            expect(after).to.equal(depositValue);
        });
    });

    describe("withdraw ( AC-6..8)", function () {
        const DEPOSIT_AMOUNT = ethers.parseEther("0.5");
        const WITHDRAW_AMOUNT = ethers.parseEther("0.3");

        beforeEach(async function () {
            await zkPayment.connect(user).deposit(C_DEPOSIT, { value: DEPOSIT_AMOUNT });
        });

        it("transfers MATIC to recipient + burns commitment + marks nullifier", async function () {
            const recipientBalanceBefore = await ethers.provider.getBalance(recipient.address);
            const signals = withdrawSignals(C_DEPOSIT, NULLIFIER_WITHDRAW, recipient.address, WITHDRAW_AMOUNT);
            const tx = await zkPayment.withdraw(dummyA, dummyB, dummyC, signals);
            const receipt = await tx.wait();

            // MATIC ditransfer
            const recipientBalanceAfter = await ethers.provider.getBalance(recipient.address);
            expect(recipientBalanceAfter - recipientBalanceBefore).to.equal(WITHDRAW_AMOUNT);

            // Commitment burned
            expect(await zkPayment.isCommitmentActive(C_DEPOSIT)).to.be.false;
            // Nullifier marked
            expect(await zkPayment.isNullifierUsed(NULLIFIER_WITHDRAW)).to.be.true;
            // totalWithdrawn updated
            expect(await zkPayment.totalWithdrawn()).to.equal(WITHDRAW_AMOUNT);
            // Pool dikurangi
            expect(await zkPayment.getContractBalance()).to.equal(DEPOSIT_AMOUNT - WITHDRAW_AMOUNT);

            // Event
            const event = receipt.logs.find(l => l.fragment && l.fragment.name === "Withdraw");
            expect(event.args.nullifier).to.equal(NULLIFIER_WITHDRAW);
            expect(event.args.recipient).to.equal(recipient.address);
            expect(event.args.amount).to.equal(WITHDRAW_AMOUNT);
        });

        it("reverts when commitment not active", async function () {
            const signals = withdrawSignals(0x9999n, NULLIFIER_WITHDRAW, recipient.address, WITHDRAW_AMOUNT);
            await expect(
                zkPayment.withdraw(dummyA, dummyB, dummyC, signals)
            ).to.be.revertedWith("Commitment not active");
        });

        it("reverts when nullifier reused", async function () {
            const signals1 = withdrawSignals(C_DEPOSIT, NULLIFIER_WITHDRAW, recipient.address, WITHDRAW_AMOUNT);
            await zkPayment.withdraw(dummyA, dummyB, dummyC, signals1);

            // Bikin deposit baru, coba pakai nullifier yang sama
            await zkPayment.connect(user).deposit(0x4444n, { value: ethers.parseEther("0.1") });
            const signals2 = withdrawSignals(0x4444n, NULLIFIER_WITHDRAW, recipient.address, ethers.parseEther("0.05"));
            await expect(
                zkPayment.withdraw(dummyA, dummyB, dummyC, signals2)
            ).to.be.revertedWith("Nullifier already used");
        });

        it("reverts when verifier returns false", async function () {
            await withdrawVerifier.setShouldVerify(false);
            const signals = withdrawSignals(C_DEPOSIT, NULLIFIER_WITHDRAW, recipient.address, WITHDRAW_AMOUNT);
            await expect(
                zkPayment.withdraw(dummyA, dummyB, dummyC, signals)
            ).to.be.revertedWith("Invalid ZK proof");
        });

        it("reverts when recipient is zero address", async function () {
            const signals = withdrawSignals(C_DEPOSIT, NULLIFIER_WITHDRAW, ethers.ZeroAddress, WITHDRAW_AMOUNT);
            await expect(
                zkPayment.withdraw(dummyA, dummyB, dummyC, signals)
            ).to.be.revertedWith("Invalid recipient");
        });

        it("reverts when amount == 0", async function () {
            const signals = withdrawSignals(C_DEPOSIT, NULLIFIER_WITHDRAW, recipient.address, 0);
            await expect(
                zkPayment.withdraw(dummyA, dummyB, dummyC, signals)
            ).to.be.revertedWith("Amount must be greater than 0");
        });

        it("reverts when withdraw amount exceeds pool balance", async function () {
            const tooMuch = DEPOSIT_AMOUNT + ethers.parseEther("1");
            const signals = withdrawSignals(C_DEPOSIT, NULLIFIER_WITHDRAW, recipient.address, tooMuch);
            await expect(
                zkPayment.withdraw(dummyA, dummyB, dummyC, signals)
            ).to.be.revertedWith("Insufficient pool balance");
        });

        it("invariant: pool == totalDeposited - totalWithdrawn after lifecycle", async function () {
            const signals = withdrawSignals(C_DEPOSIT, NULLIFIER_WITHDRAW, recipient.address, WITHDRAW_AMOUNT);
            await zkPayment.withdraw(dummyA, dummyB, dummyC, signals);

            const poolBalance = await zkPayment.getContractBalance();
            const deposited = await zkPayment.totalDeposited();
            const withdrawn = await zkPayment.totalWithdrawn();
            expect(poolBalance).to.equal(deposited - withdrawn);
        });
    });

    describe("verifyBalanceProof (view function)", function () {
        it("returns true when balance verifier mock returns true", async function () {
            const pubSignals = ["0x" + "11".repeat(32), "0x" + "22".repeat(32)];
            const result = await zkPayment.verifyBalanceProof(dummyA, dummyB, dummyC, pubSignals);
            expect(result).to.be.true;
        });

        it("returns false when balance verifier mock returns false", async function () {
            await balanceVerifier.setShouldVerify(false);
            const pubSignals = ["0x" + "11".repeat(32), "0x" + "22".repeat(32)];
            const result = await zkPayment.verifyBalanceProof(dummyA, dummyB, dummyC, pubSignals);
            expect(result).to.be.false;
        });
    });

    describe("updateVerifier (owner only)", function () {
        it("owner can update balanceVerifier", async function () {
            const MockVerifier = await ethers.getContractFactory("MockVerifier");
            const newBv = await MockVerifier.deploy(true);
            await newBv.waitForDeployment();

            await zkPayment.updateBalanceVerifier(await newBv.getAddress());
            expect(await zkPayment.balanceVerifier()).to.equal(await newBv.getAddress());
        });

        it("owner can update withdrawVerifier", async function () {
            const MockWithdrawVerifier = await ethers.getContractFactory("MockWithdrawVerifier");
            const newWv = await MockWithdrawVerifier.deploy(true);
            await newWv.waitForDeployment();

            await zkPayment.updateWithdrawVerifier(await newWv.getAddress());
            expect(await zkPayment.withdrawVerifier()).to.equal(await newWv.getAddress());
        });

        it("non-owner cannot update transferVerifier", async function () {
            const MockVerifier = await ethers.getContractFactory("MockVerifier");
            const newTv = await MockVerifier.deploy(true);
            await newTv.waitForDeployment();

            await expect(
                zkPayment.connect(user).updateTransferVerifier(await newTv.getAddress())
            ).to.be.revertedWith("Only owner can call this function");
        });

        it("non-owner cannot update withdrawVerifier", async function () {
            const MockWithdrawVerifier = await ethers.getContractFactory("MockWithdrawVerifier");
            const newWv = await MockWithdrawVerifier.deploy(true);
            await newWv.waitForDeployment();

            await expect(
                zkPayment.connect(user).updateWithdrawVerifier(await newWv.getAddress())
            ).to.be.revertedWith("Only owner can call this function");
        });

        it("rejects zero address for withdrawVerifier update", async function () {
            await expect(
                zkPayment.updateWithdrawVerifier(ethers.ZeroAddress)
            ).to.be.revertedWith("Invalid verifier address");
        });
    });

    describe("emergencyWithdraw", function () {
        it("owner can drain pool in emergency", async function () {
            await zkPayment.connect(user).deposit(C_DEPOSIT, { value: ethers.parseEther("0.5") });
            const ownerBalanceBefore = await ethers.provider.getBalance(owner.address);

            const tx = await zkPayment.emergencyWithdraw();
            const receipt = await tx.wait();
            const gasUsed = receipt.gasUsed * receipt.gasPrice;

            const ownerBalanceAfter = await ethers.provider.getBalance(owner.address);
            const expected = ownerBalanceBefore + ethers.parseEther("0.5") - gasUsed;
            expect(ownerBalanceAfter).to.equal(expected);
            expect(await zkPayment.getContractBalance()).to.equal(0);
        });

        it("non-owner cannot emergencyWithdraw", async function () {
            await expect(
                zkPayment.connect(user).emergencyWithdraw()
            ).to.be.revertedWith("Only owner can call this function");
        });
    });
});
