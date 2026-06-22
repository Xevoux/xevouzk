pragma circom 2.1.6;

include "../node_modules/circomlib/circuits/comparators.circom";
include "../node_modules/circomlib/circuits/poseidon.circom";

/**
 * Balance Check Circuit
 * Proves that balance >= amount without revealing actual balance
 *
 * This circuit allows a user to prove they have sufficient funds
 * for a transaction without revealing their actual balance.
 *
 * Private inputs
 * - balance: actual balance (kept private)
 * - salt: random value for commitment
 *
 * Public inputs
 * - minAmount: minimum required amount
 * - balanceCommitment: Poseidon commitment of balance
 */
template BalanceCheck(n) {
    // Private inputs (witness)
    signal input balance;
    signal input salt;
    
    // Public inputs
    signal input minAmount;
    signal input balanceCommitment;
    
    // 1. Verify balance commitment
    // commitment = Poseidon(balance, salt)
    component commitmentHasher = Poseidon(2);
    commitmentHasher.inputs[0] <== balance;
    commitmentHasher.inputs[1] <== salt;
    commitmentHasher.out === balanceCommitment;
    
    // 2. Check that balance >= minAmount
    component gte = GreaterEqThan(n);
    gte.in[0] <== balance;
    gte.in[1] <== minAmount;
    gte.out === 1;
}

// Using 64-bit comparison for balance values up to ~18 quintillion
component main {public [minAmount, balanceCommitment]} = BalanceCheck(64);
