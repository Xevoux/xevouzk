// SPDX-License-Identifier: MIT
pragma solidity ^0.8.20;

/**
 * @title MockVerifier
 * @dev Verifier yang return nilai yang bisa di-set untuk testing ZKPayment.
 *      Punya dua overload verifyProof:
 *        - uint[2] pubSignals (untuk balanceVerifier)
 *        - uint[4] pubSignals (untuk transferVerifier)
 *      HANYA untuk Hardhat behavioral test — JANGAN deploy ke production.
 */
contract MockVerifier {
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
        uint[2] calldata
    ) external view returns (bool) {
        return shouldVerify;
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
