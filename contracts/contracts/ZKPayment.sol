// SPDX-License-Identifier: MIT
pragma solidity ^0.8.20;

import "./verifiers/BalanceCheckVerifier.sol";
import "./verifiers/PrivateTransferVerifier.sol";
import "./verifiers/WithdrawVerifier.sol";

/**
 * @title ZKPayment v2 (true on-chain settlement)
 * @dev Commitment pool ZK-SNARK Groth16 dengan deposit/transfer/withdraw lifecycle.
 *
 * Perubahan dari v1 (..007)
 * - HAPUS `mapping(address => uint256) commitments` (account-based)
 * - TAMBAH `mapping(uint256 => bool) activeCommitments` (note-based, Tornado-light)
 * - TAMBAH withdrawVerifier ke-3 + fungsi `withdraw` yang transfer MATIC sungguhan
 * - `privateTransfer` refactor jadi burn-old + mint-two-new commitments (sender change + recipient)
 * - `deposit(commitment)` payable sekarang aktifkan commitment + track totalDeposited
 *
 * Privacy posture (lihat §3.3)
 * - Transfer privat antar user: amount, sender, recipient TERSEMBUNYI (hanya commitment+nullifier hash di event)
 * - Deposit & withdraw individu: publik (EOA + amount tampak — sifat ujung "fiat ramp")
 * - Tidak ada link langsung antara deposit dan withdraw via on-chain data
 */
