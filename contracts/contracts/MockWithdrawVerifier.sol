// SPDX-License-Identifier: MIT
pragma solidity ^0.8.20;

/**
 * @title MockWithdrawVerifier
 * @dev Mock verifier khusus withdraw circuit.
 *      Public signals: [commitment, nullifier, recipient_uint, amount_wei] = uint[4].
 *      HANYA untuk Hardhat behavioral test — JANGAN deploy ke production.
 * Real WithdrawVerifier akan di-generate snarkjs di .
 */
contract MockWithdrawVerifier {
    bool public shouldVerify;

    constructor(bool initial) {
        shouldVerify = initial;
    }

    function setShouldVerify(bool v) external {
        shouldVerify = v;
    }

    function verifyProof(
        uint[2] calldata,
        uint[2][2] calldata,
        uint[2] calldata,
        uint[4] calldata
    ) external view returns (bool) {
        return shouldVerify;
    }
}
