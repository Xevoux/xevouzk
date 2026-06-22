# 🔐 XevouZK — Sistem Pembayaran Digital dengan Zero-Knowledge Proof

Sistem pembayaran digital **peer-to-peer non-custodial** yang mengintegrasikan **Schnorr signature** (autentikasi), **Groth16 zk-SNARK** (verifikasi saldo dan transaksi privat), dan **QR Code** di atas **Polygon Amoy Testnet**. Private key user lahir dan tinggal di browser — server tidak pernah menyimpan rahasia user.

> **Status TA**: artefak prototipe. Smart contract live di Polygon Amoy dan terbukti end-to-end on-chain.

---

## ✨ Fitur Utama

### 🔐 Non-Custodial Wallet
- **Private key lahir di browser**: Keypair Schnorr (auth) + keypair Polygon (wallet) di-derive deterministik dari `(email, password)` di sisi client pakai `@noble/curves`. Server hanya menerima public key dan alamat — tidak pernah private key, tidak pernah password cleartext.
- **Tx ditandatangani di browser**: Pembayaran plain ditandatangani dengan `ethers.js v6` di client; server me-relay raw signed tx ke RPC via `POST /payment/relay` (`eth_sendRawTransaction`). Server tidak punya kuasa untuk mengirim atas nama user.
- **Konsekuensi**: lupa password = wallet hilang permanen (kunci di-derive dari password, bukan disimpan). Note pool bisa di-backup terenkripsi lintas-device (lihat fitur **Backup Note** di bawah), tetapi itu bukan password recovery — flow mnemonic / seed-phrase (BIP-39) masih Future Work.

### 🔑 Schnorr Authentication
- Login challenge-response berbasis Schnorr signature (secp256k1, Fiat-Shamir).
- Browser sign message `lower(email)|timestamp|csrf_token` (anti-replay window 5 menit).
- Server verifikasi pakai `simplito/elliptic-php` lalu jalankan `Auth::login($user)` Laravel.
- **Password tidak pernah ditransmit** untuk autentikasi — hanya untuk derive key di browser.

### 🔒 Transaksi Privat (Groth16 zk-SNARK — commitment pool)
- Model **commitment-pool** (pola commitment + nullifier + burn/mint, terinspirasi Tornado — **namun tanpa Merkle tree / anonymity set**). Yang **tidak terlihat** di Polygon explorer publik: **nominal transfer**, **identitas penerima**, dan **saldo sender**. Yang **masih terlihat**: `msg.sender` tiap tx dan graf commitment (lihat catatan privasi jujur di bawah).
- Public inputs `privateTransfer`: `[senderCommitment, nullifier, newSelfCommitment, recipientCommitment]` — semuanya Poseidon hash, bukan cleartext. Note penerima dikirim sebagai memo **ECIES** lewat event `EncryptedNote`.
- **Non-custodial penuh**: `privateTransfer` & `withdraw` **ditandatangani sendiri oleh user** di browser (key derive dari password) lalu di-relay via `POST /payment/relay`. `msg.sender = alamat user`. Tidak ada gasless relayer — master wallet **hanya** untuk faucet.
- Konsekuensi privasi yang jujur: pengamat blockchain bisa melihat *bahwa* sebuah alamat melakukan privateTransfer, tetapi **bukan** nominal maupun penerimanya. Kontrak `privateTransfer` tidak mengecek `msg.sender`, jadi relayer bisa ditambahkan kelak tanpa redeploy (lihat Future Work).
- Nullifier persistence: source-of-truth di DB Laravel, backup canonical di kontrak.

### 💎 Pool Settlement On-Chain
- **Deposit**: browser hitung `commitment = Poseidon(amount, shieldPub, salt)` (`shieldPub = Poseidon(shieldPriv)`), encrypt note di localStorage, sign tx `ZKPayment.deposit{value}(commitment)`.
- **Withdraw**: browser decrypt note, generate Groth16 proof, sign tx `ZKPayment.withdraw(...)` ke recipient address. Full-burn semantics.
- Invariant kontrak: `address(this).balance == totalDeposited − totalWithdrawn`.

### 🛟 Backup Note Lintas-Device (zero-knowledge ke server)
- Note pool hanya hidup di `localStorage` perangkat → ganti/clear browser = note hilang. Untuk itu ada **backup terenkripsi opt-in ke server**: browser mem-push **ciphertext AES-GCM yang identik dengan blob localStorage** + `ref` opaque (`sha256("xevou-note-backup-v1:"+commitment+":"+salt)`) ke `POST /notes/backup`. **Server tak pernah melihat plaintext/salt/nominal/commitment** — hanya simpan ciphertext + ref hash per-user di tabel `note_backups` (lihat [docs/PRIVACY-GAP-ANALYSIS.md](docs/PRIVACY-GAP-ANALYSIS.md)).
- **Sinkron dua-arah saat login** (`syncOnLogin`, butuh password): PULL → decrypt blob server, merge ke localStorage bila belum ada; SWEEP → push note lokal yang belum ada di server. Antrian best-effort di-flush saat dashboard load (`flushPending`, tanpa password).
- **Bukan password recovery**: lupa password = tetap tak bisa decrypt note (kunci AES turun dari `password+email`). Ini mitigasi *ganti/hilang device*, bukan pengganti BIP-39 mnemonic (masih Future Work).