contract ZKPayment {
    BalanceCheckVerifier public balanceVerifier;
    PrivateTransferVerifier public transferVerifier;
    WithdrawVerifier public withdrawVerifier;

    address public owner;

    // Note pool: commitment → active/burned status
    mapping(uint256 => bool) public activeCommitments;
    mapping(bytes32 => bool) public nullifiers;

    uint256 public transactionCount;
    uint256 public totalDeposited;
    uint256 public totalWithdrawn;

    event Deposit(address indexed user, uint256 indexed commitment, uint256 amount);
    event PrivateTransfer(
        bytes32 indexed nullifier,
        uint256 oldCommitment,
        uint256 newSelfCommitment,
        uint256 recipientCommitment
    );
    event EncryptedNote(uint256 indexed recipientCommitment, bytes memo);
    event Withdraw(
        bytes32 indexed nullifier,
        address indexed recipient,
        uint256 amount
    );
    event VerifierUpdated(string which, address newAddress);

    modifier onlyOwner() {
        require(msg.sender == owner, "Only owner can call this function");
        _;
    }

    constructor(
        address _balanceVerifier,
        address _transferVerifier,
        address _withdrawVerifier
    ) {
        require(_balanceVerifier != address(0), "Invalid balance verifier");
        require(_transferVerifier != address(0), "Invalid transfer verifier");
        require(_withdrawVerifier != address(0), "Invalid withdraw verifier");
        owner = msg.sender;
        balanceVerifier = BalanceCheckVerifier(_balanceVerifier);
        transferVerifier = PrivateTransferVerifier(_transferVerifier);
        withdrawVerifier = WithdrawVerifier(_withdrawVerifier);
    }

    /**
     * @dev User deposit MATIC ke pool. Commitment harus unik + non-zero.
     */
    function deposit(uint256 commitment) external payable {
        require(msg.value > 0, "Deposit amount must be greater than 0");
        require(commitment > 0, "Invalid commitment");
        require(!activeCommitments[commitment], "Commitment already active");

        activeCommitments[commitment] = true;
        totalDeposited += msg.value;

        emit Deposit(msg.sender, commitment, msg.value);
    }

    /**
     * @dev View-only balance proof verification (, unchanged).
     */
    function verifyBalanceProof(
        uint256[2] calldata a,
        uint256[2][2] calldata b,
        uint256[2] calldata c,
        uint256[2] calldata pubSignals
    ) external view returns (bool) {
        return balanceVerifier.verifyProof(a, b, c, pubSignals);
    }

    /**
     * @dev Private transfer: burn old commitment, mint dua new commitments
     * (sender's change + recipient's note). Zero-sum dalam pool.
     *
     * pubSignals = [oldCommitment, nullifier, newSelfCommitment, recipientCommitment]
     */
    function privateTransfer(
        uint256[2] calldata a,
        uint256[2][2] calldata b,
        uint256[2] calldata c,
        uint256[4] calldata pubSignals,
        bytes calldata encryptedNote
    ) external {
        uint256 oldCommitment = pubSignals[0];
        bytes32 nullifier = bytes32(pubSignals[1]);
        uint256 newSelfCommitment = pubSignals[2];
        uint256 recipientCommitment = pubSignals[3];

        require(activeCommitments[oldCommitment], "Old commitment not active");
        require(!nullifiers[nullifier], "Nullifier already used");
        require(newSelfCommitment > 0, "Invalid commitment");
        require(recipientCommitment > 0, "Invalid commitment");
        require(newSelfCommitment != recipientCommitment, "Commitments must differ");
        require(!activeCommitments[newSelfCommitment], "New commitment already exists");
        require(!activeCommitments[recipientCommitment], "Recipient commitment already exists");
        require(transferVerifier.verifyProof(a, b, c, pubSignals), "Invalid ZK proof");

        nullifiers[nullifier] = true;
        activeCommitments[oldCommitment] = false;
        activeCommitments[newSelfCommitment] = true;
        activeCommitments[recipientCommitment] = true;
        transactionCount++;

        emit PrivateTransfer(nullifier, oldCommitment, newSelfCommitment, recipientCommitment);
        emit EncryptedNote(recipientCommitment, encryptedNote);
    }

    /**
     * @dev Withdraw MATIC dari pool ke EOA recipient. Burn commitment, mark nullifier.
     *
     * pubSignals = [commitment, nullifier, recipient_uint, amount_wei]
     * - recipient_uint = uint256(uint160(recipient_address))
     * - amount_wei harus > 0 dan <= pool balance
     */
    function withdraw(
        uint256[2] calldata a,
        uint256[2][2] calldata b,
        uint256[2] calldata c,
        uint256[4] calldata pubSignals
    ) external {
        uint256 commitment = pubSignals[0];
        bytes32 nullifier = bytes32(pubSignals[1]);
        address recipient = address(uint160(pubSignals[2]));
        uint256 amount = pubSignals[3];

        require(activeCommitments[commitment], "Commitment not active");
        require(!nullifiers[nullifier], "Nullifier already used");
        require(recipient != address(0), "Invalid recipient");
        require(amount > 0, "Amount must be greater than 0");
        require(address(this).balance >= amount, "Insufficient pool balance");
        require(withdrawVerifier.verifyProof(a, b, c, pubSignals), "Invalid ZK proof");

        nullifiers[nullifier] = true;
        activeCommitments[commitment] = false;
        totalWithdrawn += amount;

        (bool ok, ) = recipient.call{value: amount}("");
        require(ok, "MATIC transfer failed");

        emit Withdraw(nullifier, recipient, amount);
    }

    function isCommitmentActive(uint256 commitment) external view returns (bool) {
        return activeCommitments[commitment];
    }

    function isNullifierUsed(bytes32 nullifier) external view returns (bool) {
        return nullifiers[nullifier];
    }

    function getContractBalance() external view returns (uint256) {
        return address(this).balance;
    }

    function updateBalanceVerifier(address _balanceVerifier) external onlyOwner {
        require(_balanceVerifier != address(0), "Invalid verifier address");
        balanceVerifier = BalanceCheckVerifier(_balanceVerifier);
        emit VerifierUpdated("balance", _balanceVerifier);
    }

    function updateTransferVerifier(address _transferVerifier) external onlyOwner {
        require(_transferVerifier != address(0), "Invalid verifier address");
        transferVerifier = PrivateTransferVerifier(_transferVerifier);
        emit VerifierUpdated("transfer", _transferVerifier);
    }

    function updateWithdrawVerifier(address _withdrawVerifier) external onlyOwner {
        require(_withdrawVerifier != address(0), "Invalid verifier address");
        withdrawVerifier = WithdrawVerifier(_withdrawVerifier);
        emit VerifierUpdated("withdraw", _withdrawVerifier);
    }

    function emergencyWithdraw() external onlyOwner {
        payable(owner).transfer(address(this).balance);
    }

    receive() external payable {
        // Direct send tanpa commitment dianggap "donation" — tidak buat note,
        // tapi tetap nambah pool balance. Tidak emit Deposit (no commitment).
        totalDeposited += msg.value;
    }
}
