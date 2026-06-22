# Project Understanding — XevouZK

> **Tujuan dokumen**: titik masuk konseptual untuk memahami XevouZK secara utuh —
> dari "apa itu Zero-Knowledge Proof" sampai "bagaimana XevouZK merangkainya jadi
> sistem pembayaran". Ditulis untuk penulis TA, pembimbing, dan penguji yang ingin
> paham **gambaran besar + istilah kunci** sebelum masuk ke detail teknis.
>
> **Audiens**: pembaca yang belum tentu familiar dengan ZKP. Setiap istilah
> dijelaskan dari nol (lihat juga [Glossary](#13-glossary)).
>
> **Status**: snapshot 2026-06-06 (transfer privat self-signed pool; master = faucet saja; runtime lokal via Herd). Update 2026-06-13: (1) login **murni Schnorr** — password tak pernah dikirim (login & register), `users.password` = hash acak placeholder, + replay-nonce & rate limiting; (2) riwayat penerima privat (`private_receive`) unlinkable di sisi server-DB; (3) penjelasan asimetri biaya gas deposit vs withdraw dengan angka terukur on-chain (§10). Update 2026-06-19: deep-dive model commitment-pool (§7.7 mint/burn/change note + §7.7.1 skema note terpadu), alasan **tanpa Merkle tree** (§7.8), dan batas **anonymity set & linkability on-chain** (§7.9) — termasuk peringatan anti-overclaim "seanonim Tornado". Skema note diverifikasi langsung dari source (`private_transfer.circom`, `withdraw.circom`, `polygon-transfer.js`, `test-transfer-e2e.js`): semua note `Poseidon(amount, shieldPub, salt)` deposit-shaped, recipient note langsung withdrawable tanpa konversi. (Skema circuit di `ZK-SNARK-DOCUMENTATION §5` sudah diselaraskan ke rumus 3-input ini.)
>
> **Status**: Update 2026-06-22: (1) **backup note terenkripsi lintas-device** + **account guard** (§7.10); (2) penemuan dana masuk kini lewat **proxy server `/payment/scan-rpc`** agar RPC ber-API-key tetap di server (§7.5); (3) suite **PHPUnit dihapus** dari repo — bukti TA via Hardhat + E2E on-chain + snarkjs (§11.2; harness vektor interop Schnorr belum ada).
>
> **Hubungan dengan dokumen lain** — dokumen ini bersifat *naratif & konseptual*.
> Untuk detail mendalam, lihat:
> - [PETA-KODE.md](PETA-KODE.md) — peta kode: file & baris mana untuk apa (routing, controller, service, JS, circuit, kontrak).
> - [ZK-SNARK-DOCUMENTATION.md](ZK-SNARK-DOCUMENTATION.md) — matematika Schnorr/Groth16, desain circuit, threat model.
> - [PROJECT-STATUS.md](PROJECT-STATUS.md) — status implementasi, kontrak live, demo script TA.
> - [PRIVACY-GAP-ANALYSIS.md](PRIVACY-GAP-ANALYSIS.md) — klaim privasi vs realitas per data field.
> - [TESTING-EVIDENCE.md](TESTING-EVIDENCE.md) — runbook reproduksi bukti pengujian (command + gas + capture).
> - [DEPLOY-GUIDE.md](DEPLOY-GUIDE.md) — runbook deploy ke Amoy.
> - [circuits/README.md](../circuits/README.md) — build circuit + trusted setup.

---

## Daftar Isi

1. [Ringkasan satu paragraf](#1-ringkasan-satu-paragraf)
2. [Apa itu Zero-Knowledge Proof (ZKP)](#2-apa-itu-zero-knowledge-proof-zkp)
3. [Apa itu "ZKP platform" dan di mana XevouZK berdiri](#3-apa-itu-zkp-platform-dan-di-mana-xevouzk-berdiri)
4. [XevouZK dibangun oleh apa saja](#4-xevouzk-dibangun-oleh-apa-saja)
5. [Arsitektur sistem](#5-arsitektur-sistem)
6. [Cara kerja: dua primitif ZKP](#6-cara-kerja-dua-primitif-zkp)
7. [Konsep inti dan kenapa ada](#7-konsep-inti-dan-kenapa-ada)
8. [Alur end-to-end (ringkas)](#8-alur-end-to-end-ringkas)
9. [Apa yang privat vs publik](#9-apa-yang-privat-vs-publik)
10. [Penjelasan istilah yang sering ditanya](#10-penjelasan-istilah-yang-sering-ditanya)
11. [Hal penting untuk pembelaan Tugas Akhir](#11-hal-penting-untuk-pembelaan-tugas-akhir)
12. [Keterbatasan dan future work](#12-keterbatasan-dan-future-work)
13. [Glossary](#13-glossary)

---

## 1. Ringkasan satu paragraf

**XevouZK** adalah prototipe **sistem pembayaran digital peer-to-peer (P2P)** yang
membuktikan keabsahan transaksi **tanpa membongkar data sensitif** (saldo, nominal,
identitas penerima, kredensial), lalu menyelesaikan (*settle*) transaksi tersebut
**on-chain** di blockchain **Polygon Amoy testnet**. Privasi dicapai dengan dua
teknik kriptografi: **Schnorr signature** untuk login tanpa mengirim password, dan
**zk-SNARK Groth16** untuk pembayaran di mana nominal & penerima tersembunyi dari
publik. Wallet bersifat **non-custodial** — private key user lahir di browser dari
`(email, password)` dan tidak pernah dikirim ke server. Ini adalah artefak Tugas
Akhir *"Implementasi Sistem Pembayaran Digital Menggunakan Zero-Knowledge Proof dan
QR Code"*.

---

## 2. Apa itu Zero-Knowledge Proof (ZKP)

### 2.1 Definisi

**Zero-Knowledge Proof (ZKP)** adalah protokol kriptografi di mana satu pihak
(**prover** / pembukti) meyakinkan pihak lain (**verifier** / pemverifikasi) bahwa
sebuah pernyataan itu **benar**, **tanpa mengungkapkan informasi apa pun** selain
fakta bahwa pernyataan itu benar.

Contoh pernyataan di XevouZK: *"Saya punya saldo ≥ jumlah yang mau saya transfer"* —
dibuktikan **tanpa memberitahu berapa saldo sebenarnya**.

### 2.2 Tiga properti wajib

| Properti | Arti sederhana |
|---|---|
| **Completeness** (kelengkapan) | Kalau pernyataan benar & prover jujur, verifier pasti menerima. |
| **Soundness** (kesahihan) | Kalau pernyataan salah, prover penipu **tidak bisa** meyakinkan verifier (kecuali peluang sangat kecil). |
| **Zero-Knowledge** (tanpa-pengetahuan) | Verifier tidak belajar apa pun selain "pernyataan benar". Witness (data rahasia) tidak bisa diekstrak dari proof. |

### 2.3 Analogi "Gua Ali Baba"

Bayangkan gua berbentuk cincin dengan dua lorong (A dan B) yang dipisahkan pintu
berkata-sandi di ujung. Prover yang tahu sandi bisa selalu keluar dari lorong mana
pun yang diminta verifier. Setelah banyak ronde, verifier yakin prover tahu sandi —
**tanpa pernah melihat sandinya**. Peluang menipu mengecil eksponensial tiap ronde.

zk-SNARK adalah versi **non-interaktif** dari ide ini: prover cukup mengirim **satu
pesan** (proof), tidak perlu bolak-balik tanya-jawab.

### 2.4 Kenapa relevan untuk pembayaran

Pembayaran biasa mengirim data mentah: nominal, alamat penerima, saldo. ZKP
memungkinkan **memverifikasi** properti penting (saldo cukup, transaksi sah, tidak
ada double-spend) **tanpa membocorkan datanya** — inilah inti klaim privasi XevouZK.

---

## 3. Apa itu "ZKP platform" dan di mana XevouZK berdiri

**ZKP platform** = sistem aplikatif yang memakai ZKP sebagai komponen inti untuk
memberi jaminan **privasi dan/atau skalabilitas**. Secara umum ada dua kategori:

| Kategori | Tujuan utama | Contoh |
|---|---|---|
| **Privacy platform** | Menyembunyikan data transaksi | Tornado Cash, Zcash, **XevouZK** |
| **Scaling / zk-rollup** | Memampatkan ribuan tx jadi satu proof | zkSync, StarkNet, Polygon zkEVM |

**XevouZK termasuk privacy platform skala prototipe**. Polanya paling mirip
**Tornado Cash** (pool berbasis *commitment* + *nullifier*; XevouZK menambah note
terenkripsi via event, tetapi **belum** memakai gasless relayer — semua tx di-sign user),
tetapi diadaptasi untuk pembayaran **P2P bernominal** (bukan sekadar mixer dengan
denominasi tetap) dan ditambah **autentikasi Schnorr non-custodial** serta
**QR Code** sebagai antarmuka P2P.

Komponen yang membuat XevouZK layak disebut "ZKP platform":

- **Circuit** (rangkaian aritmetika yang mendefinisikan apa yang dibuktikan).
- **Prover** di sisi client (snarkjs di browser).
- **Verifier** on-chain (smart contract Solidity hasil ekspor snarkjs).
- **Trusted setup** (ceremony menghasilkan kunci proving/verifying).
- **Settlement layer** (Polygon Amoy) tempat proof diverifikasi & dana berpindah.

---

## 4. XevouZK dibangun oleh apa saja

Pembagian per lapisan, beserta **peran** tiap teknologi (bukan sekadar daftar versi).

### 4.1 Backend

| Teknologi | Peran |
|---|---|
| **Laravel 12 / PHP 8.2+** | Web app, routing, sesi, orkestrasi alur. Controller tipis; logika di `Services/`. |
| **MySQL** | Ledger operasional internal (user, wallet, transaksi history, nullifier cache). Bukan source of truth privasi — itu di blockchain. |
| **`web3p/web3.php`** | Klien RPC ke node Polygon (baca nonce, broadcast tx). |
| **`simplito/elliptic-php`, `phpseclib`, `kornrunner/keccak`** | Verifikasi Schnorr server-side + util kripto (keccak untuk address). |
| **`simplesoftwareio/simple-qrcode`** | Render QR Code. |

### 4.2 Frontend

| Teknologi | Peran |
|---|---|
| Library | Versi | Digunakan untuk |
|---|---|---|
| **Blade + Vanilla JS + Raw CSS** | — | UI tanpa framework SPA (tanpa Vue/React/Tailwind — keputusan arsitektur). |
| **Vite** | 7 | Bundler. Modul JS ber-`import` library di-bundle dari `node_modules` ke `resources/js/` (bukan CDN, bukan `public/vendor`). |
| **`vite-plugin-node-polyfills`** | 0.28 | Polyfill Node global (Buffer/process) yang dibutuhkan snarkjs/ffjavascript di browser. |
| **`snarkjs`** | 0.7.4 | **Generate proof Groth16 di browser** (`groth16.fullProve` untuk `balance_check`, `private_transfer`, `withdraw`). |
| **`circomlibjs`** | 0.1.7 | Hash **Poseidon** di JS — hitung commitment & nullifier supaya cocok dengan circuit. |
| **`ffjavascript`** | 0.3 | Aritmetika finite-field (dependency snarkjs untuk operasi BN128). |
| **`@noble/curves`** | 1.4 | secp256k1 client-side: derivasi keypair Schnorr & Polygon + sign; dasar ECIES memo. |
| **`@noble/hashes`** | 1.4 | keccak256 (alamat EIP-55) + sha256/HKDF untuk derivasi key & ECIES. |
| **`ethers.js`** | 6.13 | Tandatangani **semua** tx EIP-1559 di browser (plain, deposit, privateTransfer, withdraw) + query RPC/event. |
| **`html5-qrcode`** | 2.3.8 | Scan QR via kamera. |
| **`qrcode`** | 1.5 | Render QR (alamat / `xevouzk:` / payment request) di client. |
| **`lucide`** | 0.456 | Ikon UI (di-bundle via `vendor-lucide.js`). |

> Derivasi key & ECIES memo dibangun di atas `@noble/*` (lihat `shield-key.js`,
> `note-crypto.js`) — tidak ada library kripto eksternal lain di client.

### 4.3 Zero-Knowledge

| Teknologi | Peran |
|---|---|
| **Schnorr (secp256k1, Fiat-Shamir)** | Autentikasi login tanpa kirim password. |
| **Circom 2.1.6** | Bahasa untuk menulis circuit (`balance_check`, `private_transfer`, `withdraw`). |
| **Groth16 (snarkjs)** | Sistem proof zk-SNARK: proof kecil (~192 byte), verify cepat on-chain. |
| **Poseidon hash (circomlib)** | Hash "ramah ZK" untuk commitment & nullifier (~250 constraint vs ~25.000 untuk SHA-256). |
| **Powers of Tau `pot14`** | SRS universal (Phase 1) untuk trusted setup. |

### 4.4 Blockchain & smart contract

| Teknologi | Peran |
|---|---|
| **Solidity ^0.8.20 + Hardhat** | `ZKPayment.sol` + 3 verifier hasil ekspor snarkjs. |
| **Polygon Amoy testnet (chainId 80002)** | Settlement on-chain (pengganti Mumbai yang sudah dihentikan). |
| **Precompile EIP-197 (`0x08`)** | Pairing check yang memungkinkan verifikasi Groth16 on-chain (~250k–340k gas) — komponen dominan biaya `withdraw`/`privateTransfer` (lihat §10). |

### 4.5 Build & bundling (bagaimana semuanya dirakit)

XevouZK punya **tiga rantai build terpisah** yang menghasilkan artefak berbeda:

| Rantai | Perintah | Input → Output |
|---|---|---|
| **Frontend (Vite)** | `npm run build` | `resources/js/*` + `resources/css/app.css` → bundle ter-hash di `public/build/` (manifest dipakai `@vite([...])` di Blade). Library di-`import` dari `node_modules`, di-bundle Vite (+ node-polyfills). |
| **Circuits (Circom + snarkjs)** | `cd circuits && npm run build` | `*.circom` → R1CS + WASM (witness) + trusted setup Phase 2 → `*_final.zkey` + `verification_key.json`. Artefak runtime (`*.wasm`, `*_final.zkey`) disalin ke `public/zk/<circuit>/` agar bisa di-`fetch` browser saat `fullProve`. |
| **Smart contract (Hardhat)** | `cd contracts && npx hardhat compile` | `ZKPayment.sol` + 3 verifier (di-export snarkjs dari `verification_key.json`) → ABI + bytecode → deploy ke Amoy (`scripts/deploy.js`). |

**Alur ketergantungan** (penting dipahami untuk reproduksi): trusted setup circuit
menghasilkan `verification_key.json` → snarkjs meng-export-nya jadi Solidity
`*Verifier.sol` (konstanta VK ditanam di kontrak) → Hardhat compile & deploy. Jadi
**verifier on-chain dan zkey client harus dari setup yang sama**; kalau circuit
diubah, ketiga rantai harus di-build ulang berurutan. Di sisi runtime, browser
memuat WASM+zkey dari `public/zk/`, generate proof, lalu kontrak mem-verifikasi
dengan VK yang tertanam — keduanya berasal dari satu sumber setup.

---

## 5. Arsitektur sistem

### 5.1 Tiga lapisan kepercayaan

```
┌─────────────────────────────────────────────────────────────────────┐
│  CLIENT (browser) — TRUSTED penuh oleh user                          │
│  • password → derive private key (Schnorr + Polygon)                 │
│  • generate ZK proof (snarkjs.fullProve)                             │
│  • sign tx (ethers.js)                                               │
│  • simpan note terenkripsi (localStorage, AES-GCM)                   │
└───────────────┬─────────────────────────────────────────────────────┘
                │ HTTPS (hanya artifact publik: pubkey, proof, raw tx)
                ▼
┌─────────────────────────────────────────────────────────────────────┐
│  SERVER (Laravel) — SEMI-TRUSTED (UX, relay, ledger internal)        │
│  • verify Schnorr login                                              │
│  • struct-validate proof + cek nullifier (cache cepat)              │
│  • relay raw signed tx (semua mode; broadcast only, tidak sign)      │
│  • MySQL: history, wallet, nullifier cache                          │
│  ❌ TIDAK punya private key user                                     │
└───────────────┬─────────────────────────────────────────────────────┘
                │ JSON-RPC
                ▼
┌─────────────────────────────────────────────────────────────────────┐
│  BLOCKCHAIN (Polygon Amoy) — TRUSTED (konsensus)                     │
│  • ZKPayment.sol: deposit / privateTransfer / withdraw              │
│  • Verifier Groth16: pairing check on-chain (source of truth)       │
│  • nullifier mapping (anti double-spend canonical)                  │
└─────────────────────────────────────────────────────────────────────┘
```

### 5.2 Prinsip arsitektur yang mengikat

1. **Proof selalu di-generate di client.** Rahasia tidak boleh keluar perangkat.
2. **Verifikasi yang mengikat ada on-chain.** Verifikasi server hanya pra-cek/hint.
3. **Login Schnorr diverifikasi server-side**; pembayaran Groth16 diverifikasi on-chain.
4. **Anti double-spend via nullifier** di smart contract.
5. **Murni P2P** — tidak ada merchant, tidak ada top-up, tidak ada payment gateway.

---

## 6. Cara kerja: dua primitif ZKP

XevouZK **tidak** memakai satu protokol untuk semuanya. Tiap kebutuhan dapat
primitif yang paling pas:

| Aspek | **Schnorr** (autentikasi) | **Groth16** (pembayaran) |
|---|---|---|
| Untuk apa | Login tanpa kirim password | Saldo, transfer privat, withdraw |
| Diverifikasi di | Server Laravel | Smart contract on-chain |
| Butuh trusted setup? | ❌ Tidak | ✅ Ya (per circuit) |
| Menyembunyikan witness kompleks? | ❌ Hanya bukti punya private key | ✅ Saldo, nominal, identitas |
| Ukuran | ~65 byte | ~192 byte (apa pun ukuran circuit) |
| Kurva / hash | secp256k1 / SHA-256 | BN128 / Poseidon |

**Intuisi memilih:** login tidak perlu menyembunyikan "rahasia kompleks" dan tidak
perlu settle on-chain → Schnorr (ringan, tanpa setup). Pembayaran perlu
menyembunyikan saldo/nominal **dan** diverifikasi publik → Groth16 (succinct,
on-chain verifiable). Detail matematis: [ZK-SNARK-DOCUMENTATION §3–4](ZK-SNARK-DOCUMENTATION.md).

---

## 7. Konsep inti dan kenapa ada

Bagian ini menjelaskan "Lego block" yang dipakai berulang. §7.1–7.6 memberi tiap
konsep dalam format **apa** + **kenapa**; §7.7–7.9 adalah *deep-dive* yang
menyatukannya menjadi mesin privasi XevouZK: **model commitment-pool** (mint/burn/
change note), **alasan tidak memakai Merkle tree**, dan **batas privasi
(anonymity set & linkability)** yang harus diakui jujur untuk pembelaan TA.

### 7.1 Commitment

- **Apa**: hash satu-arah yang **mengikat** sekumpulan nilai (sebagian rahasia)
  menjadi satu angka opaque. Di XevouZK commitment note memakai **skema terpadu
  3-input**: `commitment = Poseidon(amount, shieldPub, salt)`, di mana
  `shieldPub = Poseidon(shieldPriv)` dan `shieldPriv` adalah kunci rahasia user
  (di-derive dari `email+password`, sama untuk semua note milik user itu). Sifat penting:
  - **Hiding** — dari commitment saja, mustahil menebak `amount`/`shieldPub`/`salt`
    (Poseidon one-way + `salt` acak ≥128-bit membuat brute-force tak layak).
  - **Binding** — pembuat tak bisa mengklaim commitment yang sama membuka ke nilai
    lain di kemudian hari (tabrakan Poseidon dianggap infeasible).
- **Kenapa**: on-chain XevouZK **hanya** menaruh commitment ini (di mapping
  `activeCommitments`). Pengamat blockchain hanya melihat satu angka acak 254-bit
  yang tak bermakna — **nominal & pemilik tetap rahasia**. Pemilik bisa "membuka"
  commitment-nya nanti lewat ZK proof (membuktikan ia tahu `amount, shieldPriv, salt`
  yang menghasilkan commitment itu) **tanpa** membongkar ketiganya.
- **Catatan presisi**: yang ditaruh di commitment adalah `shieldPub` (kunci publik),
  bukan kunci rahasia langsung. Rahasia sebenarnya adalah `shieldPriv` — dipakai untuk
  membuktikan `shieldPub = Poseidon(shieldPriv)` dan membentuk nullifier. Jadi
  kepemilikan = "tahu `shieldPriv`", bukan sekadar "tahu isi commitment".
- **Kenapa pakai `salt` selain kunci**: tanpa `salt`, dua note bernilai sama dari
  user yang sama (mis. dua deposit 1 MATIC) akan menghasilkan commitment identik →
  bocor bahwa nominalnya sama. `salt` acak per-note membuat setiap commitment unik.
- **Analogi**: commitment seperti **brankas terkunci yang ditempel label hash**.
  Semua orang lihat brankasnya ada, tak ada yang tahu isinya; hanya pemegang
  `shieldPriv` (+ `salt`) yang bisa membuktikan "isi brankas ini ≥ X" tanpa membukanya.
- **Varian commitment di sistem** (semua **3-input deposit-shaped**, terverifikasi
  dari `circuits/private_transfer.circom:75-79` & `circuits/withdraw.circom:34-38`):

  | Commitment | Rumus aktual | Dipakai di |
  |---|---|---|
  | Note balance (pengirim) | `Poseidon(amount, senderShieldPub, salt)` | deposit, note lama |
  | Change note (kembalian) | `Poseidon(change, senderShieldPub, changeSalt)` | output diri di `privateTransfer` |
  | Recipient commitment | `Poseidon(amount, recipientShieldPub, recipientSalt)` | output penerima di `privateTransfer` |
  | Note withdraw | `Poseidon(amount, shieldPub, salt)` | `withdraw` |

### 7.2 Nullifier

- **Apa**: tag deterministik "sekali pakai" yang muncul saat sebuah note
  **dibelanjakan**, `nullifier = Poseidon(shieldPriv, commitment)`. Untuk note yang
  sama, nullifier selalu bernilai sama (deterministik), tetapi **tidak bisa
  dihitung mundur** ke `shieldPriv` maupun `commitment` asalnya (one-way).
- **Kenapa**: mencegah **double-spending**. Saat note dibelanjakan, kontrak
  mencatat nullifier-nya di `mapping(bytes32 => bool) nullifiers`. Upaya
  membelanjakan note yang sama lagi menghasilkan nullifier **identik** → kontrak
  `require(!nullifiers[n])` gagal → tx revert. Penyerang tak bisa memalsukan
  nullifier baru untuk note lama karena `shieldPriv` adalah **private input** circuit:
  proof hanya valid bila nullifier benar-benar `Poseidon(shieldPriv, commitment)`.
- **Kenapa nullifier dipisah dari commitment (tidak cukup hanya hapus commitment)**:
  burn commitment memang menandai note "mati" di `activeCommitments`, tapi nullifier
  memberi lapisan kedua yang **tidak bergantung pada urutan/keberadaan commitment**
  — ini pola standar mixer agar replay proof yang sudah pernah dipakai tetap ditolak
  meski state commitment berubah.
- **Pemisahan peran commitment vs nullifier**:

  | | Commitment | Nullifier |
  |---|---|---|
  | Muncul saat | note **dibuat** (deposit/mint) | note **dibelanjakan** (spend) |
  | Peran | "lahir" — menyimpan nilai rahasia | "mati" — mencegah pakai-ulang |
  | Disimpan di | `activeCommitments` | `nullifiers` |
  | Bocorkan identitas? | Tidak (opaque) | Tidak (one-way) |

### 7.3 Note & pool

- **Apa itu note**: satuan saldo privat — analog **"koin"** atau **UTXO**
  (Unspent Transaction Output) ala Bitcoin, bukan saldo angka tunggal ala rekening
  bank. Sebuah note ditentukan oleh empat nilai, tetapi **tidak semuanya disimpan** —
  `shieldPriv` di-derive ulang dari password, bukan disimpan per-note:

  | Nilai | Sifat | Lokasi | Fungsi |
  |---|---|---|---|
  | `commitment` | publik (opaque) | on-chain (`activeCommitments`) **&** disimpan di browser | "wadah" note di pool |
  | `amount` | rahasia | browser (note terenkripsi) | nilai note (wei) |
  | `salt` | rahasia | browser (note terenkripsi) | pengacak per-note agar commitment unik |
  | `shieldPriv` | rahasia | **tidak disimpan** — di-derive dari `email+password` tiap sesi | bukti kepemilikan + bahan nullifier (`shieldPub = Poseidon(shieldPriv)`) |

  Jadi note terenkripsi di localStorage berisi `{amount, salt, commitment}`; kunci
  rahasianya (`shieldPriv`) **sama untuk semua note** milik user dan lahir-ulang dari
  password — bukan rahasia acak per-note. (Terverifikasi: `polygon-transfer.js` membaca
  `note.amount_wei/salt/commitment` lalu memanggil `deriveShieldKeypair(email,password)`.)

- **Apa itu pool**: kumpulan **semua commitment yang masih hidup** di
  `ZKPayment.sol`, disimpan sebagai `mapping(uint256 => bool) activeCommitments`.
  `true` = note hidup (bisa dibelanjakan), `false`/tak-ada = belum ada atau sudah
  di-burn. Pool ini adalah satu-satunya representasi nilai privat on-chain.
- **Model UTXO, bukan saldo**: "saldo" user sebetulnya = **jumlah semua note
  miliknya** yang belum dibelanjakan (dijumlahkan di browser dari note di
  localStorage). Tidak ada satu angka "saldo" on-chain. Konsekuensi: untuk membayar,
  user **membelanjakan note** (burn) dan **membuat note baru** (mint) — persis pola
  UTXO. (Inilah kenapa ada "change note" / note kembalian — lihat §7.7.)
- **Kenapa dipisah begini**: memisahkan "bagian publik" (commitment opaque di pool)
  dari "rahasia pembukanya" (`amount, salt` di perangkat + `shieldPriv` dari password).
  Pemisahan ini yang membuat transfer & withdraw bisa privat: pool membuktikan note
  itu *ada & sah*, tanpa pool (atau siapa pun selain pemilik) tahu *isinya*.
- **Di mana rahasia disimpan**: `{amount, salt, commitment}` di-bundle jadi `note`,
  lalu dienkripsi **AES-256-GCM** dengan kunci dari **PBKDF2(`password`+`email`)**, dan
  disimpan di `localStorage` browser (`xevouzk_notes_<address>`). Server **tidak
  pernah** memegang plaintext ini. Risiko "**ganti/clear browser = kehilangan note**"
  kini **dimitigasi** oleh **backup note terenkripsi lintas-device** (§7.10): blob
  AES-GCM yang sama di-push ke server dengan `ref` opaque, lalu di-pull & decrypt di
  device lain saat login. Penemuan-ulang via scan event (§7.5) tetap jadi jaring
  pengaman. Catatan: backup **tidak** menyelamatkan note bila *password lupa* (kunci
  AES turun dari password) — recovery dari mnemonic (BIP-39) tetap *future work*.

> ⚠️ **Catatan akurasi (jangan tertukar)**: pola ini mengadopsi **mekanisme
> commitment-pool ala Tornado Cash** (commitment + nullifier + burn/mint), **tetapi
> tanpa Merkle tree / anonymity set** yang menjadi inti privasi Tornado. Lihat §7.8
> dan §7.9 untuk perbedaan ini dan konsekuensinya — penting agar klaim TA tidak
> overclaim "seanonim Tornado".

### 7.4 Trusted setup

- **Apa**: ceremony menghasilkan **Structured Reference String (SRS)** — kunci yang
  dipakai untuk generate & verify proof Groth16. Dua fase: **Phase 1 (Powers of
  Tau)** universal & reusable; **Phase 2** spesifik per circuit.
- **Kenapa perlu**: Groth16 secara matematis membutuhkan SRS ini agar proof bisa
  succinct & cepat diverifikasi. (Lihat §10 untuk penjelasan "toxic waste".)

### 7.5 Encrypted note & penemuan dana (event scanning)

- **Apa**: saat `privateTransfer`, note untuk penerima dikirim sebagai **memo
  terenkripsi (ECIES)** lewat event on-chain `EncryptedNote(recipientCommitment,
  memo)`. Penerima memindai event ini dan mencoba men-decrypt tiap memo dengan
  enc-key-nya; yang berhasil = note miliknya.
- **Kenapa**: penerima tidak punya saluran langsung dari pengirim, dan on-chain
  tidak boleh ada plaintext. Event terenkripsi jadi "kotak surat publik" — hanya
  pemilik kunci yang bisa membaca isinya. (Di XevouZK: `/dashboard` → "Cek
  Transfer Masuk" → `scanIncomingNotes`.)
- **Catatan implementasi**: pembacaan event (`eth_getLogs`) tidak dilakukan langsung
  dari browser melainkan lewat **proxy server `/payment/scan-rpc`** — RPC publik gratis
  membatasi getLogs historis, jadi proxy meneruskan ke RPC ber-API-key tanpa membocorkan
  kuncinya ke klien. **Trial-decrypt memo tetap 100% di browser** (server hanya mengambil
  bytes event terenkripsi yang toh sudah publik on-chain).

### 7.6 Gasless relayer (pola opsional — belum dipakai)

- **Apa**: pihak ketiga yang **membayar gas dan mengirim** transaksi atas nama
  user, sehingga di explorer `msg.sender` = relayer, **bukan** user.
- **Status di XevouZK**: **tidak dipakai.** `privateTransfer` & `withdraw`
  ditandatangani **dan dibayar gas oleh user sendiri** (`msg.sender = user`).
  Artinya *fakta* user bertransaksi terlihat, tetapi **nominal & penerima tetap
  tersembunyi**. Karena kontrak `privateTransfer` **tidak mengecek `msg.sender`**,
  relayer bisa ditambahkan kelak (perubahan sisi client) untuk juga menyembunyikan
  metadata sender — itu sebabnya dicatat sebagai future work. (Lihat §10 & §12.)

### 7.7 Model commitment-pool secara mendalam (mint, burn, change note)

Ini "mesin" inti XevouZK. Semua privasi pembayaran bermuara ke sini, jadi pahami
betul alurnya.

**Dua operasi primitif pada pool:**

| Operasi | Arti | Efek on-chain |
|---|---|---|
| **Mint** | "Menciptakan" note baru | `activeCommitments[c] = true` |
| **Burn** | "Menghanguskan" note lama | `activeCommitments[c] = false` + catat `nullifier` |

Setiap transaksi privat adalah kombinasi burn (note lama) + mint (note baru),
dijaga konsisten oleh ZK proof. Yang penting: kontrak **tidak pernah tahu nilai**
note mana pun — ia hanya memindahkan flag boolean dan mempercayai proof bahwa
"jumlah masuk = jumlah keluar" tervalidasi di dalam circuit.

**Siklus hidup nilai (deposit → transfer → withdraw):**

```
[1] DEPOSIT (fiat ramp, PUBLIK)
    user kirim 1 MATIC  ──▶  mint commitmentA = Poseidon(1e18, secretA, saltA)
    on-chain: Deposit(user, commitmentA, 1 MATIC)   ← nilai & user TERLIHAT

[2] PRIVATE TRANSFER (privat) — bayar 0,3 ke Bob
    BURN  commitmentA                                ← note 1 MATIC dihanguskan
    MINT  changeCommitment = Poseidon(0,7, aliceShieldPub, changeSalt)   ← kembalian utk diri
    MINT  recipientCommitment = Poseidon(0,3, bobShieldPub, recipientSalt) ← note utk Bob
    nullifierA = Poseidon(aliceShieldPriv, commitmentA) dicatat
    on-chain: PrivateTransfer(nullifierA, commitmentA, change, recipient)
              EncryptedNote(recipientCommitment, memoTerenkripsi)
    explorer hanya lihat hash opaque — NOMINAL (0,3 / 0,7) & identitas Bob TERSEMBUNYI

[3] WITHDRAW (fiat ramp, PUBLIK) — Bob cairkan notenya
    BURN  commitment Bob                              ← full-burn
    transfer 0,3 MATIC ──▶ bobAddress
    on-chain: Withdraw(nullifierB, bobAddress, 0,3)   ← penerima & nominal TERLIHAT
```

**Kenapa ada "change note" (kembalian):** karena model **UTXO** (§7.3) — note tidak
bisa dibelah "setengah" di tempat. Membayar 0,3 dari note 1 MATIC berarti: hanguskan
note 1 MATIC seluruhnya, lalu cetak dua note baru (0,3 untuk penerima, 0,7 kembali ke
pengirim). Persis seperti membayar barang Rp7.000 dengan uang Rp10.000 lalu menerima
kembalian Rp3.000 — uang Rp10.000-nya "hangus" sebagai satu kesatuan.

**Invarian nilai dijaga di mana:** kesetaraan `senderBalance = amount + newBalance`
(`newBalance ≥ 0`) **dibuktikan di dalam `private_transfer.circom`** (§5.2 / lihat
ZK-SNARK-DOCUMENTATION), bukan dicek kontrak. Kontrak hanya memverifikasi proof +
nullifier; aritmetika nilai sepenuhnya di circuit. Inilah cara "jumlah masuk = jumlah
keluar" dijamin tanpa kontrak pernah melihat angkanya.

**Penemuan note penerima:** Bob tidak otomatis tahu ia menerima note. Pengirim
menitipkan `{amount_wei, salt, commitment}` sebagai **memo terenkripsi ECIES** di event
`EncryptedNote`. Bob memindai event, trial-decrypt tiap memo dengan enc-key-nya; yang
berhasil = notenya (§7.5). Tanpa langkah ini, note penerima "ada" di pool tapi tak
bisa dibelanjakan karena Bob tak tahu `salt`-nya.

#### 7.7.1 Skema note terpadu — bagaimana penerima membelanjakan notenya (terverifikasi)

Pertanyaan teknis yang wajar ditanyakan penguji: *"`recipientCommitment` itu note
yang berbeda — bagaimana penerima bisa withdraw-nya? Apakah ada langkah konversi?"*
**Jawaban: tidak ada konversi. Semua note memakai satu skema yang sama.**

Diverifikasi langsung dari source repo (`circuits/private_transfer.circom:75-79`,
`circuits/withdraw.circom:34-44`, `resources/js/polygon-transfer.js`, dan e2e test
`contracts/scripts/test-transfer-e2e.js`): **setiap** commitment — note pengirim,
change note, maupun recipient note — berbentuk **`Poseidon(amount, shieldPub, salt)`**
(deposit-shaped). Komentar di `private_transfer.circom` menyatakannya eksplisit:
*"Keduanya deposit-shaped sehingga bisa di-withdraw via withdraw.circom."*

```
recipientCommitment = Poseidon(transferAmount, recipientShieldPub, recipientSalt)
                                                  └ = Poseidon(recipientShieldPriv)
```

Karena bentuknya sudah standar, penerima cukup memakai circuit `withdraw` (atau
`private_transfer`) **apa adanya**. Bahan yang ia butuhkan untuk membuka note:

| Bahan untuk membuka note | Penerima dapat dari mana |
|---|---|
| `amount` (= transferAmount) | memo ECIES (di-decrypt dengan enc-priv-key-nya) |
| `salt` (= recipientSalt) | memo ECIES (di-decrypt) |
| `shieldPub` (= recipientShieldPub) | derive sendiri dari `email+password` |
| `shieldPriv` (= recipientShieldPriv) | derive sendiri dari `email+password` |

Saat withdraw, circuit membuktikan `commitment = Poseidon(amount, shieldPub, salt)`
**dan** `nullifier = Poseidon(shieldPriv, commitment)` → kontrak burn commitment +
transfer MATIC. Selesai, tanpa re-mint.

**Kenapa ini aman (siap untuk panel):**
- **Pengirim tak bisa mencuri note yang ia kirim.** Pengirim tahu `recipientShieldPub`,
  `recipientSalt`, `transferAmount` (ia yang membuatnya), jadi tahu nilai
  `recipientCommitment`. Tapi untuk membelanjakannya harus menghitung
  `nullifier = Poseidon(recipientShieldPriv, …)` — dan pengirim **tak punya
  `recipientShieldPriv`** (hanya pub-nya). Jadi tidak bisa. ✅
- **Penerima wajib decrypt memo dulu.** Penerima bisa derive `shieldPriv/shieldPub`-nya
  sendiri, tetapi **tidak tahu `recipientSalt`** (dipilih acak oleh pengirim, hanya
  dikirim via memo ECIES). Tanpa `salt`, ia tak bisa merekonstruksi commitment → tak
  bisa spend. Itu sebabnya `scanIncomingNotes` + ECIES bersifat wajib, bukan sekadar
  notifikasi.

> ✅ **Konsistensi dokumen**: `ZK-SNARK-DOCUMENTATION.md §5` sudah memakai skema
> 3-input `Poseidon(amount, shieldPub, salt)` (`shieldPub = Poseidon(shieldPriv)`)
> dan naming `shieldPriv/shieldPub` + `newSelfCommitment` yang sama dengan dokumen
> ini dan dengan source `.circom`. Tidak ada lagi rumus 2-input `recipientAddress` lama.

### 7.8 Merkle tree: kenapa XevouZK TIDAK memakainya

Pertanyaan yang hampir pasti muncul: *"Sistem pool ini pakai Merkle tree seperti
Tornado Cash / Zcash?"* — **Tidak.** Memahami perbedaan ini = memahami batas privasi
XevouZK secara jujur.

**Apa itu Merkle tree dalam konteks mixer privasi:**
Merkle tree adalah pohon hash di mana setiap commitment menjadi **daun (leaf)**, dan
puncaknya satu **root**. Pada Tornado Cash/Zcash, saat membelanjakan note, prover
membuktikan **di dalam ZK proof** bahwa "commitment saya ada di pohon dengan root R"
**tanpa mengungkap daun yang mana** (jalur Merkle = private witness). On-chain yang
muncul hanya `root` + `nullifier`. Karena commitment yang dibelanjakan **tidak pernah
ditunjuk secara eksplisit**, semua daun dalam pohon menjadi tersangka setara — inilah
**anonymity set**.

**Apa yang dipakai XevouZK sebagai gantinya:**
`mapping(uint256 => bool) activeCommitments` — daftar datar (flat). Untuk
membelanjakan, `senderCommitment` di-**sebut eksplisit** sebagai *public signal*, dan
kontrak mengecek `require(activeCommitments[senderCommitment])` lalu mem-burn-nya.
Tidak ada pembuktian keanggotaan secara zero-knowledge; commitment yang dipakai
terlihat terang di event.

**Perbandingan langsung:**

| Aspek | Merkle-tree mixer (Tornado/Zcash) | Flat mapping (XevouZK) |
|---|---|---|
| Bukti keanggotaan | Merkle path **di dalam** circuit (ZK) | `require(mapping[c])` — eksplisit |
| Commitment yang di-spend | **Tidak terungkap** (hanya root) | **Terungkap** (public signal + event) |
| Anonymity set | Seluruh daun pohon | **Tidak ada** (lihat §7.9) |
| Constraint circuit | Berat (hash sepanjang kedalaman pohon) | Ringan (tanpa Merkle path) |
| Kompleksitas implementasi | Tinggi (sinkronisasi root, path) | Rendah |
| Gas | Lebih berat | Lebih ringan |

**Kenapa XevouZK memilih flat mapping (trade-off yang diambil):**
- **Kesederhanaan & skala prototipe TA** — tanpa pengelolaan root, indexing daun,
  dan path proof; cocok untuk membuktikan konsep dalam batas waktu TA.
- **Circuit lebih kecil** — tanpa hashing Merkle path (yang bisa menambah ratusan
  hingga ribuan constraint per level), proof lebih cepat dibuat di browser.
- **Konsekuensi yang diterima**: **kehilangan anonymity set** → graf commitment dapat
  ditelusuri (§7.9). Ini keputusan desain sadar, bukan kelalaian — **tetapi harus
  diakui jujur** dalam laporan, bukan ditutupi dengan klaim "seanonim Tornado".

> **Untuk laporan**: posisikan XevouZK sebagai *"sistem pembayaran privat berbasis
> commitment-pool yang menyembunyikan **nominal dan identitas penerima** dari
> pengamat blockchain"* — **bukan** *"mixer dengan anonymity set ala Tornado Cash"*.
> Adopsi Merkle tree untuk anonymity set adalah jalur *future work* yang jelas.

### 7.9 Anonymity set & linkability (batas privasi yang HARUS diakui)

Ini subbagian paling rawan overclaim. Definisikan dulu:

- **Anonymity set** = himpunan kandidat yang sama-sama mungkin sebagai pelaku suatu
  aksi. Makin besar, makin privat ("bersembunyi di keramaian"). XevouZK **tidak
  membangun anonymity set** karena tanpa Merkle membership proof (§7.8).
- **Linkability** = kemampuan pengamat menautkan dua kejadian (mis. deposit ↔
  withdraw, atau pengirim ↔ penerima).

**Apa yang benar-benar tersembunyi di XevouZK (klaim AMAN):**
1. **Nominal** setiap note privat — semua commitment opaque; explorer tak tahu
   0,3 vs 0,7 vs 30 MATIC.
2. **Identitas penerima saat transfer** — `recipientCommitment` opaque; pengamat
   tak tahu note itu untuk siapa **selama belum di-withdraw**.

**Apa yang TIDAK tersembunyi (batas yang harus diakui):**
1. **Graf commitment (lineage)** — event `PrivateTransfer(nullifier, old, new,
   recipient)` meng-emit commitment lama (`old`) dan baru secara **publik**. Maka
   rantai "commitmentA → {change, recipientCommitment}" dapat direkonstruksi sebagai
   graf berarah on-chain. Struktur "siapa-cetak-dari-mana" terlihat, **walau
   nilainya tidak**.
2. **`msg.sender`** tiap tx privat = alamat user (tx di-sign sendiri; tak ada
   relayer) — *fakta* bertransaksi terlihat (§7.6).
3. **Ujung fiat-ramp** — `deposit` membuka (user, nominal); `withdraw` membuka
   (penerima, nominal). Kedua ujung ini publik **by design**.

**Implikasi untuk klaim "deposit ↔ withdraw unlinkable":**
Pada Tornado, unlinkability deposit↔withdraw berasal dari anonymity set Merkle tree.
XevouZK **tidak punya** itu, dan commitment yang dibelanjakan tampil eksplisit di
event di **setiap** hop. Maka:

> ⚠️ **Hati-hati klaim ini.** Karena graf commitment publik dan tiap hop menyebut
> commitment yang di-burn, penelusuran rantai deposit → transfer → withdraw secara
> prinsip **mungkin** dilakukan analis on-chain (terutama bila jumlah user/transaksi
> sedikit sehingga tak ada keramaian penyamar). Yang tetap tersembunyi sepanjang
> rantai adalah **nominal** dan **identitas antara** — bukan keberadaan tautannya.
> Jadi **jangan klaim "deposit↔withdraw unlinkable" sekuat Tornado**. Klaim yang
> aman: *"nominal dan identitas penerima transfer tidak terlihat di explorer; namun
> graf commitment dan kedua ujung fiat-ramp bersifat publik, dan XevouZK belum
> menyediakan anonymity set."*

**Cara menaikkan privasi (future work, jujur):** (1) Merkle tree + membership proof
untuk anonymity set; (2) gasless relayer untuk menyembunyikan `msg.sender`; (3)
denominasi tetap + delay acak agar pola nominal/timing tak membocorkan tautan.

### 7.10 Backup note & account guard (lintas-device)

Dua fitur pendukung yang menjawab masalah praktis "note hanya ada di satu browser"
tanpa mengorbankan model non-custodial.

**Backup note terenkripsi (zero-knowledge ke server):**
- **Masalah**: note pool hidup di `localStorage` perangkat → clear/ganti browser =
  note hilang (kecuali ditemukan-ulang via scan event).
- **Solusi**: browser mem-push **ciphertext AES-GCM yang identik dengan blob
  localStorage** (kunci = PBKDF2 dari `password+email`) ke server, ditandai `ref`
  **opaque** = `sha256("xevou-note-backup-v1:" + commitment + ":" + salt)`. Server
  menyimpan `{user_id, ref, ciphertext}` di tabel `note_backups` — **tidak pernah**
  melihat plaintext, salt, nominal, atau commitment mentah.
- **Sinkron dua-arah** (`syncOnLogin`, butuh password): **PULL** decrypt blob server →
  merge ke localStorage bila belum ada; **SWEEP** push note lokal yang belum ada di
  server. Antrian gagal-kirim di-flush best-effort saat dashboard load (`flushPending`,
  tanpa password).
- **Batas jujur**: ini mitigasi *ganti/hilang device*, **bukan password recovery** —
  lupa password tetap berarti note tak bisa di-decrypt. Detail privasi server:
  [PRIVACY-GAP-ANALYSIS §3.J](PRIVACY-GAP-ANALYSIS.md).

**Account guard (verifikasi password tanpa kirim ke server):**
- **Masalah**: karena semua kunci di-derive dari `(email, password)`, password salah
  **tidak gagal** — ia diam-diam menurunkan *identitas berbeda* (note milik orang lain
  takkan muncul; operasi bisa salah-alamat).
- **Solusi**: sebelum operasi sensitif (deposit, withdraw, transfer privat, reveal
  saldo), browser derive Schnorr pubkey dari `(email, password)` lalu cocokkan dengan
  `zk_public_key` akun yang login (nilai **publik**, ditanam di `<meta
  name="account-schnorr-pub">`). Tak cocok → operasi dibatalkan **di klien**; password
  tetap tak pernah dikirim ke server.

---

## 8. Alur end-to-end (ringkas)

Diagram detail (sequence) ada di [ZK-SNARK-DOCUMENTATION §8](ZK-SNARK-DOCUMENTATION.md#8-alur-end-to-end).
Ringkasan naratif:

1. **Register (non-custodial)** — browser derive dua keypair dari `(email,
   password)`. Server hanya menerima **public key + address** — **password tidak
   dikirim**; `users.password` diisi hash acak placeholder. Tidak ada kolom
   `encrypted_private_key`.
2. **Login (Schnorr)** — browser tanda tangani `email|timestamp|csrf` dengan
   Schnorr key. Server verify (anti-replay 5 menit + single-use nonce + CSRF +
   rate limit). `Auth::attempt` sudah dihapus — password tidak dikirim.
3. **Deposit ke pool** — browser hitung `commitment`, simpan note terenkripsi,
   sign tx `deposit{value}(commitment)`, relay. Ini titik "fiat ramp" yang memang
   publik (`msg.sender = user`).
4. **Transfer privat (ZK)** — pengirim scan **QR Privat (zkpub)** penerima, browser
   generate proof Groth16 + memo ECIES, server pra-validasi (`/payment/transfer/verify`),
   lalu **user sign sendiri** `privateTransfer` → `/payment/relay`. On-chain: pairing
   check, burn commitment lama, mint commitment kembalian + penerima, catat nullifier,
   emit `EncryptedNote`. Explorer hanya melihat 4 hash Poseidon + memo terenkripsi;
   `msg.sender = user` (nominal & penerima tetap tersembunyi).
4b. **Terima (penemuan note)** — penerima `/dashboard` → "Cek Transfer Masuk"
   memindai event `EncryptedNote`, trial-decrypt memo, simpan note yang cocok.
5. **Withdraw (full-burn)** — browser decrypt note, generate proof withdraw,
   preview validity, sign & relay tx `withdraw`. Kontrak transfer MATIC ke
   recipient (recipient & amount sengaja publik — ujung "fiat ramp").
6. **QR Code P2P** — *static* (alamat saja) untuk receive ad-hoc; *dynamic*
   (`amount`+`description` + HMAC, expire 15 menit) untuk payment request.

---

## 9. Apa yang privat vs publik

Ini **klaim privasi paling penting untuk TA** — harus akurat. Detail per field:
[PRIVACY-GAP-ANALYSIS.md](PRIVACY-GAP-ANALYSIS.md).

| Data | Di explorer publik | Di server/DB (trusted) | Di client |
|---|---|---|---|
| Saldo sender | ❌ hanya commitment Poseidon | ⚠️ shadow cleartext (UX) | ✅ |
| Nominal transfer privat | ❌ hanya commitment | ❌ **NULL** di `transactions.amount` (tak pernah dikirim) | ✅ |
| Identitas penerima (mode privat) | ❌ hanya `recipientCommitment` | ⚠️ FK `receiver_wallet_id` (tanpa tautan ke pengirim — M1) | ✅ |
| Identitas sender (mode privat) | ⚠️ `msg.sender = user` — *fakta* tx terlihat, tapi nominal & penerima tidak | ✅ | ✅ |
| Password | ❌ tidak pernah | ❌ tak pernah dikirim; `users.password` = hash acak placeholder | ✅ transient (hanya derive key) |
| Private key Polygon/Schnorr | ❌ | ❌ **tidak pernah ada** | ✅ derive per sesi |
| Note backup | ❌ tidak (off-chain) | ⚠️ ciphertext AES-GCM + `ref` opaque (server tak bisa decrypt) | ✅ plaintext note |
| Deposit & withdraw | ✅ publik (fiat ramp) | ✅ | ✅ |

**Inti**: privasi XevouZK adalah **"tersembunyi dari pengamat blockchain publik"**,
**bukan** "terenkripsi end-to-end dari server". Server tetap trusted untuk ledger
UX. Mengakui ini membuat klaim TA jauh lebih kuat saat diuji.

---

## 10. Penjelasan istilah yang sering ditanya

Penjelasan naratif untuk istilah yang paling sering jadi pertanyaan penguji.

### "Trusted setup itu apa, dan kenapa 'trusted'?"

Groth16 butuh sepasang kunci (proving + verification) yang dihasilkan dari sebuah
angka rahasia acak **τ (tau)**. Begitu kunci dibuat, **τ harus dihancurkan**. Kenapa
"trusted"? Karena siapa pun yang masih menyimpan τ bisa **membuat proof palsu** yang
lolos verifikasi. Sisa-sisa τ inilah yang disebut **"toxic waste"** (limbah
beracun).

- **Phase 1 (Powers of Tau)**: universal, bisa dipakai banyak circuit. XevouZK
  memakai `pot14` dari ceremony **Hermez** (multi-party — banyak orang menyumbang
  keacakan, jadi aman selama **satu** peserta jujur menghancurkan bagiannya).
- **Phase 2**: spesifik per circuit. Di XevouZK ini dijalankan **single-party**
  (satu komputer) untuk prototipe TA.

**Konsekuensi jujur untuk TA**: karena Phase 2 single-party, validitas proof
bergantung pada asumsi bahwa pihak yang menjalankan setup menghancurkan toxic
waste-nya. Untuk production butuh ceremony Phase 2 **multi-party**. (Jangan klaim
"trusted setup multi-party" — itu hanya benar untuk Phase 1.)

### "Gasless relayer itu apa?"

Pihak yang membayar gas dan mengirim transaksi **atas nama** user, supaya di
explorer `msg.sender` = relayer (pola Tornado Cash). **Di XevouZK ini belum
dipakai** — `privateTransfer` & `withdraw` ditandatangani **dan dibayar gas oleh
user sendiri**, jadi `msg.sender = alamat user`. Konsekuensinya: *fakta* bahwa
user melakukan transaksi privat terlihat, tetapi nominal & penerima tetap
tersembunyi (commitment Poseidon + memo ECIES). Fungsi `privateTransfer` sengaja
**tidak memeriksa pemanggil** (keabsahan 100% dari ZK proof + nullifier), jadi
menambah relayer kelak = perubahan sisi client saja, tanpa redeploy. Itu
sebabnya relayer dicatat sebagai *future work* untuk menyembunyikan metadata
sender (§12).

### "Non-custodial itu maksudnya?"

Server **tidak menyimpan private key user** dan **tidak bisa** bertindak atas nama
user di mode plain. Private key lahir di browser dari `(email, password)` setiap
sesi, dipakai menandatangani, lalu dibuang dari memori. Konsekuensinya: **lupa
password = wallet hilang permanen** (belum ada recovery BIP-39).

### "Nullifier vs commitment — bedanya?"

**Commitment** menyembunyikan sebuah nilai (note) dan ditaruh saat *deposit/mint*.
**Nullifier** adalah "stempel pembatalan" yang muncul saat note itu *dibelanjakan*,
mencegahnya dipakai dua kali. Keduanya hash Poseidon, tapi peran berlawanan: satu
"membuat", satu "menghanguskan".

### "Kenapa Poseidon, bukan SHA-256?"

Di dalam circuit, SHA-256 butuh ~25.000 constraint per hash; Poseidon hanya ~250.
Poseidon dirancang ramah-aritmetika (operasi field add/mul + S-box `x^5`), jadi
circuit jauh lebih kecil → proof lebih cepat dibuat. SHA-256 tetap dipakai di
Schnorr karena itu **di luar** circuit (software biasa).

### "zk-SNARK vs zk-STARK — kenapa pilih SNARK?"

STARK tidak butuh trusted setup, tapi proof-nya ~100 KB — terlalu besar &
mahal untuk diverifikasi on-chain di EVM. SNARK Groth16 proof-nya ~192 byte dengan
verifikasi ~250k gas. Untuk settlement on-chain di Polygon, SNARK menang telak.

### "Full-burn (withdraw) itu kenapa?"

`withdraw` memaksa `amount == balance` note (tarik penuh, tidak boleh sebagian).
Ini menyederhanakan state machine. Untuk menarik sebagian, user lebih dulu
`privateTransfer` ke dirinya sendiri untuk memecah note jadi lebih kecil — sama
seperti pola Tornado Cash.

### "Kenapa withdraw kena gas besar tapi deposit hampir tidak? (note kecil jadi tak ekonomis)"

Ini **bukan komisi/fee protokol** — XevouZK tidak memungut komisi. Yang terlihat
adalah **biaya gas jaringan**, dan asimetrinya berasal dari perbedaan *apa yang
dikerjakan kontrak on-chain*:

| Operasi | Gas used (real, Amoy) | Verifikasi Groth16 on-chain? | Fee @ 35 gwei |
|---|---|---|---|
| `deposit` | **74.963** | ❌ Tidak (hanya catat commitment) | ~0,0026 MATIC |
| `withdraw` | **427.667** | ✅ Ya (1 pairing check penuh) | ~0,0150 MATIC |
| `privateTransfer` | **478.101** | ✅ Ya (1 pairing check penuh) | ~0,0167 MATIC |

> Angka di atas diukur langsung dari receipt tx e2e di Amoy (2026-06-13) via
> `eth_getTransactionReceipt`, `effectiveGasPrice = 35 gwei`. Reproduksi:
> [TESTING-EVIDENCE §2](TESTING-EVIDENCE.md#2-bukti-gas-riil-on-chain-polygon-amoy).

**Akar sebabnya:**

- `deposit(commitment)` ([ZKPayment.sol](../contracts/contracts/ZKPayment.sol))
  hanya melakukan beberapa `require`, set **satu** entri `activeCommitments`, tambah
  `totalDeposited`, dan emit event. **Tidak ada proof yang diverifikasi** → murah.
- `withdraw(...)` dan `privateTransfer(...)` **wajib** menjalankan
  `verifyProof(...)` → di EVM ini memanggil precompile pairing **EIP-197 (`0x08`)**
  plus beberapa `ecMul`/`ecAdd`. Inilah komponen yang mendominasi biaya
  (~250k–340k gas dari total). Deposit melewatkan langkah ini sepenuhnya.

**Kenapa note 0,0001 jadi "mahal":** biaya verifikasi Groth16 itu **konstan** —
tidak tergantung nominal note. Jadi gas withdraw ≈ 0,015 MATIC apakah notenya
berisi 1 MATIC atau 0,0001 MATIC. Untuk note besar, fee jadi persentase kecil;
untuk note 0,0001, fee bisa **ratusan kali** nilai note itu sendiri. Ini sifat
fundamental sistem commitment-pool ala Tornado Cash, bukan bug — konsekuensinya
ada **batas ekonomis minimum** nominal yang masuk akal di-withdraw. (Tornado Cash
mengatasinya dengan denominasi tetap + relayer yang memotong fee dari nominal.)

Implikasi praktis untuk demo TA: pakai nominal note yang cukup besar (mis. ≥ 0,01
MATIC) agar fee withdraw terasa proporsional, dan jelaskan ke panel bahwa
"mahalnya withdraw = harga verifikasi zk-SNARK on-chain, bukan komisi sistem".

---

## 11. Hal penting untuk pembelaan Tugas Akhir

### 11.1 Pemetaan ke judul TA

Judul: *"Implementasi Sistem Pembayaran Digital Menggunakan Zero-Knowledge Proof dan
QR Code"*.

| Frasa judul | Wujud di XevouZK |
|---|---|
| Sistem pembayaran digital | Alur deposit → transfer → withdraw MATIC P2P |
| Zero-Knowledge Proof | Schnorr (auth) + Groth16 (saldo, transfer, withdraw) |
| QR Code | Static (alamat) + dynamic (payment request HMAC) |

### 11.2 Bukti fungsionalitas (siap dikutip)

- **34 Hardhat test** lulus untuk `ZKPayment.sol` (lifecycle, memo `EncryptedNote`, double-spend revert, admin guard).
- **Lifecycle privateTransfer real on-chain** di Amoy dengan VK asli, nullifier persistence & replay-reject terverifikasi (deposit → privateTransfer → withdraw penerima).
- **snarkjs local proof** untuk 3 circuit + negative case (tampered/insufficient-balance/wrong-shieldPriv ditolak).
- **Schnorr JS↔PHP**: implementasi browser & server mengikuti algoritma + domain-separation identik (dapat diverifikasi dari sumber) dan dipakai di login. *Harness vektor byte-identik otomatis belum ada (future).*
- Kontrak **ter-verify di PolygonScan** (lihat alamat di [PROJECT-STATUS §2](PROJECT-STATUS.md)).

> Catatan: suite **PHPUnit** (jalur HTTP server) **dihapus** dari repo (2026-06-22); fitur sisi-klien baru (backup note, account-guard, proxy scan) diverifikasi via smoke test browser E2E manual, bukan unit test.

### 11.3 Klaim yang AKURAT (boleh ditulis)

> XevouZK memakai Schnorr (secp256k1, Fiat-Shamir) untuk autentikasi tanpa mengirim
> password, dan Groth16 zk-SNARK untuk pembayaran P2P di mana **nominal, saldo
> sender, dan identitas penerima tidak terlihat di explorer Polygon publik**.
> Private key user di-derive di browser — server tak punya kuasa mengirim atas nama
> user pada **semua** mode (plain, deposit, privateTransfer, withdraw di-sign user;
> server hanya relay). Transfer privat memakai commitment pool: validitas dari ZK
> proof + nullifier; note penerima dikirim sebagai memo terenkripsi via event.
> Anti-double-spend memakai nullifier Poseidon yang dicatat di DB internal dan di
> kontrak sebagai backup canonical.

### 11.4 Klaim yang HARUS DIHINDARI (overclaim)

| ❌ Jangan klaim | Realitas |
|---|---|
| "Saldo terenkripsi end-to-end" | DB shadow `wallets.balance` cleartext untuk UX |
| "Nominal tersembunyi total" | Nominal **privat** = NULL di DB ✅, tapi saldo shadow & nominal **plain/deposit/withdraw** cleartext (toh publik on-chain) |
| "Trustless penuh" | Nullifier cache di DB; server bisa skip cek (kontrak tetap backup) |
| "Fully on-chain settlement" | Mode plain transfer tetap ada |
| "Identitas sender transaksi privat tersembunyi" | `msg.sender = user` (tx di-sign sendiri); yang tersembunyi nominal & penerima, bukan fakta bertransaksi |
| "Trusted setup multi-party" | Hanya Phase 1; Phase 2 single-party |
| "Wallet bisa recover tanpa password" | Lupa password = hilang permanen |

### 11.5 Prinsip keamanan yang bisa ditonjolkan

**Least privilege** — pemisahan dua wallet (prototipe berjalan lokal via Herd, tanpa hosting):
- **DEPLOYER** (`0xF90BA9...Adf4`): owner kontrak, hanya di mesin lokal developer — by design tidak pernah ikut ke hosting.
- **MASTER** (`0x16a747...E6a4`): faucet test MATIC (bukan relayer — tx privat user di-sign sendiri), key runtime; tanpa hak owner kontrak, kerugian maksimal terbatas saldo faucet.

Pemisahan ini keputusan desain: bila kelak di-host dan server di-hack, penyerang
hanya dapat MASTER — hak admin kontrak tetap aman. Detail: [PROJECT-STATUS §5](PROJECT-STATUS.md).

### 11.6 Jawaban siap-pakai untuk Q&A panel

- **"Trustless?"** → Tidak sepenuhnya; nullifier cache di DB. Kontrak punya mapping
  nullifier sebagai backup canonical. Untuk trustless penuh, server perlu query
  `isNullifierUsed` ke kontrak sebelum insert DB.
- **"Non-custodial?"** → Ya, penuh. Semua tx (plain, deposit, privateTransfer,
  withdraw) di-sign di browser dengan key user; server hanya relay raw tx. Master
  wallet hanya faucet, tidak menandatangani transaksi user.
- **"Biaya?"** → privateTransfer ~478k gas, withdraw ~428k, deposit ~75k, plain ~21k (terukur on-chain, §10).
- **"Kenapa setup single-party?"** → Trade-off prototipe; production butuh
  multi-party Phase 2. Phase 1 (pot14 Hermez) sudah multi-party.
- **"User lupa password?"** → Wallet hilang; recovery (BIP-39) adalah future work.

---

## 12. Keterbatasan dan future work

| Area | Keterbatasan saat ini | Arah perbaikan |
|---|---|---|
| Trusted setup | Phase 2 single-party | Ceremony multi-party |
| Recovery | Backup note lintas-device ada (§7.10), tapi lupa password = wallet tetap hilang | BIP-39 mnemonic backup untuk recovery saat password lupa |
| Trustless | Nullifier cache DB-primary | Server query kontrak sebelum insert |
| Metadata sender privat | `msg.sender = user` terlihat (tx di-sign sendiri) | Gasless relayer opsional (kontrak sudah tak cek `msg.sender`) |
| Privasi DB | Nominal/saldo cleartext (UX) | Enkripsi field dengan kunci user (opsional) |
| Skala / biaya | Withdraw ~428k & privateTransfer ~478k gas (verifikasi Groth16 konstan → note kecil tak ekonomis, §10) | Plonk/optimasi Poseidon round, denominasi minimum, atau zk-rollup |
| Jaringan | Testnet Amoy only | Mainnet butuh audit + BLS12-381 (EIP-2537) |
| Quantum | secp256k1 + BN128 tidak quantum-resistant | Di luar timeline TA |

---

## 13. Glossary

Glossary ringkas untuk dokumen ini. Versi lengkap & matematis ada di
[ZK-SNARK-DOCUMENTATION §12](ZK-SNARK-DOCUMENTATION.md#12-glossary).

| Istilah | Definisi singkat |
|---|---|
| **Prover** | Pihak yang membuat proof (di XevouZK: browser user). |
| **Verifier** | Pihak yang memeriksa proof (server untuk Schnorr; smart contract untuk Groth16). |
| **Witness** | Input rahasia ke circuit (saldo, secret, salt) yang tidak pernah dibongkar. |
| **Circuit** | Definisi aritmetika dari "apa yang dibuktikan" (file `.circom`). |
| **R1CS** | Rank-1 Constraint System — bentuk circuit setelah compile. |
| **Groth16** | Konstruksi zk-SNARK paling efisien (proof 3 elemen grup, verify 3 pairing). Butuh trusted setup. |
| **zk-SNARK** | Zero-Knowledge Succinct Non-interactive ARgument of Knowledge. |
| **zk-STARK** | Alternatif tanpa trusted setup tapi proof besar (~100 KB). Tidak dipakai XevouZK. |
| **Schnorr signature** | Skema tanda tangan berbasis discrete log; XevouZK pakai variant deterministik + Fiat-Shamir untuk login. |
| **Fiat-Shamir** | Teknik mengubah protokol interaktif jadi non-interaktif via hash transcript. |
| **Commitment** | Hash mengikat opaque dari note: `Poseidon(amount, shieldPub, salt)`. Tak bisa dibalik (one-way) & mengikat (binding). |
| **Shield key** | Keypair ZK milik user, di-derive dari `email+password`. `shieldPriv` = kunci rahasia (bahan nullifier + bukti kepemilikan); `shieldPub = Poseidon(shieldPriv)` = nilai yang ditanam di commitment. Sama untuk semua note milik user itu. |
| **Nullifier** | Tag deterministik per spend; cegah double-spend. `Poseidon(shieldPriv, commitment)`. |
| **Note** | Satuan saldo privat berbentuk `Poseidon(amount, shieldPub, salt)` (deposit-shaped, seragam untuk semua note). Tersimpan terenkripsi `{amount, salt, commitment}` di browser; `shieldPriv` di-derive dari password. Bersifat **UTXO** (dibelanjakan utuh, bukan saldo angka). |
| **Pool** | Kumpulan commitment aktif di `ZKPayment.sol` (`mapping(uint256=>bool) activeCommitments`). |
| **UTXO** | *Unspent Transaction Output* — model "koin" yang dibelanjakan utuh lalu dicetak ulang sebagai output baru; lawan dari model saldo/rekening. XevouZK memakai model ini untuk note. |
| **Mint / Burn** | Mint = menandai commitment baru hidup (`= true`); Burn = menghanguskan commitment lama (`= false`) + catat nullifier. Tiap transfer privat = burn lama + mint baru. |
| **Change note** | Note kembalian: saat membelanjakan note > nominal bayar, sisa dicetak sebagai note baru milik pengirim (konsekuensi model UTXO). |
| **Merkle tree** | Pohon hash commitment yang dipakai mixer seperti Tornado/Zcash untuk membuktikan keanggotaan secara ZK (anonymity set). **Tidak dipakai** XevouZK — diganti flat mapping (§7.8). |
| **Anonymity set** | Himpunan kandidat yang sama-sama mungkin sebagai pelaku; makin besar makin privat. XevouZK **tidak membangunnya** (tanpa Merkle membership proof). |
| **Linkability** | Kemampuan pengamat menautkan kejadian (deposit↔withdraw, pengirim↔penerima). Di XevouZK graf commitment **dapat ditautkan**; yang tersembunyi nominal & identitas antara, bukan tautannya (§7.9). |
| **Poseidon** | Hash ramah-ZK (~250 constraint per evaluasi). |
| **Trusted setup** | Ceremony menghasilkan SRS Groth16; Phase 1 universal (ptau), Phase 2 per circuit. |
| **Toxic waste** | Sisa keacakan `τ` dari setup yang harus dihancurkan; kalau bocor → proof palsu. |
| **SRS** | Structured Reference String — output trusted setup. |
| **Powers of Tau (ptau)** | Phase 1 trusted setup universal. XevouZK pakai `pot14` (≤ 2^14 constraint, ≈ 19 MB). |
| **Gasless relayer** | Pihak yang membayar gas & mengirim tx atas nama user untuk menyembunyikan identitasnya. Pola opsional — **belum dipakai** di XevouZK (semua tx di-sign user sendiri). |
| **Non-custodial** | Sistem tak menyimpan private key user; server tak bisa bertindak atas nama user — berlaku untuk **semua** mode (plain, deposit, transfer, withdraw). |
| **Note backup** | Ciphertext note (AES-GCM identik localStorage) yang di-push ke server dengan `ref` opaque agar note bisa dipulihkan lintas-device. Server tak pernah lihat isinya. Bukan password recovery (§7.10). |
| **Account guard** | Cek sisi-klien yang mencocokkan Schnorr pubkey turunan `(email,password)` dengan `zk_public_key` akun sebelum operasi sensitif, agar password salah dibatalkan tanpa dikirim ke server (§7.10). |
| **Scan RPC proxy** | Route same-origin `/payment/scan-rpc` yang meneruskan `eth_getLogs`/`eth_call` read-only ke RPC ber-API-key, menjaga kunci API di server; trial-decrypt memo tetap di klien (§7.5). |
| **EncryptedNote** | Event on-chain berisi memo ECIES note penerima; dipindai penerima untuk menemukan dana masuk tanpa plaintext on-chain. |
| **Full-burn** | Aturan `withdraw`: tarik penuh nilai note, tidak ada partial. |
| **EIP-197** | Precompile pairing check di EVM (`0x08`) yang memungkinkan verifikasi Groth16 on-chain. |
| **BN128 / alt_bn128** | Kurva eliptik untuk Groth16 di EVM. |
| **Polygon Amoy** | Testnet Polygon (chainId 80002), pengganti Mumbai. |

---

> **Cara pakai dokumen ini**: baca §2–§7 untuk paham konsep, §8–§9 untuk paham
> alur & privasi, §10–§11 untuk siap menghadapi panel. Untuk angka & kode persis,
> lompat ke dokumen teknis yang ditautkan di header.
>
> Snapshot: **2026-06-22**. Jika arsitektur berubah (circuit/kontrak/primitif
> baru), perbarui dokumen ini bersama [ZK-SNARK-DOCUMENTATION.md](ZK-SNARK-DOCUMENTATION.md).
