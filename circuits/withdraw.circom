pragma circom 2.1.6;

include "../node_modules/circomlib/circuits/poseidon.circom";

/**
 * Withdraw Circuit — (model shielded-keypair).
 *
 * Membuktikan tanpa reveal shieldPriv/salt bahwa
 * 1. Pemilik tahu shieldPriv: shieldPub = Poseidon(shieldPriv)
 * 2. Note valid: commitment = Poseidon(amount, shieldPub, salt)
 * 3. Nullifier benar: nullifier = Poseidon(shieldPriv, commitment)
 *
 * recipient + amount publik (ujung "fiat ramp" transparan).
 *
 * Private inputs: shieldPriv, salt
 * Public inputs: commitment, nullifier, recipient, amount
 */
template Withdraw() {
    // Private
    signal input shieldPriv;
    signal input salt;

    // Public
    signal input commitment;
    signal input nullifier;
    signal input recipient;
    signal input amount;

    // 1. shieldPub = Poseidon(shieldPriv)
    component pubHasher = Poseidon(1);
    pubHasher.inputs[0] <== shieldPriv;

    // 2. commitment = Poseidon(amount, shieldPub, salt)
    component commitHasher = Poseidon(3);
    commitHasher.inputs[0] <== amount;
    commitHasher.inputs[1] <== pubHasher.out;
    commitHasher.inputs[2] <== salt;
    commitHasher.out === commitment;

    // 3. nullifier = Poseidon(shieldPriv, commitment)
    component nullHasher = Poseidon(2);
    nullHasher.inputs[0] <== shieldPriv;
    nullHasher.inputs[1] <== commitment;
    nullHasher.out === nullifier;

    // 4. recipient passthrough (frontrun-resistant; cegah optimize-out)
    signal recipientBound;
    recipientBound <== recipient * 1;
}

component main {public [commitment, nullifier, recipient, amount]} = Withdraw();
