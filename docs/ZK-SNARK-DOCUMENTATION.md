# Dokumentasi ZK & Kriptografi pada XevouZK

> **Audiens**: penulis TA, pembimbing, penguji panel, dan reviewer code yang ingin
> memahami klaim privasi XevouZK end-to-end.
>
> **Status**: snapshot 2026-06-22, sesuai implementasi aktual.
> Dokumen sebelumnya (versi commitment-based "ZK Login" dengan
> `deterministicHash` custom) telah di-deprecate dan diganti oleh versi ini.
> Update 2026-06-22: (1) backup note terenkripsi lintas-device (server simpan
> ciphertext opaque) + account-guard (verifikasi password sisi-klien) → threat
> model §9.3 & code reference §11; (2) penemuan dana masuk lewat proxy server
> `/payment/scan-rpc` (kunci API tetap di server, decrypt di klien); (3) suite
> **PHPUnit dihapus** dari repo — bukti TA via Hardhat + E2E on-chain + snarkjs
> (harness vektor interop Schnorr belum ada — §3.4).
> Koreksi 2026-06-19: **§5 (desain circuit) diselaraskan dengan source `.circom` aktual** —
> skema commitment terpadu `Poseidon(amount, shieldPub, salt)` (3-input), naming
> `shieldPriv/shieldPub` (bukan `secret`/`recipientAddress`), `newSelfCommitment`
> (bukan `newBalanceCommitment`), dan **penghapusan klaim range `n=248` di withdraw**
> yang tidak ada di `withdraw.circom`. Tabel I/O, 7 sub-konstrain `private_transfer`,
> §5.3 withdraw, sequence §8.5–8.6, dan glossary ikut dikoreksi.
> Update 2026-06-13: login kini **murni Schnorr** — `Auth::attempt` dihapus,
> password tak pernah dikirim (login & register), `users.password` = hash acak
> placeholder; ditambah single-use replay-nonce + rate limiting. Lihat §8.2 & §9.
> Update 2026-06-05: modul JS yang meng-`import` library kini di-**bundle Vite**
> dari `node_modules` dan berada di `resources/js/` (bukan lagi `public/js/` +
> `public/vendor`). Lihat §11.2.
>
> **Ruang lingkup**: konsolidasi protokol Schnorr (autentikasi), Groth16 zk-SNARK
> (saldo, transfer privat, withdraw), desain circuit, trusted setup, smart
> contract `ZKPayment`, dan threat model. Glossary di akhir.

---

## Daftar Isi