### 🔐 Account Guard (verifikasi password tanpa kirim ke server)
- Karena password salah **tidak gagal** (ia diam-diam menurunkan identitas lain), tiap operasi sensitif (deposit, withdraw, transfer privat, reveal saldo) lebih dulu memanggil `AccountGuard.assertPassword`: browser men-derive Schnorr pubkey dari `(email, password)` dan mencocokkannya dengan `zk_public_key` akun (anchor publik di `<meta name="account-schnorr-pub">`). Password salah → operasi dibatalkan **di klien**, tanpa pernah mengirim password.

### 📱 QR Code P2P
- **QR Terima (`xevouzk:`)** — dibuat di halaman Wallet, dua varian:
  - *Plain*: alamat Polygon penerima → untuk transfer publik biasa.
  - *Privat (zkpub)*: shielded public key + encryption key penerima → discan pengirim untuk melakukan **private transfer** ke pool tanpa mengungkap alamat penerima on-chain.
- **Dynamic payment-request QR**: `{amount, description}` dengan HMAC signature, persist di DB `qr_codes`, expire 15 menit, anti-replay via `used_at` flag.
- **Static QR**: alamat wallet saja (JSON `wallet_address`) — penerima input nominal saat scan.
- Server-side `/payment/qr/scan` memvalidasi signature + expiration QR dynamic; QR `xevouzk:` di-decode client-side untuk menentukan mode (plain vs privat).

### ⛓️ Polygon Integration
- Live di **Polygon Amoy testnet** (chain ID `80002`).
- 4 kontrak ter-deploy: `BalanceCheckVerifier`, `PrivateTransferVerifier`, `WithdrawVerifier`, `ZKPayment v2`. Lihat [docs/PROJECT-STATUS.md §2](docs/PROJECT-STATUS.md) untuk alamat.
- Source code ter-verify di PolygonScan.
- Trusted setup pakai `pot14` Powers of Tau + Phase 2 single-party contribution (lihat [docs/ZK-SNARK-DOCUMENTATION.md §6](docs/ZK-SNARK-DOCUMENTATION.md)).

### 🚰 Faucet Test MATIC
- Tombol "Request MATIC" di halaman Wallet → 5 MATIC dikirim dari master wallet ke wallet user.
- Cooldown 24 jam per-user, plus rate limit HTTP `throttle:5,1`.

---

## 🛠️ Tech Stack

| Lapisan | Teknologi |
|---|---|
| Backend | Laravel 12, PHP 8.2+, MySQL |
| Frontend | Blade Templates + Vanilla JavaScript + Raw CSS, Vite build |
| ZKP autentikasi | Schnorr (secp256k1, Fiat-Shamir) |
| ZKP saldo & transaksi | Groth16 (Circom + snarkjs, Powers of Tau `pot14`) |
| Smart contract | Solidity ^0.8.20, Hardhat |
| Blockchain | Polygon Amoy Testnet (chain ID `80002`) |
| Web3 client | `ethers.js` v6 (frontend signing); `web3p/web3.php` (backend RPC) |
| Kripto client | `@noble/curves` v1.4, `@noble/hashes` v1.4, `ffjavascript`, `snarkjs` |
| Kripto server | `simplito/elliptic-php`, `kornrunner/keccak`, `phpseclib` |
| QR Code | `simplesoftwareio/simple-qrcode` |
| Test / bukti | Hardhat (behavioral kontrak), snarkjs (proof Groth16 lokal + bench waktu), E2E on-chain Amoy |

---

## 📋 Persyaratan Sistem

