// SPDX-License-Identifier: MIT
pragma solidity ^0.8.20;

/**
 * Setelah trusted setup selesai, Groth16Verifier.sol bukan lagi
 * file VK custom — kita pakai langsung verifier yang di-generate snarkjs.
 *
 * File ini hanya re-export untuk konsistensi import path lama. Logika real
 * verifier ada di:
 *   - contracts/verifiers/BalanceCheckVerifier.sol     (snarkjs-generated)
 *   - contracts/verifiers/PrivateTransferVerifier.sol  (snarkjs-generated)
 *
 * Auth verifier sudah dihapus (Schnorr replace Groth16 auth).
 */

import "./verifiers/BalanceCheckVerifier.sol";
import "./verifiers/PrivateTransferVerifier.sol";