1. [Pengenalan Zero-Knowledge Proof](#1-pengenalan-zero-knowledge-proof)
2. [Dua primitif ZKP di XevouZK](#2-dua-primitif-zkp-di-xevouzk)
3. [Layer 1 — Autentikasi Schnorr](#3-layer-1--autentikasi-schnorr)
4. [Layer 2 — Groth16 zk-SNARK](#4-layer-2--groth16-zk-snark)
5. [Desain Circuit](#5-desain-circuit)
6. [Trusted Setup Ceremony](#6-trusted-setup-ceremony)
7. [Smart Contract ZKPayment v2](#7-smart-contract-zkpayment-v2)
8. [Alur End-to-End](#8-alur-end-to-end)
9. [Threat Model](#9-threat-model)
10. [Privacy Posture (klaim TA)](#10-privacy-posture-klaim-ta)
11. [Code Reference](#11-code-reference)
12. [Glossary](#12-glossary)

---

## 1. Pengenalan Zero-Knowledge Proof

### 1.1 Definisi

**Zero-Knowledge Proof (ZKP)** adalah protokol kriptografi yang memungkinkan satu
pihak (*prover*) meyakinkan pihak lain (*verifier*) bahwa suatu pernyataan benar,
**tanpa mengungkap informasi apa pun** di luar kebenaran pernyataan tersebut.

Tiga properti yang harus dipenuhi:

| Properti | Deskripsi |
|---|---|
| **Completeness** | Jika pernyataan benar dan prover jujur, verifier yang jujur akan menerima proof. |
| **Soundness** | Jika pernyataan salah, prover curang **tidak bisa** menyakinkan verifier (kecuali dengan probabilitas yang dapat diabaikan). |
| **Zero-Knowledge** | Verifier tidak mendapat informasi apa pun selain bit "pernyataan benar". Tidak ada cara mengekstrak witness dari proof. |

### 1.2 Analogi "Ali Baba Cave"

Gua dengan dua pintu A dan B yang dipisahkan pintu rahasia berkata-sandi.
Prover (yang tahu sandi) bisa selalu keluar dari pintu yang diminta verifier.
Setelah banyak ronde, verifier yakin prover tahu sandi — tanpa pernah
melihat sandinya. Probabilitas curang menurun eksponensial dengan jumlah ronde.

ZK-SNARK adalah versi non-interaktif: prover hanya butuh **satu pesan** untuk
meyakinkan verifier.

### 1.3 Kenapa ZKP relevan untuk pembayaran digital

Pembayaran tradisional mengirim plaintext (nominal, alamat penerima, saldo). ZKP
memungkinkan **verifikasi** properti penting (saldo cukup, transaksi sah, tidak
double-spend) tanpa membocorkan datanya. XevouZK menggunakan ini untuk:

- Login tanpa mengirim password (Schnorr signature, §3).
- Membuktikan saldo cukup tanpa reveal saldo (Groth16 `balance_check`, §5.1).
- Transfer dengan nominal & penerima tersembunyi (Groth16 `private_transfer`, §5.2).
- Withdraw note dari pool tanpa reveal asal note (Groth16 `withdraw`, §5.3).

---

## 2. Dua primitif ZKP di XevouZK

XevouZK **tidak** memakai satu protokol untuk semua. Untuk efisiensi dan
ergonomi, autentikasi pakai Schnorr (cepat, ringan, server-side verify),
sedangkan saldo + transaksi pakai Groth16 (succinct, on-chain verifiable).

| Aspek | Schnorr (auth) | Groth16 (payment) |
|---|---|---|
| Tipe | Signature scheme (interactive→Fiat-Shamir) | zk-SNARK |
| Kurva | secp256k1 | BN128 (alt_bn128 EIP-197) |
| Hash transcript | SHA-256 | Poseidon (zk-friendly) |
| Ukuran proof | 130 hex char (≈65 byte) | 3 group elements (≈192 byte) |
| Verifikasi di | Server Laravel (`SchnorrService`) | Smart contract Solidity (`Groth16Verifier`) |
| Trusted setup? | ❌ Tidak butuh | ✅ Powers of Tau `pot14` + Phase 2 |
| Witness | private key Schnorr | shieldPriv, salt, amount (+ commitment publik) |
| Use case | login, anti-replay session binding | saldo, transfer privat, withdraw |

**Kenapa dua primitif?**

- Schnorr **tidak butuh trusted setup**, jadi cocok untuk fitur yang tidak perlu
  on-chain settlement (login). Tapi Schnorr **tidak menyembunyikan witness yang
  bisa kompleks** — hanya membuktikan kepemilikan private key.
- Groth16 menyembunyikan witness apa pun yang dirumuskan sebagai *circuit*
  (saldo, nominal, identitas), dengan trade-off: butuh trusted setup +
  generate proof lebih lambat (~5–30 detik di browser).

---

## 3. Layer 1 — Autentikasi Schnorr

### 3.1 Skema (Fiat-Shamir variant)

Schnorr signature klasik adalah protokol interaktif 3-langkah (commit, challenge,
response). XevouZK pakai variant **non-interaktif** lewat transformasi
**Fiat-Shamir**: nilai challenge `e` dihasilkan dari hash transcript, bukan dari
verifier. Aman selama hash dimodelkan sebagai random oracle.

**Setup**:

- Kurva: secp256k1, generator G, ordo n.
- Private key: `d ∈ [1, n−1]`.
- Public key: `P = d · G` (compressed point, 33 byte / 66 hex char).

**Sign(d, msg)** — implementasi `SchnorrService::sign`:

```
k          = SHA-256(privBytes || msg) mod n        # deterministic nonce
R          = k · G                                   # commitment point
rHex       = encode_compressed(R)                    # 66 hex
e          = SHA-256(rHex || pubHex || msg) mod n    # Fiat-Shamir challenge
s          = (k + e · d) mod n
signature  = rHex || pad64(s)                        # 130 hex total
```

**Verify(P, msg, sig)**:

```
parse rHex (66), sHex (64) from sig
R = decode_compressed(rHex)
e = SHA-256(rHex || pubHex || msg) mod n
check  s · G  ==  R + e · P
```

Bukti korektnes:

```
s · G = (k + e · d) · G
      = k · G + e · (d · G)
      = R + e · P  ✓
```

> **Deterministic nonce**: XevouZK memakai `k = SHA-256(priv || msg) mod n` (RFC 6979-lite),
> bukan nonce acak. Trade-off: aman dari nonce-reuse attack, tapi prover berbeda
> dengan witness sama menghasilkan signature byte-identik (informasi linkability
> minor — tidak masalah untuk skenario login XevouZK).

### 3.2 Key derivation dari password

XevouZK adalah **non-custodial**: server tidak boleh punya private key. Solusi:
derive deterministik di browser dari `(email, password)`.

```
schnorr_priv = SHA-256("schnorr_v1:" || lower(email) || ":" || password) mod n
schnorr_pub  = schnorr_priv · G   (compressed)

polygon_priv = SHA-256("polygon_v1:" || lower(email) || ":" || password) mod n
polygon_pub  = polygon_priv · G
polygon_addr = EIP-55(keccak256(uncompressed_pub_x || pub_y)[-20:])
```

**Key separation**: dua label berbeda (`schnorr_v1:` vs `polygon_v1:`) menghasilkan
keypair berbeda. Kompromi Schnorr key tidak otomatis kompromi wallet Polygon dan
sebaliknya. Pola serupa dengan BIP-32 hardened derivation tanpa parent secret
sharing.

**Implikasi**:

- Server hanya menerima `schnorr_pub`, `polygon_pub`, `polygon_addr` saat register
  (semua public artifacts).
- Login pakai Schnorr → password tidak ditransmit untuk autentikasi (hanya untuk
  derive Schnorr key di browser).
- **Konsekuensi non-custodial**: lupa password = kedua keypair hilang permanen.
  Recovery flow (BIP-39 mnemonic) belum diimplementasikan.

### 3.3 Message format dan anti-replay

Server build message yang ditandatangani sebagai:

```
msg = lower(email) || "|" || timestamp_unix_ms || "|" || csrf_token
```

Tiga lapis proteksi:

| Threat | Mitigasi |
|---|---|
| Replay antar-sesi | `csrf_token` rotate per session Laravel |
| Replay dalam-sesi | `timestamp` window 5 menit (server reject jika `\|now − ts\| > 300_000 ms`) |
| Email/akun mismatch | `email` di-bind ke signature; server cek `pub` di DB cocok dengan `lower(email)` |

Server-side verify di `AuthController::login`:

```php
if (!$schnorr->verify($user->zk_public_key, $msg, $request->schnorr_signature)) {
    abort(401);
}
if (abs(now() - $request->schnorr_timestamp) > 300_000) {
    abort(401);
}
Auth::login($user);  // setelah verify Schnorr lulus
```

### 3.4 Interoperabilitas JS↔PHP

Agar login berfungsi, dua implementasi **harus** menghasilkan signature yang sama
untuk witness yang sama. Keduanya ditulis mengikuti algoritma + domain-separation
**identik** (dapat diverifikasi dengan membandingkan [`schnorr-auth.js`](../resources/js/schnorr-auth.js)
dan [`SchnorrService.php`](../app/Services/SchnorrService.php)):

| Komponen | JavaScript (`@noble/curves@1.4`) | PHP (`simplito/elliptic-php`) |
|---|---|---|
| Kurva | `secp256k1` | `EC('secp256k1')` |
| Scalar mod n | `mod(BigInt(hash), CURVE.n)` | `(new BN(hex, 16))->umod($ec->n)` |
| Hash transcript | `sha256()` / `@noble/hashes` | `hash('sha256', ..., true)` |
| Compressed point | `bytesToHex(P.toRawBytes(true))` | `$P->encode('hex', true)` |

Kompatibilitas terbukti secara **fungsional** lewat alur login (browser sign →
server verify). ⚠️ Harness vektor yang membuktikan output **byte-identik** secara
otomatis (`tools/schnorr-interop-vectors.mjs`) **belum ada di repo** — lihat
[TESTING-EVIDENCE §5](TESTING-EVIDENCE.md). Membuatnya = future work.

---

## 4. Layer 2 — Groth16 zk-SNARK

### 4.1 Apa itu Groth16

**Groth16** (Jens Groth, 2016) adalah konstruksi zk-SNARK dengan proof terkecil
dan verifikasi tercepat di antara famili pairing-based SNARK. Properti:

- **Zero-Knowledge** — proof tidak membocorkan witness.
- **Succinct** — proof = 3 elemen grup (~192 byte) **terlepas dari ukuran circuit**.
- **Non-interactive** — satu pesan dari prover.
- **Argument of Knowledge** — prover membuktikan tahu witness yang memenuhi
  circuit, di bawah Knowledge-of-Exponent assumption.

Trade-off: setiap circuit butuh **trusted setup** Phase 2 yang circuit-specific
(toxic waste harus dihancurkan, lihat §6).

### 4.2 Pipeline Groth16

```
Circom (.circom)
   │  compile
   ▼
R1CS (rank-1 constraint system)
   │  + ptau (Powers of Tau, universal SRS)
   │  + Phase 2 contribution (circuit-specific)
   ▼
proving_key (.zkey)  +  verification_key (.json / .sol)
   │
   ├──► Prover: snarkjs.fullProve(witness, wasm, zkey)
   │           → proof (πA, πB, πC) + public signals
   │
   └──► Verifier: snarkjs.verify(vkey, publicSignals, proof)
                  ATAU contract.verifyProof(a, b, c, pubSignals)
                  → bool (3 pairing check)
```

### 4.3 Kurva BN128 & EIP-197 compatibility

XevouZK pakai kurva **alt_bn128** (BN254/BN128) karena:

1. EVM punya precompile native: `0x06` (G1 add), `0x07` (G1 mul), `0x08` (pairing
   check). EIP-197 standardize. Verifikasi on-chain ~250k gas, feasible di Polygon.
2. snarkjs default support BN128.
3. Security level ≈ 100-bit (post Kim-Barbulescu 2016 → dianggap masih cukup untuk
   skema yang tidak menyimpan dana institusional jangka panjang).

Untuk skala mainnet jangka panjang, BLS12-381 lebih aman tapi EVM butuh precompile
yang baru tersedia di Ethereum mainnet via EIP-2537 (di luar scope TA).

### 4.4 Struktur proof Groth16

```javascript
proof = {
    pi_a: [G1.x, G1.y, "1"],            // titik di G1, projective coord
    pi_b: [[G2.x0, G2.x1], [G2.y0, G2.y1], ["1","0"]],  // titik di G2
    pi_c: [G1.x, G1.y, "1"],            // titik di G1
    protocol: "groth16",
    curve: "bn128"
}

publicSignals = [
    "string_decimal_field_element",     // public input 1
    "string_decimal_field_element",     // public input 2
    ...
]
```

Setiap field element adalah angka di `F_p` di mana `p ≈ 2^254`.
`ZKSNARKService::verifyProof` melakukan **struct validation** (range check `[0, p)`
+ format) sebelum delegasi ke smart contract untuk pairing check yang sebenarnya.

---

## 5. Desain Circuit

XevouZK punya **3 circuit Circom 2.1.6**, semua di [`circuits/`](../circuits/).
Tabel ringkas:

| Circuit | File | Public inputs | Private inputs | Output utama |
|---|---|---|---|---|
| balance_check | [`balance_check.circom`](../circuits/balance_check.circom) | `minAmount`, `balanceCommitment` | `balance`, `salt` | Bukti `balance ≥ minAmount` |
| private_transfer | [`private_transfer.circom`](../circuits/private_transfer.circom) | `senderCommitment`, `nullifier`, `newSelfCommitment`, `recipientCommitment` | `amountIn`, `senderShieldPriv`, `senderSalt`, `transferAmount`, `changeSalt`, `recipientShieldPub`, `recipientSalt` | Bukti transfer sah |
| withdraw | [`withdraw.circom`](../circuits/withdraw.circom) | `commitment`, `nullifier`, `recipient`, `amount` | `shieldPriv`, `salt` | Bukti pemilik note + full-burn |

### 5.1 `balance_check.circom`

**Klaim**: prover tahu `balance` dan `salt` sehingga:

1. `Poseidon(balance, salt) == balanceCommitment` (commitment opening)
2. `balance ≥ minAmount` (range comparison)

Constraint:

```circom
component commitmentHasher = Poseidon(2);
commitmentHasher.inputs[0] <== balance;
commitmentHasher.inputs[1] <== salt;
commitmentHasher.out === balanceCommitment;

component gte = GreaterEqThan(n);   // n = 64-bit
gte.in[0] <== balance;
gte.in[1] <== minAmount;
gte.out === 1;
```

`GreaterEqThan(64)` dari `circomlib` bekerja pada nilai ≤ 2^64. Aman untuk
amount MATIC dalam wei karena 2^64 wei ≈ 1.8 × 10^19 wei ≈ 18 ETH, jauh di atas
saldo testnet realistic.

**Kegunaan**: dipanggil oleh `private_transfer` (sebagai assertion `senderBalance
≥ amount`) — sebenarnya pada implementasi sekarang `balance_check` adalah
circuit terpisah yang **tidak lagi dipanggil di alur live**; sub-check
serupa sudah inline di `private_transfer.circom`. Verifier-nya tetap di-deploy
sebagai opsi extension (mis. proof of solvency).

### 5.2 `private_transfer.circom`

**Klaim**: prover bisa membelanjakan commitment lama dengan benar tanpa reveal
saldo atau nominal.

7 sub-konstrain di circuit (model shielded-keypair; urutan sesuai
`private_transfer.circom:36-79`):

```
1. senderShieldPub === Poseidon(senderShieldPriv)
   → bukti prover tahu kunci rahasia shield
2. senderCommitment === Poseidon(amountIn, senderShieldPub, senderSalt)
   → bukti kepemilikan note lama (deposit-shaped)
3. amountIn >= transferAmount
   → bukti saldo cukup (GreaterEqThan(64))
4. change := amountIn - transferAmount ; change >= 0
   → kembalian non-negatif
5. nullifier === Poseidon(senderShieldPriv, senderCommitment)
   → nullifier deterministik & unik per spend
6. newSelfCommitment === Poseidon(change, senderShieldPub, changeSalt)
   → note kembalian untuk pengirim (deposit-shaped)
7. recipientCommitment === Poseidon(transferAmount, recipientShieldPub, recipientSalt)
   → note untuk penerima (deposit-shaped, langsung withdrawable)
```

> Catatan: `senderShieldPub`/`recipientShieldPub` = `Poseidon(shieldPriv)`. Yang
> ditanam di commitment adalah kunci **publik** shield; rahasia sebenarnya
> (`shieldPriv`) hanya muncul di konstrain 1 & 5. Ketiga commitment memakai skema
> **3-input identik** `Poseidon(amount, shieldPub, salt)`, sehingga note penerima
> bisa langsung di-`withdraw` tanpa konversi.

**Public signals**: hanya hash Poseidon + nullifier — tidak ada nominal atau
alamat cleartext. On-chain explorer hanya melihat 4 angka opaque.

**Anti double-spend**: contract menyimpan `mapping(bytes32 => bool) nullifiers`.
Spend kedua dengan `senderCommitment` yang sama menghasilkan `nullifier` identik
(deterministik) → contract revert. Tidak bisa dipalsukan karena `senderShieldPriv`
adalah private input.

### 5.3 `withdraw.circom`
**Klaim**: prover pemegang note `commitment` ingin menarik full value-nya ke
`recipient` (address public).

```
1. shieldPub === Poseidon(shieldPriv)
   → bukti prover tahu kunci rahasia shield
2. commitment === Poseidon(amount, shieldPub, salt)
   → bukti kepemilikan note (deposit-shaped). `amount` adalah PUBLIC input dan
     sekaligus nilai note yang terikat di commitment
3. nullifier === Poseidon(shieldPriv, commitment)
   → mencegah re-withdraw
4. recipient signal bound (recipientBound <== recipient * 1)
   → mengikat proof ke recipient tertentu (frontrun-resistant; cegah optimize-out)
```

Private inputs hanya `shieldPriv` & `salt`; `amount` bersifat **publik** (ujung
fiat ramp). Tidak ada signal `balance` terpisah — nilai note **adalah** `amount`
yang terikat di commitment.

**Full-burn rationale**: withdraw tidak men-mint note baru, jadi seluruh nilai note
(`amount`) ditarik dan commitment di-burn utuh — tidak ada partial withdraw. Untuk
menarik sebagian, user harus dulu `privateTransfer` ke diri sendiri guna memecah
note. Ini menyederhanakan state machine dan menyamakan dengan pola Tornado Cash classic.

**Recipient binding**: `recipient` adalah public signal yang dimasukkan ke proof.
Penyerang yang men-sniff proof dari mempool tidak bisa relay ulang proof tersebut
ke `recipient` berbeda — proof akan invalid karena public signal `recipient`
berubah. Pengikatan dilakukan via constraint passthrough `recipientBound <== recipient * 1`
(mencegah compiler meng-optimize-out signal `recipient`); `withdraw.circom`
**tidak** memakai range-check pada `recipient`.

### 5.4 Kenapa Poseidon, bukan SHA-256

| Hash | Constraint per evaluation di R1CS | Cocok untuk |
|---|---|---|
| SHA-256 | ~25.000 constraint | Hashing data besar di luar circuit |
| Keccak-256 | ~150.000 constraint | Sama |
| **Poseidon(3)** | **~250 constraint** | **Native zk-SNARK hash** |

Poseidon dirancang untuk arithmetic-friendly: hanya operasi field add + mul +
S-box `x^5`. Itu sebabnya circuit XevouZK pakai Poseidon untuk semua commitment
dan nullifier. SHA-256 hanya dipakai di Schnorr (di luar circuit, pure software
hash).

### 5.5 Constraint count (snapshot)

| Circuit | Non-linear constraints | Wires | Generate proof (browser) | Verifier gas (Amoy) |
|---|---|---|---|---|
| balance_check | ~520 | ~1.300 | ~3 detik | ~250k |
| private_transfer | ~860 | ~2.100 | ~8 detik | ~400k |
| withdraw | ~610 | ~1.500 | ~5 detik | ~280k |

Angka diukur di laptop dev (i5 mid-tier, Chrome). Mobile browser bisa 2–4× lebih
lambat — pertimbangkan progress indicator UX.

---

## 6. Trusted Setup Ceremony

### 6.1 Kenapa butuh trusted setup

Groth16 butuh **Structured Reference String (SRS)** yang dihasilkan dari "toxic
waste" `τ` (tau). Siapa pun yang tahu `τ` bisa membuat **proof palsu** untuk
pernyataan apa pun. Karena itu `τ` harus dihancurkan setelah SRS dibuat.

Setup pakai 2 fase:

| Fase | Sifat | Output |
|---|---|---|
| **Phase 1 (Powers of Tau)** | Universal, reusable | `pot{n}_final.ptau` |
| **Phase 2 (Circuit-specific)** | Per circuit | `circuit_final.zkey` + `verification_key.json` |

### 6.2 Phase 1 — Powers of Tau pot14

XevouZK pakai **`pot14_final.ptau`** (≈ 19 MB) yang men-support circuit hingga
2^14 = 16.384 constraint. Cukup untuk ketiga circuit XevouZK (paling besar
`private_transfer` ~860 constraint, jauh di bawah batas).

Ada dua sumber `.ptau`:

1. **Hermez community ceremony** (rekomendasi, multi-party). XevouZK pakai ini
   untuk demo TA.
2. Single-party local generation (kalau offline; tidak untuk production).

Download via npm script: `npm run download:ptau` (curl) atau
`npm run download:ptau:win` (PowerShell `Invoke-WebRequest`).

### 6.3 Phase 2 — kontribusi single-party

Untuk prototipe TA, Phase 2 dijalankan **single-party** dengan kontribusi acak.
Lihat [`circuits/scripts/setup.js`](../circuits/scripts/setup.js) (dipanggil
per circuit via `npm run setup:balance|transfer|withdraw`, atau `setup:all`):

```javascript
// node scripts/setup.js <circuit_name>:
await snarkjs.zKey.newZKey(r1cs, pot14_final, zkey_0000);
await snarkjs.zKey.contribute(zkey_0000, zkey_final, "XevouZK Contribution", entropy);
const vkey = await snarkjs.zKey.exportVerificationKey(zkey_final);  // → verification_key.json
await snarkjs.zKey.verifyFromR1cs(r1cs, pot14_final, zkey_final);   // self-check
// Solidity verifier diekspor terpisah: npm run export:verifiers
```

**Klaim TA yang akurat tentang trusted setup**:

> "XevouZK menggunakan SRS Phase 1 dari Hermez community Powers of Tau
> ceremony (pot14) dan kontribusi Phase 2 single-party untuk circuit
> `balance_check`, `private_transfer`, dan `withdraw`. Karena Phase 2 single-party,
> validitas proof bergantung pada asumsi bahwa pihak yang men-generate kontribusi
> Phase 2 menghancurkan toxic waste-nya. Untuk production, ceremony Phase 2
> multi-party diperlukan."

**Klaim yang harus DIHINDARI**:

- ❌ "Trusted setup XevouZK multi-party" — tidak. Phase 2 single-party.
- ❌ "Tidak butuh trust assumption" — butuh, lihat di atas.

### 6.4 Verification key di kontrak

Output `exportSolidityVerifier` adalah file Solidity dengan VK ter-hardcode
sebagai konstanta. XevouZK menamai ulang menjadi:

- `contracts/contracts/verifiers/BalanceCheckVerifier.sol`
- `contracts/contracts/verifiers/PrivateTransferVerifier.sol`
- `contracts/contracts/verifiers/WithdrawVerifier.sol`

Tiap kontrak punya method `verifyProof(uint[2] a, uint[2][2] b, uint[2] c,
uint[N] pubSignals) returns (bool)` yang melakukan 3 pairing check via precompile
`0x08`.

---

## 7. Smart Contract ZKPayment v2

### 7.1 Arsitektur

```
ZKPayment v2
├── verifier slots (3):
│   ├── balanceVerifier   → BalanceCheckVerifier.verifyProof()
│   ├── transferVerifier  → PrivateTransferVerifier.verifyProof()
│   └── withdrawVerifier  → WithdrawVerifier.verifyProof()
│
├── state:
│   ├── mapping(uint256 => bool) activeCommitments   // note pool
│   ├── mapping(bytes32 => bool) nullifiers           // anti-replay
│   ├── totalDeposited / totalWithdrawn / transactionCount
│   └── owner   (= DEPLOYER wallet, least privilege; lihat PROJECT-STATUS §8)
│
├── methods (lifecycle deposit → transfer → withdraw):
│   ├── deposit(commitment) payable
│   │     - msg.value > 0
│   │     - commitment ∉ activeCommitments
│   │     - mark active + emit Deposit(user, commitment, amount)
│   │     - totalDeposited += msg.value
│   │
│   ├── privateTransfer(a, b, c, pubSignals[4])
│   │     - verify proof via transferVerifier
│   │     - require !nullifiers[nullifier]
│   │     - require activeCommitments[senderCommitment]
│   │     - burn senderCommitment (set to false)
│   │     - mint newSelfCommitment + recipientCommitment (set to true)
│   │     - mark nullifier used
│   │     - emit PrivateTransfer(nullifier, old, new, recipient)
│   │
│   ├── withdraw(a, b, c, pubSignals[4])
│   │     - verify proof via withdrawVerifier
│   │     - require !nullifiers[nullifier]
│   │     - require activeCommitments[commitment]
│   │     - burn commitment
│   │     - transfer `amount` MATIC ke `recipient` (cast uint160→address)
│   │     - totalWithdrawn += amount
│   │     - emit Withdraw(nullifier, recipient, amount)
│   │
│   └── admin (onlyOwner):
│         updateBalanceVerifier / updateTransferVerifier / updateWithdrawVerifier
│         emergencyWithdraw
│
└── invariants:
    address(this).balance == totalDeposited - totalWithdrawn
    (modulo emergencyWithdraw yang dipanggil owner; di TA scope tidak dipakai)
```

### 7.2 Alamat live (Polygon Amoy, chainId 80002)

Snapshot deploy **2026-06-05** (lihat juga [PROJECT-STATUS §2](PROJECT-STATUS.md) & `contracts/deployments/amoy.json`):

| Contract | Address |
|---|---|
| BalanceCheckVerifier | [`0x5653778d4c1C2257Eb65fAa69B714364E7a01363`](https://amoy.polygonscan.com/address/0x5653778d4c1C2257Eb65fAa69B714364E7a01363#code) |
| PrivateTransferVerifier | [`0x5500d21AC089152c0131eC0B7fB97Ad72ED40457`](https://amoy.polygonscan.com/address/0x5500d21AC089152c0131eC0B7fB97Ad72ED40457#code) |
| WithdrawVerifier | [`0xa6ff8557D425Bc32D582c544E3DBBfd48Ec56056`](https://amoy.polygonscan.com/address/0xa6ff8557D425Bc32D582c544E3DBBfd48Ec56056#code) |
| **ZKPayment v2** | [`0x105e6DB96C697DA8ca0952116bEA12AAbFF359B5`](https://amoy.polygonscan.com/address/0x105e6DB96C697DA8ca0952116bEA12AAbFF359B5#code) |

Source ter-verify di PolygonScan. Owner = DEPLOYER `0xF90BA9...Adf4` (terpisah
dari MASTER, lihat [PROJECT-STATUS §5](PROJECT-STATUS.md) — least privilege).

---

## 8. Alur End-to-End

### 8.1 Register non-custodial

```
USER                BROWSER                          SERVER (Laravel)        DB
 │                    │                                    │                  │
 │ email + password   │                                    │                  │
 │ ─────────────────▶ │                                    │                  │
 │                    │ derive schnorr_priv, schnorr_pub   │                  │
 │                    │ derive polygon_priv, polygon_pub   │                  │
 │                    │ compute polygon_addr (EIP-55)      │                  │
 │                    │                                    │                  │
 │                    │ POST /register                     │                  │
 │                    │  { name, email,                    │                  │
 │                    │    schnorr_public_key,             │                  │
 │                    │    polygon_address,                │                  │
 │                    │    polygon_public_key }            │                  │
 │                    │   (TANPA password)                 │                  │
 │                    │ ──────────────────────────────────▶│                  │
 │                    │                                    │ validate         │
 │                    │                                    │ EIP-55 checksum  │
 │                    │                                    │ password =       │
 │                    │                                    │ Hash::make(random)│
 │                    │                                    │ ────────────────▶│
 │                    │                                    │                  │ users (id, email, placeholder-hash, zk_public_key)
 │                    │                                    │                  │ wallets (user_id, polygon_address, public_key)
 │                    │                                    │                  │   ← NO encrypted_private_key column
 │                    │ 302 /dashboard                     │                  │
 │                    │ ◀──────────────────────────────────│                  │
```

**Bukti non-custodial**: di Network tab, request body **tidak** mengandung
`polygon_private_key` atau `schnorr_private_key`. Server bahkan tidak punya
artifact untuk derive ulang.

### 8.2 Login Schnorr

```
USER         BROWSER                                        SERVER
 │             │                                              │
 │ email + pw  │                                              │
 │ ──────────▶ │                                              │
 │             │ schnorr_priv = derive(email, pw, "schnorr_v1")│
 │             │ ts = Date.now()                              │
 │             │ csrf = read meta[name=csrf-token]            │
 │             │ msg = lower(email) + "|" + ts + "|" + csrf   │
 │             │ sig = schnorrSign(schnorr_priv, msg)         │
 │             │                                              │
 │             │ POST /login                                  │
 │             │  { email, schnorr_signature: sig,            │
 │             │    schnorr_timestamp: ts, csrf_token: csrf } │
 │             │ ────────────────────────────────────────────▶│
 │             │                                              │ User::where(email=...)
 │             │                                              │ msg' = rebuild(email, ts, csrf)
 │             │                                              │ check |now - ts| < 300_000 ms
 │             │                                              │ check csrf valid
 │             │                                              │ check $schnorr->verify(pub, msg', sig)
 │             │                                              │ Auth::login($user)
 │             │ 302 /dashboard                               │
 │             │ ◀────────────────────────────────────────────│
```

**Password tidak ditransmit** — login **murni Schnorr** (`Auth::attempt` sudah
dihapus). Form login & register tidak punya field `name="password"`; password hanya
dipakai di browser untuk derive key. Kolom `users.password` diisi **hash acak
placeholder** (`Hash::make(Str::random(40))`), bukan hash password user, dan tak
pernah dicocokkan. Hardening: **single-use replay-nonce** (`Cache::add` per
`sha256(signature)`, TTL = window 300 dtk) menolak signature yang dipakai ulang, dan
**rate limiting** per (email+IP) maks 5 gagal + `throttle:10,1` per-IP di route.

### 8.3 Pembayaran plain non-custodial

```
USER       BROWSER                       SERVER             POLYGON RPC      ZKPayment
 │           │                              │                    │              │
 │ prompt pw │                              │                    │              │
 │ ────────▶ │                              │                    │              │
 │           │ derive polygon_priv          │                    │              │
 │           │ GET /payment/nonce?addr=...  │                    │              │
 │           │ ────────────────────────────▶│ web3->getNonce()   │              │
 │           │                              │ ──────────────────▶│              │
 │           │ { nonce, feeData }           │                    │              │
 │           │ ◀────────────────────────────│                    │              │
 │           │ build EIP-1559 tx            │                    │              │
 │           │ sign via ethers.js v6        │                    │              │
 │           │ POST /payment/relay          │                    │              │
 │           │  { raw_tx: 0x... }           │                    │              │
 │           │ ────────────────────────────▶│ validate hex       │              │
 │           │                              │ sendRawTransaction │              │
 │           │                              │ ──────────────────▶│              │
 │           │                              │                    │ broadcast    │
 │           │ { tx_hash }                  │                    │              │
 │           │ ◀────────────────────────────│                    │              │
```

`msg.sender` di explorer = polygon_addr **user** (signer = browser). Server
hanya broadcast — tidak punya kuasa untuk sign atas nama user.

### 8.4 Pembayaran privat ZK (commitment pool, self-signed)

Transfer privat = belanjakan note pool → mint note kembalian + note penerima,
kirim note penerima sebagai **memo ECIES** lewat event. **User menandatangani &
membayar gas sendiri** (tidak ada gasless relayer; master = faucet saja).

```
USER       BROWSER                                    SERVER          ZKPayment
 │ scan QR  │ decrypt note pengirim (localStorage)       │                 │
 │ Privat   │ derive shieldPriv + Polygon key            │                 │
 │ ───────▶ │ pilih changeSalt + recipientSalt           │                 │
 │          │ snarkjs.fullProve(private_transfer)        │                 │
 │          │   → senderCommitment(burn), nullifier,     │                 │
 │          │     newSelfCommitment, recipientCommitment │                 │
 │          │ ECIES memo {amount,salt,commitment}        │                 │
 │          │ POST /payment/transfer/verify (preview) ──▶│ verifyTransfer  │
 │          │ ◀──────────────────────────────────────────│  Proof (struct  │
 │          │  { ok, public_inputs }                     │  + nullifier DB)│
 │          │ sign privateTransfer(a,b,c,pubSignals,memo)│  [USER key]     │
 │          │ POST /payment/relay { raw_tx } ───────────▶│ sendRawTx ─────▶│ privateTransfer
 │          │                                            │ (broadcast)     │  - pairing check
 │          │                                            │                 │  - !nullifiers[n]
 │          │                                            │                 │  - burn senderCommitment
 │          │                                            │                 │  - mint 2 commitment
 │          │ ◀──────────────────────────────────────────│ { tx_hash }     │  - emit EncryptedNote
 │          │ simpan note kembalian + mark note used     │                 │  [msg.sender = USER]
```

**Penemuan dana penerima**: penerima `/dashboard` → "Cek Transfer Masuk"
(`scanIncomingNotes`) memindai event `EncryptedNote(recipientCommitment, memo)`,
trial-decrypt tiap memo dengan enc-key-nya, verifikasi `recipientCommitment`,
simpan note yang cocok.

**Catatan `msg.sender`**: kontrak `privateTransfer(a,b,c,pubSignals,memo)` **tidak**
mengecek `msg.sender` — validitas 100% dari ZK proof + nullifier. Karena user yang
menandatangani, `msg.sender = alamat user`: pengamat tahu *bahwa* user bertransaksi
privat, tetapi **nominal & penerima tetap tersembunyi** (commitment Poseidon + memo
terenkripsi). Gasless relayer (agar `msg.sender` ≠ user, pola Tornado Cash) bisa
ditambahkan kelak tanpa redeploy — saat ini **belum** dipakai (lihat §10 & roadmap).

### 8.5 Deposit ke pool privat

```
USER       BROWSER                                       SERVER    ZKPayment
 │ prompt   │                                              │           │
 │ pw + amt │                                              │           │
 │ ───────▶ │ derive polygon_priv                          │           │
 │          │ shieldPriv = derive(email, pw); shieldPub=Poseidon(shieldPriv) │
 │          │ salt = random()                              │           │
 │          │ commitment = Poseidon(amount_wei, shieldPub, salt)       │
 │          │ note = { commitment, salt, amount }          │           │
 │          │ encrypted_note = AES-GCM(note, key=PBKDF2(pw+email))     │
 │          │ localStorage.setItem("xevouzk_notes_" + addr, [...])    │
 │          │                                              │           │
 │          │ build tx: ZKPayment.deposit{value:amt}(commitment)       │
 │          │ sign via ethers.js                          │           │
 │          │ POST /payment/relay { raw_tx }              │           │
 │          │ ────────────────────────────────────────────▶│           │
 │          │                                              │ ─────────▶│ deposit()
 │          │                                              │           │  - msg.value > 0
 │          │                                              │           │  - mark activeCommitments[c]
 │          │                                              │           │  - totalDeposited += value
 │          │                                              │           │  - emit Deposit
 │          │ { tx_hash }                                  │           │
 │          │ ◀────────────────────────────────────────────│           │
```

**Privacy**: di explorer terlihat `deposit(commitment, value)` dengan
`msg.sender = user_addr`. Ini **memang publik** (ujung "fiat ramp"), tapi
mapping ke recipient final tidak terlihat (recipient akan withdraw note yang
sudah di-private-transfer beberapa kali).

### 8.6 Withdraw dari pool privat

```
USER       BROWSER                                       SERVER    ZKPayment
 │ prompt   │ list encrypted notes                         │           │
 │ pw       │ decrypt selected note (AES-GCM)             │           │
 │ ───────▶ │ { commitment, salt, amount }; shieldPriv=derive(email,pw)  │
 │          │                                              │           │
 │          │ snarkjs.fullProve(                          │           │
 │          │   witness = { amount, shieldPriv, salt }    │           │
 │          │   publicSignals = { commitment, nullifier,  │           │
 │          │                     recipient, amount }     │           │
 │          │ )                                           │           │
 │          │                                              │           │
 │          │ POST /payment/withdraw/verify (preview)     │           │
 │          │ ────────────────────────────────────────────▶│ verifyWithdrawProof
 │          │ { ok: true, public_inputs: {...} }          │           │
 │          │ ◀────────────────────────────────────────────│           │
 │          │                                              │           │
 │          │ build tx: ZKPayment.withdraw(a,b,c,pubSig)  │           │
 │          │ sign via ethers.js                          │           │
 │          │ POST /payment/relay { raw_tx }              │           │
 │          │ ────────────────────────────────────────────▶│ ─────────▶│ withdraw()
 │          │                                              │           │  - pairing check
 │          │                                              │           │  - !nullifiers[n]
 │          │                                              │           │  - transfer MATIC
 │          │                                              │           │  - totalWithdrawn += amt
 │          │ mark note used in localStorage              │           │
```

**Preview endpoint**: server cek dulu validity proof (struct + field range +
nullifier) supaya user tidak buang gas untuk tx yang akan revert. Ini hint
pre-validation, bukan source of truth — kontrak tetap re-verify on-chain.

---

## 9. Threat Model

### 9.1 Aktor & tingkat trust

| Aktor | Tingkat trust | Akses |
|---|---|---|
| **User browser** | Trusted (self) | Password, derived keys, witness, sign tx |
| **Server Laravel** | Semi-trusted (DB integrity, UX) | Public keys, nullifier DB, ledger off-chain (`users.password` = hash acak placeholder, bukan password) |
| **MASTER wallet** | Semi-trusted (faucet) | Kirim test MATIC ke user. **Tidak** menandatangani tx user (bukan relayer), tidak custody dana. |
| **DEPLOYER wallet** | Trusted, di laptop dev saja | Owner kontrak, hak admin (updateVerifier, emergencyWithdraw) |
| **Polygon Amoy validator** | Trusted (chain consensus) | Tidak relevan untuk privacy claim |
| **External observer (PolygonScan)** | Untrusted | Lihat semua public state on-chain |
| **Penyerang network** | Untrusted | Bisa sniff request HTTPS (tidak bisa decrypt; mitigasi TLS) |

### 9.2 Aset yang dilindungi

| Aset | Layer privasi | Layer integritas |
|---|---|---|
| Password user | Schnorr derivation (browser); **tak pernah dikirim ke server** | `users.password` = hash acak placeholder (tak dipakai auth) |
| Schnorr private key | Tidak ada di server / DB | Public key di DB; signature self-verify |
| Polygon private key | Tidak ada di server / DB | Address di DB; tx sign di browser |
| Saldo Polygon | ⚠️ Shadow di DB cleartext (UX) | Sync dari blockchain |
| Nominal transaksi ZK | On-chain commitment Poseidon only | Nullifier dual: DB + kontrak |
| Identitas penerima ZK | On-chain commitment Poseidon only | Recipient binding di proof |
| Nominal & recipient plain transfer | ❌ Terlihat di explorer (banner warning di UI) | Standar EVM |

### 9.3 Skenario kompromi

| Skenario | Damage | Mitigasi (sudah / belum) |
|---|---|---|
| Server di-hack, `.env` bocor | MASTER dicuri (faucet drained max 5 MATIC). DEPLOYER aman karena tidak di server. | ✅ pemisahan key |
| DB bocor | Public keys + ledger off-chain terbongkar. Saldo shadow + nominal **publik** (plain/deposit/withdraw) cleartext. Private key & password tidak ada; nominal transfer privat = NULL. Tabel `note_backups` berisi **ciphertext opaque + ref hash** (tak bisa di-decrypt tanpa password). | ✅ kolom encrypted_private_key dihapus, password = placeholder, nominal privat tak disimpan, backup note ter-enkripsi (server tak bisa baca). ⚠️ ledger off-chain memang ada (trade-off); residual metadata count/timing backup |
| Toxic waste Phase 2 bocor (single party) | Penyerang bisa fabricate proof palsu untuk ketiga circuit | ⚠️ Asumsi prototipe TA. Untuk production: ceremony multi-party. |
| Smart contract bug (mis. nullifier collision) | Double-spend on-chain | ✅ 34 Hardhat test + lifecycle on-chain real proof + nullifier unique by Poseidon design |
| User pakai mode plain padahal niat privat | Address + nominal di explorer | ⚠️ Banner warning di UI (PRIVACY-GAP §3.C); user awareness required |
| User salah ketik password (operasi sensitif) | Diam-diam derive identitas lain → operasi salah-alamat | ✅ **account-guard** cocokkan Schnorr pubkey turunan vs `zk_public_key` akun sebelum operasi; salah → dibatalkan di klien (PROJECT-STATUS §3.8) |
| User lupa password | Wallet hilang permanen | ⚠️ Backup note lintas-device menyelamatkan note dari ganti/hilang **device**, **bukan** dari lupa password (kunci AES turun dari password). Recovery BIP-39 = future work |
| Ganti/clear browser | Note lokal hilang | ✅ Backup note terenkripsi ke server + scan event sebagai jaring pengaman |
| Replay Schnorr signature | Login takeover dalam window 5 menit | ✅ timestamp + csrf token binding |
| Replay ZK proof | Tidak bisa — nullifier deterministik | ✅ design level |
| Frontrun withdraw proof | Penyerang relay proof ke recipient lain | ✅ recipient signal di-bind di circuit |
| Server skip nullifier check | Double-spend di DB layer | ✅ kontrak nullifier mapping = backup canonical |

### 9.4 Yang DI LUAR scope (tidak diklaim aman)

- **DoS layer**: rate limit ada (`throttle:5,1` di faucet, default Laravel CSRF
  protection di route web), tapi tidak ada anti-Sybil sophisticated.
- **Side-channel attack pada browser**: timing attack pada `snarkjs.fullProve`
  bisa leak info amount (variance kecil). Tidak di-mitigasi.
- **Quantum adversary**: secp256k1 + Groth16 BN128 tidak quantum-resistant.
  Tidak relevan untuk timeline TA.
- **Mainnet Polygon**: scope testnet only.

---

## 10. Privacy Posture (klaim TA)

Detail di [PRIVACY-GAP-ANALYSIS.md](PRIVACY-GAP-ANALYSIS.md). Ringkasan klaim
yang akurat & klaim yang harus dihindari.

### 10.1 Klaim akurat untuk laporan TA

> "XevouZK menggunakan Schnorr signature (secp256k1, Fiat-Shamir) untuk
> autentikasi tanpa mengirim password, dan Groth16 zk-SNARK untuk pembayaran P2P
> di mana nominal transaksi, saldo sender, dan identitas penerima tidak terlihat
> di Polygon blockchain explorer publik. Private key Polygon user di-derive
> deterministik di browser dari `(email, password)` — server tidak menyimpan
> kuasa untuk mengirim atas nama user pada **semua** mode (plain, deposit,
> privateTransfer, withdraw di-sign user; server hanya relay raw tx). Transfer
> privat memakai commitment pool ala Tornado: note penerima dikirim sebagai memo
> terenkripsi via event `EncryptedNote`. Karena tx di-sign user, `msg.sender`
> transaksi privat = alamat user (fakta bertransaksi terlihat), tetapi nominal &
> penerima tetap tersembunyi. Server XevouZK menyimpan ledger operasional
> internal (nominal, FK wallet) untuk kebutuhan UX history dan audit.
> Anti-double-spend menggunakan nullifier Poseidon yang dicatat di database
> internal dan di contract `ZKPayment` sebagai backup canonical."

### 10.2 Klaim yang harus DIHINDARI

| Klaim overstated | Realitas |
|---|---|
| "Saldo terenkripsi end-to-end" | DB shadow `wallets.balance` cleartext untuk UX |
| "Nominal tersembunyi total" | Nominal **privat** = NULL di DB ✅, tapi saldo shadow & nominal **plain/deposit/withdraw** cleartext (toh publik on-chain) |
| "Trustless / tidak butuh percaya server" | Nullifier source of truth = DB; server bisa skip cek |
| "Fully on-chain settlement" | Mode plain transfer tetap ada |
| "Identitas sender transaksi privat tersembunyi" | `msg.sender = user` (tx di-sign sendiri); yang tersembunyi nominal & penerima, bukan fakta bertransaksi |
| "Trusted setup multi-party" | Phase 2 single-party (prototipe TA) |
| "Wallet recoverable tanpa password" | Lupa password = wallet hilang permanen |

---

## 11. Code Reference

### 11.1 Backend (PHP)

| File | Tanggung jawab |
|---|---|
| [`app/Services/SchnorrService.php`](../app/Services/SchnorrService.php) | Schnorr sign/verify secp256k1 + Fiat-Shamir |
| [`app/Services/ZKSNARKService.php`](../app/Services/ZKSNARKService.php) | Struct validation Groth16 proof + nullifier DB lookup + verify {balance,transfer,withdraw} proof |
| [`app/Services/PolygonService.php`](../app/Services/PolygonService.php) | Web3 RPC + sendRawTransaction (non-custodial relay untuk semua tx user) + faucet send |
| [`app/Services/QRCodeService.php`](../app/Services/QRCodeService.php) | Static + dynamic QR, HMAC signature, expiration |
| [`app/Http/Controllers/AuthController.php`](../app/Http/Controllers/AuthController.php) | Register non-custodial + Schnorr login |
| [`app/Http/Controllers/PaymentController.php`](../app/Http/Controllers/PaymentController.php) | relayRawTransaction, scanRpc (proxy getLogs read-only), recordRelayTransfer/recordPoolEvent, previewTransfer/previewWithdraw, QR scan |
| [`app/Http/Controllers/WalletController.php`](../app/Http/Controllers/WalletController.php) | Wallet info, faucet, QR delegation |
| [`app/Http/Controllers/NoteBackupController.php`](../app/Http/Controllers/NoteBackupController.php) | Backup note terenkripsi lintas-device (store/index ciphertext opaque + ref) |

### 11.2 Frontend (JavaScript)

Modul yang meng-`import` library di-**bundle Vite** dari `node_modules` dan
berada di `resources/js/` (dimuat di Blade via `@vite([...])`). File classic
tanpa library tetap di `public/js/` (dimuat via `asset('js/...')`).

| File | Tanggung jawab |
|---|---|
| [`resources/js/schnorr-auth.js`](../resources/js/schnorr-auth.js) | Schnorr key derivation + sign di browser (`@noble/curves`) |
| [`resources/js/polygon-key.js`](../resources/js/polygon-key.js) | Polygon key derivation + EIP-55 address |
| [`resources/js/shield-key.js`](../resources/js/shield-key.js) | Derivasi shielded keypair (Poseidon) untuk pool |
| [`resources/js/note-store.js`](../resources/js/note-store.js) | Simpan/baca encrypted note di localStorage (AES-GCM) |
| [`resources/js/note-crypto.js`](../resources/js/note-crypto.js) | ECIES encrypt/decrypt memo + enc-keypair |
| [`resources/js/payment-relay.js`](../resources/js/payment-relay.js) | ethers.js v6 signing (plain) → relay |
| [`resources/js/pool-balance.js`](../resources/js/pool-balance.js) | Hitung saldo pool privat dari note lokal |
| [`resources/js/polygon-deposit.js`](../resources/js/polygon-deposit.js) | Commitment + encrypted note + deposit tx (self-sign) |
| [`resources/js/polygon-withdraw.js`](../resources/js/polygon-withdraw.js) | Decrypt note + withdraw proof + tx (self-sign) |
| [`resources/js/polygon-transfer.js`](../resources/js/polygon-transfer.js) | private_transfer proof + memo ECIES + `scanIncomingNotes` |
| [`resources/js/private-send.js`](../resources/js/private-send.js) | Orkestrasi transfer privat (parse kode penerima + pilih note + `transferFromPool`) — dipakai scan & manual |
| [`resources/js/note-backup.js`](../resources/js/note-backup.js) | Backup note terenkripsi ke server (`pushBackup`/`flushPending`/`syncOnLogin`); server tak pernah lihat plaintext |
| [`resources/js/account-guard.js`](../resources/js/account-guard.js) | Verifikasi password sisi-klien vs `zk_public_key` akun (tanpa kirim password) |
| [`resources/js/zk-snark.js`](../resources/js/zk-snark.js) | Wrapper snarkjs (`import`, bukan window-global) |
| [`resources/js/qr-scanner.js`](../resources/js/qr-scanner.js) | qr-scanner + server scan endpoint |
| [`resources/js/xevou-uri.js`](../resources/js/xevou-uri.js) | Encode/parse skema URI `xevouzk:` |
| [`resources/js/receive-qr.js`](../resources/js/receive-qr.js) | Generate QR terima (plain + privat zkpub) |

### 11.3 Circuits

| File | Output |
|---|---|
| [`circuits/balance_check.circom`](../circuits/balance_check.circom) | `balance_check.wasm` + `balance_check_final.zkey` |
| [`circuits/private_transfer.circom`](../circuits/private_transfer.circom) | `private_transfer.wasm` + `private_transfer_final.zkey` |
| [`circuits/withdraw.circom`](../circuits/withdraw.circom) | `withdraw.wasm` + `withdraw_final.zkey` |
| [`circuits/scripts/setup.js`](../circuits/scripts/setup.js) | Trusted setup Phase 2 per circuit (`setup:all`) |
| [`circuits/scripts/test-proofs.js`](../circuits/scripts/test-proofs.js) | Local generate + verify (3 circuit + 3 negative case) |

### 11.4 Smart contracts

| File | Tanggung jawab |
|---|---|
| [`contracts/contracts/ZKPayment.sol`](../contracts/contracts/ZKPayment.sol) | Main: deposit + privateTransfer + withdraw + admin |
| [`contracts/contracts/verifiers/BalanceCheckVerifier.sol`](../contracts/contracts/verifiers/) | snarkjs-exported, real VK |
| [`contracts/contracts/verifiers/PrivateTransferVerifier.sol`](../contracts/contracts/verifiers/) | snarkjs-exported, real VK |
| [`contracts/contracts/verifiers/WithdrawVerifier.sol`](../contracts/contracts/verifiers/) | snarkjs-exported, real VK |
| [`contracts/test/ZKPayment.test.js`](../contracts/test/ZKPayment.test.js) | 34 Hardhat behavioral test |
| [`contracts/scripts/deploy.js`](../contracts/scripts/deploy.js) | Deploy 4 kontrak ke Amoy |
| [`contracts/scripts/test-transfer-e2e.js`](../contracts/scripts/test-transfer-e2e.js) | E2E real proof di Amoy (deposit→transfer→withdraw, cetak gas used) |

### 11.5 Docs

| File | Isi |
|---|---|
| [`README.md`](../README.md) | Overview untuk reviewer eksternal |
| [`docs/PROJECT-UNDERSTANDING.md`](PROJECT-UNDERSTANDING.md) | Primer konseptual: ZKP, arsitektur, istilah, catatan TA |
| [`docs/PROJECT-STATUS.md`](PROJECT-STATUS.md) | TA defense brief, snapshot end-to-end |
| [`docs/PETA-KODE.md`](PETA-KODE.md) | Peta kode: file & baris untuk tiap fungsi |
| [`docs/PRIVACY-GAP-ANALYSIS.md`](PRIVACY-GAP-ANALYSIS.md) | Klaim TA vs realitas per data field |
| [`docs/PENGUJIAN.md`](PENGUJIAN.md) | Panduan + template hasil bab Pengujian TA (7 jenis uji → klaim/command/tabel/bukti) |
| [`docs/TESTING-EVIDENCE.md`](TESTING-EVIDENCE.md) | Runbook + hasil terukur bukti pengujian (Hardhat/gas/snarkjs/E2E) |
| [`docs/DEPLOY-GUIDE.md`](DEPLOY-GUIDE.md) | Operational runbook deploy ke Amoy |

---

## 12. Glossary

| Istilah | Definisi |
|---|---|
| **Argument of Knowledge** | Bukti yang meyakinkan verifier bahwa prover **tahu** witness, bukan sekadar tahu bahwa witness ada. Lebih kuat dari "proof of existence". |
| **BN128 / alt_bn128** | Kurva elliptic Barreto-Naehrig dengan embedding degree 12 dan ordo ~254-bit. Dipakai Groth16 di EVM (EIP-197 precompile). |
| **Commitment** | Hash mengikat opaque dari note tanpa reveal isinya. Di XevouZK: `Poseidon(amount, shieldPub, salt)` (3-input, `shieldPub = Poseidon(shieldPriv)`). Tidak bisa di-reverse. |
| **EIP-55** | Standar checksum untuk Ethereum address — campuran upper/lowercase berdasarkan keccak hash. |
| **EIP-1559** | Format transaksi Ethereum dengan `maxFeePerGas` + `maxPriorityFeePerGas`. Polygon mendukung. |
| **EIP-197** | Precompile pairing check di EVM (alamat `0x08`). Memungkinkan verifikasi Groth16 on-chain. |
| **Fiat-Shamir transform** | Teknik mengubah protokol ZKP interaktif jadi non-interaktif dengan menghasilkan challenge dari hash transcript. |
| **Full-burn semantics** | Aturan di `withdraw.circom`: amount yang di-withdraw harus equal full value commitment. Tidak ada partial. |
| **Gasless relayer** | Pihak ketiga yang membayar gas untuk tx user, biasanya untuk menyembunyikan identitas user (mis. Tornado Cash). Pola opsional — **belum dipakai** di XevouZK (semua tx di-sign user sendiri). |
| **EncryptedNote** | Event on-chain `EncryptedNote(recipientCommitment, memo)` berisi memo ECIES note penerima; dipindai penerima (`scanIncomingNotes`) untuk menemukan dana masuk tanpa plaintext on-chain. |
| **Groth16** | Konstruksi zk-SNARK paling efisien (proof 3 group elements, verify 3 pairings). Butuh trusted setup per circuit. |
| **Knowledge-of-Exponent assumption** | Asumsi kriptografi: pemberian `g` dan `g^α`, satu-satunya cara hitung `(g^x, g^(αx))` adalah dengan tahu `x`. Dasar soundness Groth16. |
| **Master wallet** | Wallet operasional XevouZK **hanya** untuk faucet test MATIC (bukan relayer — tx privat user di-sign sendiri). Address `0x16a747...E6a4`. |
| **Deployer wallet** | Wallet untuk men-deploy kontrak → otomatis jadi owner. Address `0xF90BA9...Adf4`. Least privilege: hanya di laptop dev. |
| **Nullifier** | Tag deterministik per spend, didefinisikan `Poseidon(shieldPriv, commitment)`. Disimpan di mapping kontrak untuk anti-double-spend. |
| **Non-custodial** | Sistem yang tidak menyimpan private key user. Server tidak punya kuasa untuk mengirim atas nama user. XevouZK non-custodial untuk **semua** mode (plain, deposit, transfer, withdraw di-sign user). |
| **Note** | Satuan saldo privat di pool ZKPayment, berbentuk `Poseidon(amount, shieldPub, salt)` (deposit-shaped, seragam). Tersimpan terenkripsi `{commitment, salt, amount}` di browser; `shieldPriv` di-derive dari password. Hanya commitment yang on-chain. |
| **Polygon Amoy** | Testnet Polygon (chain ID 80002), pengganti Mumbai yang sudah deprecated. |
| **Poseidon** | Hash function dirancang untuk efisiensi di R1CS circuit (~250 constraint per evaluation). Dipakai di semua commitment + nullifier XevouZK. |
| **Powers of Tau (ptau)** | Universal Phase 1 trusted setup. XevouZK pakai `pot14_final.ptau` (≤ 2^14 constraint). |
| **Proof of Knowledge** | Lihat *Argument of Knowledge*. |
| **R1CS** | Rank-1 Constraint System. Format intermediate setelah compile circom — list of `(a·x)(b·x) = (c·x)` constraint. |
| **Schnorr signature** | Signature scheme berbasis discrete log + Fiat-Shamir. XevouZK pakai variant deterministic (SHA-256 nonce) atas secp256k1. |
| **secp256k1** | Kurva elliptic yang sama dipakai Bitcoin & Ethereum. XevouZK pakai untuk Schnorr auth dan derivation address Polygon. |
| **Soundness** | Properti ZKP: prover curang tidak bisa convince verifier yang jujur. |
| **SRS** | Structured Reference String — output trusted setup. Dipakai prover untuk generate proof + verifier untuk verify. |
| **Toxic waste** | `τ` (tau) dan turunan random dari trusted setup. Harus dihancurkan; kalau bocor, fabricate proof palsu bisa dilakukan. |
| **Trusted setup** | Ceremony off-chain untuk menghasilkan SRS Groth16. Phase 1 universal (ptau), Phase 2 circuit-specific (zkey). |
| **Witness** | Input rahasia ke circuit yang prover tahu. Mis. `balance`, `salt` di balance_check; `shieldPriv`, `salt` di withdraw. Tidak pernah di-reveal. |
| **Zero-Knowledge property** | Verifier tidak belajar apa pun dari proof selain bit "claim benar". Formalized via simulator. |
| **zk-SNARK** | Zero-Knowledge Succinct Non-interactive ARgument of Knowledge. Famili protokol yang Groth16 adalah anggotanya. |
| **zk-STARK** | Alternatif zk-SNARK tanpa trusted setup, tapi proof jauh lebih besar (~100 KB). XevouZK tidak pakai karena terlalu mahal di EVM. |

---

> **Maintainer note**: dokumen ini menggantikan versi commitment-based legacy
> yang sebelumnya ada di path yang sama. Jika ada perubahan arsitektur (circuit
> baru, kontrak baru, primitif kripto berubah), update dokumen ini berbarengan.
> Snapshot terakhir: **2026-06-22**.