- **[Laravel Herd](https://herd.laravel.com/)** (cara dijalankan di proyek ini) — bundle PHP + web server (nginx) + domain `.test` HTTPS otomatis. PHP ≥ 8.2 sudah termasuk.
- Composer >= 2.x
- MySQL >= 8.0 (atau MariaDB 10.6+) — lewat Herd Services (Herd Pro) atau instalasi MySQL terpisah.
- Node.js >= 18.x + NPM
- Koneksi internet (untuk RPC ke Polygon Amoy & faucet) — **bukan** untuk hosting.

Tidak butuh MetaMask atau extension wallet pihak ketiga — wallet di-derive di browser dari password user.

> **Catatan deployment**: XevouZK dijalankan sebagai **prototipe lokal via Laravel Herd**, bukan di-host publik. Settlement transaksi tetap nyata on-chain di Polygon Amoy (blockchain publik), jadi bukti implementasi untuk TA tetap valid. Internet diperlukan untuk menjangkau RPC Amoy, tetapi aplikasinya sendiri cukup berjalan di mesin lokal.

---

## 🚀 Instalasi

```bash
# 1. Clone repo
git clone <repository-url>
cd zk_wallet

# 2. Install dependencies
composer install
npm install

# 3. Environment
cp .env.example .env
php artisan key:generate

# 4. Sesuaikan kredensial DB di .env (DB_DATABASE, DB_USERNAME, DB_PASSWORD)
#    lalu buat database (default .env.example: zk_payment):
mysql -u root -p -e "CREATE DATABASE zk_payment;"

# 5. Migrasi
php artisan migrate

# 6. Build asset frontend (Vite)
npm run build
```

### Menjalankan via Laravel Herd (lokal, tanpa hosting)

Herd bertindak sebagai web server — **tidak perlu `php artisan serve`**. Langkahnya:

```powershell
# Daftarkan folder proyek ke Herd dengan domain yang cocok dengan APP_URL
# (.env.example memakai APP_URL=https://xevouzk.test)
herd link xevouzk

# (opsional) pastikan HTTPS aktif untuk domain ini
herd secure xevouzk
```

Buka **`https://xevouzk.test`** di browser — aplikasi siap.

> ⚠️ **Penting**: domain Herd harus cocok dengan `APP_URL` di `.env`. Folder bernama
> `zk_wallet` akan otomatis menjadi `zk_wallet.test`; jalankan `herd link xevouzk`
> agar domainnya jadi `xevouzk.test` (sesuai `.env.example`), atau ubah `APP_URL`
> mengikuti domain yang kamu pakai. Kalau tidak cocok, URL asset bisa rusak.

**Frontend (Vite):**
- Untuk pemakaian biasa cukup `npm run build` sekali; rebuild bila ada perubahan JS/CSS.
- Untuk active development dengan hot-reload, jalankan `npm run dev` (HMR). Karena
  Herd menyajikan situs lewat HTTPS, jika dev server Vite kena mixed-content, pakai
  `npm run build` saja sebagai jalur paling andal.

Tidak ada queued job di aplikasi ini, jadi **tidak perlu** worker `queue:work` terpisah.

**Akses dari HP (uji kamera/QR):** aplikasi siap diakses lewat tunnel publik (mis. `herd share` atau ngrok). `bootstrap/app.php` sudah `trustProxies(at: '*')` untuk header `X-Forwarded-*`, sehingga `url()`/`route()`/deteksi HTTPS memakai domain publik tunnel (login & asset tak rusak di HP). Route proxy `payment/scan-rpc` juga di-CSRF-exempt karena dipanggil `ethers` `JsonRpcProvider` (route tetap butuh sesi auth + hanya meneruskan method RPC read-only). `'*'` aman untuk dev testnet lokal yang hanya dijangkau via tunnel/localhost.

---

## ⚙️ Konfigurasi Polygon Amoy

`.env` minimum:

```env
POLYGON_NETWORK=testnet
POLYGON_RPC_URL=https://rpc-amoy.polygon.technology/
POLYGON_CHAIN_ID=80002
# ZKPayment v2 + 3 verifier (deploy 2026-06-05, lihat docs/PROJECT-STATUS.md §2)
POLYGON_CONTRACT_ADDRESS=0x105e6DB96C697DA8ca0952116bEA12AAbFF359B5
POLYGON_BALANCE_VERIFIER_ADDRESS=0x5653778d4c1C2257Eb65fAa69B714364E7a01363
POLYGON_TRANSFER_VERIFIER_ADDRESS=0x5500d21AC089152c0131eC0B7fB97Ad72ED40457
POLYGON_WITHDRAW_VERIFIER_ADDRESS=0xa6ff8557D425Bc32D582c544E3DBBfd48Ec56056

# RPC upstream untuk SCAN dana masuk (eth_getLogs historis). Dipakai HANYA oleh
# proxy server POST /payment/scan-rpc — URL ber-API-key ini TAK pernah dikirim ke
# browser. RPC publik gratis kini membatasi getLogs historis, jadi gunakan RPC
# ber-API-key, mis. Alchemy: https://polygon-amoy.g.alchemy.com/v2/<API_KEY>
POLYGON_SCAN_RPC_URL=https://polygon-amoy-bor-rpc.publicnode.com
# Blok awal scan (block deploy ZKPayment) — mempersempit rentang getLogs
POLYGON_CONTRACT_DEPLOY_BLOCK=0

# Master wallet — HANYA untuk faucet test MATIC (bukan relayer; tx privat user di-sign sendiri)
POLYGON_MASTER_WALLET=0xYourMasterAddress
POLYGON_PRIVATE_KEY=your_master_private_key_64hex_no_0x

# Deployer key hanya di mesin lokal (least privilege) — terpisah dari MASTER
# Lihat docs/PROJECT-STATUS.md §5
```

Polygon Amoy testnet:
- RPC: `https://rpc-amoy.polygon.technology/`
- Explorer: `https://amoy.polygonscan.com/`
- Faucet publik (kalau master wallet butuh isi ulang): `https://faucet.polygon.technology/`

> Polygon Mumbai sudah deprecated — jangan pakai. CLAUDE.md §4 melarang.

---

## 🔐 Trusted Setup zk-SNARK (sudah selesai)

Tiga sirkuit (`balance_check.circom`, `private_transfer.circom`, `withdraw.circom`) sudah di-compile dan punya zkey + verification key di repo (`circuits/keys/`, `public/zk/`). Detail trusted setup ada di [docs/ZK-SNARK-DOCUMENTATION.md §6](docs/ZK-SNARK-DOCUMENTATION.md).

---

## 📱 Cara Menggunakan

### 1. Register

1. Buka `/register`.
2. Isi nama, email, password (min 8 char), konfirmasi.
3. Klik **Daftar**.
4. Process Logs di sidebar menampilkan:
   - `Schnorr module ready (secp256k1)`
   - `Polygon key module ready`
   - `Schnorr pub: 02xxxx...`
   - `Polygon addr: 0x...`
5. Browser mengirim `{ name, email, schnorr_public_key, polygon_address, polygon_public_key }` ke server. **Password tidak ikut** (hanya dipakai di browser untuk derive key) dan private key tidak ikut — silakan inspect Network tab.

### 2. Login

1. Buka `/login`.
2. Masukkan email + password (password dipakai di browser untuk derive Schnorr key, tidak dikirim).
3. Browser auto-derive Schnorr key, sign challenge `email|timestamp|csrf`, kirim **hanya signature**.
4. Server verifikasi signature (login murni Schnorr, `Auth::attempt` dihapus) → `Auth::login` → dashboard. Hardening: single-use replay-nonce + rate limit per (email+IP).

### 3. Request Test MATIC

1. `/wallet` → tombol **Request MATIC**.
2. Master wallet kirim 5 MATIC ke address Anda di Amoy.
3. Tx hash muncul di response — bisa di-cek di [amoy.polygonscan.com](https://amoy.polygonscan.com/).
4. Cooldown 24 jam.

### 4. Buat QR untuk Menerima

Dari halaman `/wallet`, bagian **QR Terima**:
- **QR Plain** — encode alamat Polygon (`xevouzk:` plain). Untuk menerima transfer publik biasa.
- **QR Privat (zkpub)** — encode shielded public key + encryption key Anda. Pengirim men-scan ini untuk mengirim ke Anda lewat **pool privat** tanpa membongkar alamat Anda on-chain.

Untuk *payment request* bernominal, `/payment` → **Generate QR** membuat dynamic QR (HMAC-signed, expire 15 menit).

### 5. Kirim Pembayaran Plain (publik, non-custodial)

1. `/payment` → tab **Plain (Publik)** pada form Transfer Manual.
2. Receiver: alamat Polygon penerima (format `0x...`, EIP-55).
3. Jumlah: dalam MATIC (sisakan ~0.001 untuk gas).
4. Klik **Kirim Pembayaran** → browser prompt password → derive Polygon private key di browser.
5. ethers.js sign EIP-1559 tx → POST raw hex ke `/payment/relay` → server `eth_sendRawTransaction`.
6. Browser mencatat metadata ke riwayat via `/payment/record-relay` (informatif; kebenaran tetap on-chain).

→ Nominal & penerima **terlihat** di PolygonScan (ini memang mode publik).

### 6. Kirim Pembayaran Privat (pool — nominal & penerima tersembunyi)

Privasi memakai **commitment pool**, jadi alurnya melalui pool — semuanya **di-sign sendiri oleh user**, tanpa relayer:

1. **Deposit ke pool** — `/wallet` → **Pool Privat (ZKPayment)** → input nominal → **Sign & Deposit**. Browser hitung `commitment = Poseidon(amount, shieldPub, salt)`, simpan note terenkripsi di localStorage, sign `deposit{value}(commitment)`. Browser **menunggu tx ter-mined** dulu sebelum menyatakan sukses (commitment baru aktif on-chain), sehingga saldo pool langsung benar saat dibuka di Dashboard. Saldo pool (setelah di-reveal) dan saldo on-chain ter-refresh **live** tanpa reload. (Deposit publik — ini "fiat ramp" masuk.)
2. **Kirim ke penerima** — dua cara setara (browser-side, hasil sama):
   - **Scan QR Privat** — `/payment/scan` → scan **QR Privat (zkpub)** penerima, atau
   - **Manual** — `/payment` → tab **Privat (Pool)** → tempel *viewing key* penerima (penerima ambil dari Wallet → QR Privat → **Copy Kode**).

   Lalu browser:
   - generate Groth16 `private_transfer` proof (snarkjs `fullProve`),
   - burn note lama (nullifier) + mint note kembalian (`newSelfCommitment`) + note penerima (`recipientCommitment`),
   - lampirkan memo ECIES untuk penerima,
   - **(opsional)** pre-validasi proof via `/payment/transfer/verify` (hemat gas),
   - **user sign** `privateTransfer(a,b,c,pubSignals,encryptedNote)` → `/payment/relay`.
   - On-chain hanya 4 hash Poseidon + nullifier + memo terenkripsi. `msg.sender = user`.
3. **Penerima mendeteksi dana** — `/dashboard` → **Cek Transfer Masuk** menjalankan `scanIncomingNotes`: scan event `EncryptedNote`, trial-decrypt memo ECIES, simpan note yang cocok.
4. **Withdraw ke alamat** — `/wallet` → **Withdraw dari Pool Privat** → pilih note → alamat tujuan → **Generate Proof & Withdraw**. Browser generate proof withdraw, **user sign** `withdraw(...)` → `/payment/relay`. Recipient + amount **publik** (ujung "fiat ramp" keluar). Full-burn.

> Master wallet **tidak** ikut menandatangani satu pun langkah di atas — perannya hanya faucet test MATIC.

### 7. Scan QR Code

`/payment/scan` → izinkan kamera atau pakai input manual. Server memvalidasi signature + expiration untuk QR dynamic; QR `xevouzk:` di-decode client-side untuk menentukan mode (plain → konfirmasi transfer; privat → alur private transfer di atas).

---

## 🏗️ Struktur Project

```
zk_wallet/
├── CLAUDE.md                            # Project rules untuk Claude Code
├── README.md                            # File ini
├── composer.json, package.json          # Manifests
├── app/
│   ├── Http/Controllers/
│   │   ├── AuthController.php           # Register (non-custodial) + Schnorr login
│   │   ├── DashboardController.php
│   │   ├── WalletController.php         # Wallet info, faucet, receive-QR, decode-QR, liveState, pubkeys
│   │   ├── PaymentController.php        # relay + recordRelay/recordPoolEvent + previewTransfer/previewWithdraw + scanRpc + QR
│   │   └── NoteBackupController.php     # Backup note terenkripsi lintas-device (store/index ciphertext opaque)
│   ├── Models/                          # User, Wallet, Transaction, ZKProof, QRCode, NoteBackup
│   └── Services/
│       ├── SchnorrService.php           # Schnorr secp256k1 + Fiat-Shamir
│       ├── ZKSNARKService.php           # Groth16 verify struct + nullifier DB
│       ├── QRCodeService.php            # QR static + dynamic + HMAC
│       ├── PolygonService.php           # RPC: sendTransaction, sendRawTransaction, sendContractTransaction
│       ├── FaucetService.php            # Test MATIC distribution
│       └── WalletService.php            # Wallet helper
├── circuits/
│   ├── balance_check.circom             # Groth16 saldo proof
│   ├── private_transfer.circom          # Groth16 transfer proof
│   ├── withdraw.circom                  # Groth16 withdraw proof (full-burn)
│   ├── build/                           # Compiled wasm + r1cs
│   └── keys/                            # Final zkey + verification key
├── contracts/
│   ├── contracts/
│   │   ├── ZKPayment.sol                # Main: deposit + privateTransfer + withdraw
│   │   ├── verifiers/{BalanceCheck,PrivateTransfer,Withdraw}Verifier.sol  # snarkjs-generated, real VK
│   │   └── MockVerifier.sol             # Hardhat test helper
│   ├── scripts/{deploy,check-balance,test-transfer-e2e}.js
│   └── test/ZKPayment.test.js           # 34 Hardhat behavioral test
├── database/migrations/                 # Tidak ada kolom encrypted_private_key; ada note_backups
├── resources/js/                        # Modul ber-import library → di-bundle Vite (@vite)
│   ├── schnorr-auth.js                  # Schnorr key derivation + sign (@noble/curves)
│   ├── polygon-key.js                   # Polygon key derivation + EIP-55 address
│   ├── shield-key.js                    # Derivasi shielded keypair (Poseidon) untuk pool
│   ├── note-store.js                    # Encrypted note ↔ localStorage (AES-GCM)
│   ├── note-crypto.js                   # ECIES encrypt/decrypt memo + enc-keypair
│   ├── payment-relay.js                 # ethers.js v6 signing (plain transfer)
│   ├── pool-balance.js                  # Hitung saldo pool dari note lokal
│   ├── polygon-deposit.js               # Commitment + note + deposit tx (self-sign)
│   ├── polygon-withdraw.js              # Decrypt note + withdraw proof + tx (self-sign)
│   ├── polygon-transfer.js              # private_transfer proof + memo + scanIncomingNotes
│   ├── private-send.js                  # Orkestrasi transfer privat (pilih note + transferFromPool) — dipakai scan & manual
│   ├── record-event.js                  # Catat event pool ke riwayat (privacy-preserving; private_receive tanpa tx_hash)
│   ├── note-backup.js                   # Backup note terenkripsi ke server (pushBackup/flushPending/syncOnLogin)
│   ├── account-guard.js                 # Verifikasi password vs zk_public_key akun (tanpa kirim ke server)
│   ├── zk-snark.js                      # Wrapper snarkjs (import, bukan window-global)
│   ├── qr-scanner.js                    # qr-scanner + server scan
│   ├── xevou-uri.js                     # Encode/parse skema URI xevouzk:
│   ├── receive-qr.js                    # Generate QR terima (plain + privat zkpub)
│   └── vendor-lucide.js                 # Bundle ikon Lucide
├── public/js/                           # File classic tanpa library (asset())
│   └── app.js, dashboard.js, wallet.js, live-updates.js
├── public/zk/{balance_check,private_transfer,withdraw}/{wasm,zkey}    # Runtime client artifacts
├── resources/
│   ├── css/app.css                      # Single stylesheet
│   └── views/                           # Blade: auth, dashboard, layouts, payment
├── routes/web.php                       # POST /payment/relay + /payment/scan-rpc + /notes/backup + verify + QR
├── bootstrap/app.php                    # CSRF-exempt scan-rpc + trustProxies '*' (akses via tunnel)
├── storage/app/zk-keys/                 # Server-side VK JSON
├── tools/                               # verify-deployment.php (harness interop Schnorr belum ada)
└── docs/                                # Dokumentasi — lihat bagian Dokumentasi
```

---

## 🔒 Keamanan & Privacy

### Yang diterapkan
1. **Non-custodial key**: private key user tidak pernah ada di server.
2. **Schnorr login (murni)**: password tidak pernah ditransmit (login & register); `Auth::attempt` dihapus. Hardening: single-use replay-nonce + rate limit per (email+IP).
3. **`users.password` = hash acak placeholder**: kolom NOT NULL diisi `Hash::make(Str::random(40))`, **bukan** hash password user — tak pernah dipakai untuk auth.
4. **CSRF token** di semua form, **XSS escape** via Blade, **SQL injection guard** via Eloquent ORM.
5. **Least privilege wallet**: master wallet (runtime) ≠ deployer wallet (kontrak owner). Lihat [docs/PROJECT-STATUS.md §5](docs/PROJECT-STATUS.md).
6. **ZK proof on-chain**: 3 verifier (`BalanceCheck`, `PrivateTransfer`, `Withdraw`) snarkjs-exported, VK real.
7. **Anti double-spend**: nullifier guard di kontrak + DB index.
8. **HMAC signature** di dynamic QR + 15-menit expiration + anti-replay flag.

### Klaim yang akurat untuk laporan TA
> "XevouZK menggunakan Schnorr signature untuk autentikasi tanpa mengirim password, dan Groth16 zk-SNARK untuk pembayaran P2P di mana nominal transaksi, saldo sender, dan identitas penerima tidak terlihat di Polygon explorer publik. Private key Polygon user di-derive deterministik di browser dari password — server tidak menyimpan kuasa untuk mengirim atas nama user."

Detail batasan (apa yang masih terlihat di server, dst.) ada di [docs/PRIVACY-GAP-ANALYSIS.md](docs/PRIVACY-GAP-ANALYSIS.md).

### Klaim yang harus DIHINDARI (overclaim)
- ❌ "Trustless penuh" — nullifier source-of-truth ada di DB Laravel.
- ❌ "Saldo enkripsi end-to-end" — DB shadow saldo cleartext untuk UX.
- ❌ "Hardware wallet" — XevouZK pakai password derivation, bukan hardware key.
- ❌ "Anonim / anonymity set seperti Tornado Cash" — XevouZK tidak memakai Merkle tree, `senderCommitment` tampil publik di event tiap hop, jadi graf commitment dapat ditelusuri. Yang tersembunyi adalah nominal & identitas penerima, bukan keberadaan tautan antar-commitment.
- ❌ "Identitas sender transaksi privat tersembunyi" — `msg.sender = alamat user` (tx di-sign sendiri; belum ada gasless relayer).

---

## 🧪 Verifikasi Fungsionalitas

Sebagai bukti correctness untuk TA, XevouZK punya beberapa lapis verifikasi.
**Runbook lengkap (command + bukti gas used/price + cara capture)**: lihat
[docs/TESTING-EVIDENCE.md](docs/TESTING-EVIDENCE.md).

```bash
# Hardhat behavioral test untuk ZKPayment.sol (34 test)
cd contracts && npx hardhat test

# Gas used per method (tabel gas-reporter)
cd contracts && REPORT_GAS=true npx hardhat test     # PowerShell: $env:REPORT_GAS="true"; npx hardhat test

# Local Groth16 proof (generate + verify untuk 3 circuit)
cd circuits && node scripts/test-proofs.js

# End-to-end real proof di Amoy (deposit→transfer→withdraw, cetak gas used + tx hash)
cd contracts && npx hardhat run scripts/test-transfer-e2e.js --network amoy
```

Bukti yang relevan untuk laporan TA:
- 34 Hardhat behavioral test untuk `ZKPayment.sol` (deposit, privateTransfer + memo `EncryptedNote`, withdraw, admin guard, invariant `pool == deposited − withdrawn`).
- Local Groth16: generate + verify proof untuk `balance_check`, `private_transfer`, `withdraw`, termasuk negative case (tampered proof, insufficient-balance, wrong-shieldPriv rejection).
- 3× tx `privateTransfer` real on-chain di Amoy yang terverifikasi PolygonScan dengan nullifier persistence + replay rejection.
- Konsistensi Schnorr JS↔PHP: implementasi [schnorr-auth.js](resources/js/schnorr-auth.js) & [SchnorrService.php](app/Services/SchnorrService.php) mengikuti algoritma + domain-separation identik (dapat diverifikasi dari sumber) dan dipakai di alur login. *Harness vektor "byte-identik" otomatis belum ada di repo (future work).*

> Catatan: jalur HTTP server tidak lagi diuji lewat PHPUnit (suite + `phpunit.xml` dihapus dari repo). Bukti correctness TA bersandar pada Hardhat (behavioral kontrak), proof Groth16 lokal (snarkjs), dan E2E on-chain di Amoy — lihat [docs/TESTING-EVIDENCE.md](docs/TESTING-EVIDENCE.md).

---

## 📊 Database Schema (ringkas)

### `users`
- `id`, `name`, `email`, `password` (hash acak placeholder — NOT NULL filler; auth via Schnorr, password tak pernah dikirim/disimpan)
- `zk_enabled`, `zk_public_key` (Schnorr compressed pub), `zk_login_commitment`
- `email_verified_at`, timestamps

### `wallets`
- `id`, `user_id`
- `wallet_address` (internal ID `ZKWALLET...` untuk legacy display)
- `polygon_address` (EIP-55 0x...)
- `public_key` (uncompressed `04` + x + y, 130 hex)
- `balance` (decimal — cache dari blockchain)
- `last_sync_at`, `is_active`, `zk_proof_commitment`, timestamps
- Tidak ada kolom `encrypted_private_key` — private key user di-derive di browser, tidak pernah masuk DB

### `transactions`
- `id`, `type` (enum: `transfer`/`faucet`/`deposit`/`withdraw`/`private_transfer`/`private_receive`)
- `sender_wallet_id` (nullable — null untuk faucet & sisi penerima privat), `receiver_wallet_id`, `receiver_address`
- `amount` (nullable — **NULL** untuk jalur privat, nominal disembunyikan), `transaction_hash`, `polygon_tx_hash` (null untuk `private_receive` — unlinkable, lihat PRIVACY-GAP §3.I)
- `zk_proof`, `zk_public_inputs` (untuk mode privat)
- `status`, `notes`, timestamps

### `zk_proofs`, `qr_codes`, `faucet_requests`
Operational tables untuk nullifier persistence, QR signing, dan faucet cooldown.

### `note_backups`
- `id`, `user_id` (FK, cascade delete)
- `ref` (char 64 — `sha256("xevou-note-backup-v1:"+commitment+":"+salt)`, **opaque**)
- `ciphertext` (text — base64 AES-GCM blob, identik localStorage; **server tak bisa baca**)
- `timestamps`, unique `(user_id, ref)`
- Backup note pool lintas-device. Server tak pernah memegang plaintext/salt/nominal/commitment — hanya ciphertext + ref hash (lihat [docs/PRIVACY-GAP-ANALYSIS.md](docs/PRIVACY-GAP-ANALYSIS.md)).

---

## 📚 Dokumentasi Lengkap

Dokumentasi ada di folder [docs/](docs/):

| Dokumen | Isi |
|---|---|
| [docs/PROJECT-UNDERSTANDING.md](docs/PROJECT-UNDERSTANDING.md) | Titik masuk konseptual: apa itu ZKP + gambaran besar XevouZK (untuk pembimbing/penguji) |
| [docs/PETA-KODE.md](docs/PETA-KODE.md) | Peta kode: file & baris mana untuk apa (routing, controller, service, JS, circuit, kontrak) |
| [docs/PROJECT-STATUS.md](docs/PROJECT-STATUS.md) | Status proyek + deployed contracts + arsitektur + demo script TA |
| [docs/PRIVACY-GAP-ANALYSIS.md](docs/PRIVACY-GAP-ANALYSIS.md) | Klaim privacy vs realitas (untuk pertahanan TA) |
| [docs/ZK-SNARK-DOCUMENTATION.md](docs/ZK-SNARK-DOCUMENTATION.md) | Penjelasan teknis ZKP: Schnorr, Groth16, circuit, trusted setup, threat model, glossary |
| [docs/PENGUJIAN.md](docs/PENGUJIAN.md) | Panduan + template hasil bab Pengujian TA: 7 jenis uji (privasi, performa, keamanan) → klaim, command, tabel, capture bukti |
| [docs/TESTING-EVIDENCE.md](docs/TESTING-EVIDENCE.md) | Runbook reproduksi bukti pengujian: command Hardhat/gas/snarkjs/E2E + hasil terukur + cara capture |
| [docs/DEPLOY-GUIDE.md](docs/DEPLOY-GUIDE.md) | Operational runbook deploy ke Amoy |

---

## 🗺️ Future Work

- **BIP-39 mnemonic backup** untuk wallet recovery (lupa password = wallet hilang permanen di state sekarang). Catatan: backup note lintas-device sudah ada, tetapi itu menyelamatkan *note* dari ganti/hilang device — **bukan** recovery saat password lupa (kunci AES tetap turun dari password).
- **Trusted setup multi-party** untuk Phase 2 (saat ini single-party — cukup untuk prototipe, perlu upgrade untuk production).
- **Hosting publik** (opsional) di shared hosting / VPS — saat ini XevouZK dijalankan lokal via Laravel Herd; template `.env.production.example` tersedia bila suatu saat mau di-host.
- **Server query `isNullifierUsed(n)` ke kontrak** sebelum DB insert untuk trust-less property penuh.
- **Gasless relayer (opsional)** untuk `privateTransfer` agar metadata sender (`msg.sender`) ikut tersembunyi dan user bisa transaksi privat tanpa memegang MATIC. Kontrak sudah tidak mengecek `msg.sender`, jadi ini perubahan sisi client saja tanpa redeploy.

---

## 🐛 Troubleshooting

### "Polygon RPC tidak responsif"
- Cek `POLYGON_RPC_URL` di `.env` — default `https://rpc-amoy.polygon.technology/`.
- Coba endpoint alternatif Amoy.
- Master wallet harus punya saldo MATIC untuk melayani faucet (Request MATIC). Transfer privat user di-sign & dibayar gas oleh user sendiri, jadi pastikan wallet user sudah terisi via faucet.

### Tx tertolak `insufficient funds for gas`
- Mode non-custodial pakai user wallet untuk bayar gas. Klik **Request MATIC** di `/wallet` dulu.

### Address penerima ditolak
- Mode non-custodial via `/payment/relay` hanya menerima format `0x...` (EIP-55). ID internal `ZKWALLET...` tidak didukung — pakai address Polygon langsung.

### QR Scanner tidak jalan
- Izinkan akses kamera. Browser hanya mengizinkan kamera di konteks aman — domain HTTPS Herd `https://xevouzk.test` sudah memenuhi syarat ini (jalankan `herd secure xevouzk` jika belum HTTPS).
- Fallback ke input manual selalu tersedia.

### "Cek Transfer Masuk" tidak menemukan dana / scan gagal
- Penerimaan dana privat dipindai via `scanIncomingNotes` yang memanggil proxy server `POST /payment/scan-rpc` (kunci API tetap di server). RPC publik gratis kini membatasi `eth_getLogs` historis, jadi set `POLYGON_SCAN_RPC_URL` ke RPC ber-API-key (mis. Alchemy Amoy) lalu `php artisan config:clear`.
- Set `POLYGON_CONTRACT_DEPLOY_BLOCK` ke block deploy ZKPayment untuk mempersempit rentang scan. Cursor scan resumable di localStorage — tekan tombol beberapa kali bila backend RPC sedang berputar.

### Hardhat test gagal di pre-deploy
- Pastikan `circuits/keys/*.zkey` ada (verifier Solidity dependent pada VK constants).
- Re-run `cd circuits && npm run setup:all && npm run export:verifiers`.

---

## 🤝 Kontribusi

Repo ini adalah artefak Tugas Akhir — kontribusi eksternal tidak diharapkan untuk saat ini. Kalau menemukan bug atau punya saran:

1. Fork repo.
2. Buat branch baru.
3. Commit dengan pesan imperatif ringkas.
4. Pull request.

---

## 📝 Lisensi

MIT. Lihat file `LICENSE` (kalau ada) atau anggap MIT default.

---

## 🙏 Acknowledgments

- **Circom** + **snarkjs** — circuit compiler + Groth16 prover/verifier
- **circomlib** — Poseidon hash + comparator primitives
- **@noble/curves** + **@noble/hashes** — JS secp256k1 + keccak/sha256
- **simplito/elliptic-php** — server-side secp256k1
- **Hardhat** + **ethers v6** — Solidity dev & client signing
- **Laravel 12** + **web3p/web3.php** — backend framework + Web3 RPC
- **Polygon Amoy** — testnet untuk on-chain settlement

---

**XevouZK** — artefak TA *"Implementasi Sistem Pembayaran Digital Menggunakan Zero-Knowledge Proof dan QR Code"*.
