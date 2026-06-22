# ZK-SNARK Circuits for XevouZK

This directory contains the circom circuits for zero-knowledge proofs used in the XevouZK system.

## 📋 Prerequisites

### 1. Install Circom

```bash
# Install Rust (if not installed)
curl --proto '=https' --tlsv1.2 https://sh.rustup.rs -sSf | sh

# Clone and build circom
git clone https://github.com/iden3/circom.git
cd circom
cargo build --release
cargo install --path circom

# Verify installation
circom --version
```

**Windows Users:**
- Install Rust from https://rustup.rs/
- Build circom using the same commands in PowerShell

### 2. Install Node.js Dependencies

```bash
cd circuits
npm install
```

## 🏗️ Build Process

### Quick Build (All Steps)

```bash
npm run build
```

This will:
1. Compile all circuits
2. Run trusted setup
3. Export verification keys
4. Generate Solidity verifiers

### Manual Step-by-Step Build

#### Step 1: Download Powers of Tau

```bash
# Create directory
mkdir -p ptau

# Download (14 is sufficient for our circuits)
npm run download:ptau
# or manually:
curl -L https://hermez.s3-eu-west-1.amazonaws.com/powersOfTau28_hez_final_14.ptau -o ptau/pot14_final.ptau
```

**Note:** The Powers of Tau ceremony provides the common reference string (CRS) for Groth16. The file `pot14_final.ptau` supports circuits up to 2^14 constraints.

#### Step 2: Compile Circuits

```bash
# Compile individual circuits
npm run compile:balance   # Balance check circuit
npm run compile:transfer  # Private transfer circuit
npm run compile:withdraw  # Withdraw (full-burn) circuit

# Or compile all
npm run compile:all
```

> **Catatan:** autentikasi XevouZK memakai **Schnorr signature** (secp256k1,
> diverifikasi server-side di Laravel), **bukan** sebuah circuit. Karena itu
> tidak ada `auth_proof.circom` — hanya tiga circuit Groth16 di atas yang aktif.

Output files (in `build/<circuit_name>/`):
- `<circuit>.r1cs` - Rank-1 Constraint System
- `<circuit>.sym` - Debug symbols
- `<circuit>_js/<circuit>.wasm` - WebAssembly for proof generation

#### Step 3: Trusted Setup (Groth16)

```bash
# Run setup for each circuit
npm run setup:balance
npm run setup:transfer
npm run setup:withdraw

# Or all at once
npm run setup:all
```

Setiap target memanggil `node scripts/setup.js <circuit_name>`, yang melakukan:
1. Phase 1: `newZKey(r1cs, pot14_final.ptau, circuit_0000.zkey)` — bind circuit ke SRS universal.
2. Phase 2: `contribute(...)` — kontribusi entropi **single-party** (prototipe TA; production butuh multi-party).
3. Export verification key + verifikasi zkey terhadap R1CS.

Output files (in `keys/`):
- `<circuit>_final.zkey` - Proving key
- `<circuit>_verification_key.json` - Verification key

#### Step 4: Export to Application

```bash
# Export verification keys to Laravel storage
npm run export:keys

# Generate Solidity verifier contracts
npm run export:verifiers
```

Files are copied to:
- `../storage/app/zk-keys/` - For server-side verification
- `../public/zk/` - For client-side proof generation
- `../contracts/contracts/verifiers/` - Solidity contracts

## 📁 Directory Structure

```
circuits/
├── balance_check.circom      # Balance verification circuit
├── private_transfer.circom   # Private transfer circuit
├── withdraw.circom           # Withdraw (full-burn) circuit
├── package.json              # Build scripts
├── README.md                 # This file
├── ptau/                     # Powers of Tau files
│   └── pot14_final.ptau      # ≈ 19 MB (mendukung ≤ 2^14 constraint)
├── build/                    # Compiled circuits
│   ├── balance_check/
│   ├── private_transfer/
│   └── withdraw/
│       ├── withdraw.r1cs
│       ├── withdraw.sym
│       └── withdraw_js/
│           └── withdraw.wasm
├── keys/                     # Generated keys
│   ├── balance_check_final.zkey
│   ├── balance_check_verification_key.json
│   ├── private_transfer_final.zkey
│   ├── private_transfer_verification_key.json
│   ├── withdraw_final.zkey
│   └── withdraw_verification_key.json
└── scripts/                  # Build scripts
    ├── setup.js              # Phase 2 trusted setup per circuit
    ├── export-keys.js        # Salin vkey JSON → storage/app/zk-keys
    ├── export-verifiers.js   # Generate Solidity verifier → contracts/
    └── test-proofs.js        # Local generate + verify (incl. negative cases)
```

## 🔐 Circuits Overview

### balance_check.circom

**Purpose:** Prove balance ≥ amount without revealing actual balance

**Private Inputs:**
- `balance` - Actual balance
- `salt` - Commitment randomness

**Public Inputs:**
- `minAmount` - Required minimum amount
- `balanceCommitment` - Poseidon(balance, salt)

