pragma circom 2.1.6;

include "../node_modules/circomlib/circuits/poseidon.circom";
include "../node_modules/circomlib/circuits/comparators.circom";

/**
 * Private Transfer Circuit (shielded-keypair, pool-compatible).
 *
 * Membelanjakan satu note pool (deposit-shaped) → mint dua note baru:
 *   - kembalian milik pengirim (newSelfCommitment)
 *   - note milik penerima (recipientCommitment)
 * Keduanya deposit-shaped sehingga bisa di-withdraw via withdraw.circom.
 *
 * Private inputs:
 *   amountIn, senderShieldPriv, senderSalt,
 *   transferAmount, changeSalt, recipientShieldPub, recipientSalt
 * Public inputs (urutan == pubSignals kontrak):
 *   senderCommitment, nullifier, newSelfCommitment, recipientCommitment
 */
template PrivateTransfer(n) {
    // Private (witness)
    signal input amountIn;
    signal input senderShieldPriv;
    signal input senderSalt;
    signal input transferAmount;
    signal input changeSalt;
    signal input recipientShieldPub;
    signal input recipientSalt;

    // Public
    signal input senderCommitment;
    signal input nullifier;
    signal input newSelfCommitment;
    signal input recipientCommitment;

    // 1. senderShieldPub = Poseidon(senderShieldPriv)
    component pub = Poseidon(1);
    pub.inputs[0] <== senderShieldPriv;

    // 2. senderCommitment = Poseidon(amountIn, senderShieldPub, senderSalt)  (== note deposit)
    component sc = Poseidon(3);
    sc.inputs[0] <== amountIn;
    sc.inputs[1] <== pub.out;
    sc.inputs[2] <== senderSalt;
    sc.out === senderCommitment;

    // 3. amountIn >= transferAmount
    component ge = GreaterEqThan(n);
    ge.in[0] <== amountIn;
    ge.in[1] <== transferAmount;
    ge.out === 1;

    // 4. change = amountIn - transferAmount; change >= 0
    signal change;
    change <== amountIn - transferAmount;
    component chPos = GreaterEqThan(n);
    chPos.in[0] <== change;
    chPos.in[1] <== 0;
    chPos.out === 1;

    // 5. nullifier = Poseidon(senderShieldPriv, senderCommitment)  (== nullifier withdraw)
    component nu = Poseidon(2);
    nu.inputs[0] <== senderShieldPriv;
    nu.inputs[1] <== senderCommitment;
    nu.out === nullifier;

    // 6. newSelfCommitment = Poseidon(change, senderShieldPub, changeSalt)
    component ns = Poseidon(3);
    ns.inputs[0] <== change;
    ns.inputs[1] <== pub.out;
    ns.inputs[2] <== changeSalt;
    ns.out === newSelfCommitment;

    // 7. recipientCommitment = Poseidon(transferAmount, recipientShieldPub, recipientSalt)
    component rc = Poseidon(3);
    rc.inputs[0] <== transferAmount;
    rc.inputs[1] <== recipientShieldPub;
    rc.inputs[2] <== recipientSalt;
    rc.out === recipientCommitment;
}

component main {public [senderCommitment, nullifier, newSelfCommitment, recipientCommitment]} = PrivateTransfer(64);