**Constraints:** ~520 non-linear

> Pada alur live, sub-check `balance ≥ amount` sudah inline di `private_transfer`.
> `balance_check` di-deploy sebagai opsi extension (mis. proof-of-solvency).

### private_transfer.circom

**Purpose:** Spend one pool note → mint two deposit-shaped notes (change for the sender + a note for the recipient), with ownership and balance verified in zero knowledge. Model: shielded-keypair (`shieldPub = Poseidon(shieldPriv)`).

**Private Inputs:**
- `amountIn` - Value of the note being spent
- `senderShieldPriv` - Sender's shield private key (ownership + nullifier material)
- `senderSalt` - Salt of the spent note
- `transferAmount` - Amount sent to the recipient
- `changeSalt` - Salt for the new change note
- `recipientShieldPub` - Recipient's shield public key
- `recipientSalt` - Salt for the recipient note

**Public Inputs:**
- `senderCommitment` - Commitment of the note being spent (burned)
- `nullifier` - `Poseidon(senderShieldPriv, senderCommitment)`; prevents double-spend
- `newSelfCommitment` - Change note minted back to the sender
- `recipientCommitment` - Note minted for the recipient

**Commitment scheme (uniform, 3-input):** every commitment is `Poseidon(amount, shieldPub, salt)`, so the recipient note is deposit-shaped and directly withdrawable via `withdraw.circom` — no conversion/re-mint needed.

**Constraints:** non-linear count — verify via `npm run compile:transfer` then `snarkjs r1cs info build/private_transfer/private_transfer.r1cs` (the old "~860" figure predates the shielded-keypair rewrite).

### withdraw.circom

**Purpose:** Prove ownership of a note and withdraw its full value (full-burn) to a public recipient

**Private Inputs:**
- `shieldPriv` - Shield private key (ownership + nullifier material)
- `salt` - Note salt

**Public Inputs:**
- `commitment` - `Poseidon(amount, shieldPub, salt)`, `shieldPub = Poseidon(shieldPriv)`
- `nullifier` - `Poseidon(shieldPriv, commitment)`, mencegah re-withdraw
- `recipient` - Alamat penerima; di-bind via `recipientBound <== recipient * 1` (frontrun-resistant, mencegah optimize-out) — **tanpa** range-check
- `amount` - **PUBLIC**; nilai note penuh yang ditarik (full-burn, tidak ada partial)

> Tidak ada signal `balance` terpisah — nilai note **adalah** `amount` yang terikat di commitment. `withdraw.circom` **tidak** memakai gadget `GreaterEqThan`/range `n=248` (klaim itu keliru pada versi README lama).

**Constraints:** non-linear count — verify via `npm run compile:withdraw` then `snarkjs r1cs info build/withdraw/withdraw.r1cs`.

## 🧪 Testing

```bash
# Run proof generation and verification tests
npm run test
```

This generates test proofs and verifies them using the generated keys.

## 🔒 Production Security Considerations

### Trusted Setup Ceremony

For production, the trusted setup should involve multiple independent parties:

```bash
# Initial setup
snarkjs zkey new circuit.r1cs pot14_final.ptau circuit_0000.zkey

# Contribution 1 (Party A)
snarkjs zkey contribute circuit_0000.zkey circuit_0001.zkey --name="Party A" -v

# Contribution 2 (Party B)
snarkjs zkey contribute circuit_0001.zkey circuit_0002.zkey --name="Party B" -v

# ... more contributions

# Final beacon (public randomness)
snarkjs zkey beacon circuit_000N.zkey circuit_final.zkey 0102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f 10 -n="Final Beacon"

# Verify final zkey
snarkjs zkey verify circuit.r1cs pot14_final.ptau circuit_final.zkey
```

### Audit Checklist

- [ ] Multiple trusted setup contributors
- [ ] Circuit code audited
- [ ] Verification key matches deployed contract
- [ ] Nullifier storage properly implemented
- [ ] No secret leakage in public signals

## 🛠️ Troubleshooting

### "Cannot find module 'circomlib'"

```bash
npm install circomlib
```

### "Powers of Tau file not found"

```bash
npm run download:ptau
```

### "Constraint too large"

Use a larger Powers of Tau file:
```bash
curl -L https://hermez.s3-eu-west-1.amazonaws.com/powersOfTau28_hez_final_16.ptau -o ptau/pot16_final.ptau
```

### "WebAssembly memory error"

Increase Node.js memory:
```bash
NODE_OPTIONS=--max-old-space-size=8192 npm run compile:all
```

## 📚 Resources

- [Circom Documentation](https://docs.circom.io/)
- [snarkjs GitHub](https://github.com/iden3/snarkjs)
- [circomlib (circuit library)](https://github.com/iden3/circomlib)
- [ZK-SNARK Tutorial](https://blog.iden3.io/first-zk-proof.html)
- [Groth16 Paper](https://eprint.iacr.org/2016/260.pdf)

## 📝 License

MIT License - See main project LICENSE file
