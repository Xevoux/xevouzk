# Peta Kode — XevouZK

> **Tujuan dokumen**: menjelaskan **struktur kode** dan **baris mana melakukan apa**,
> file demi file, agar penulis TA paham persis di mana setiap fungsi diimplementasikan.
> Ditulis untuk dibaca sambil membuka kodenya — setiap referensi pakai format
> `path:baris` supaya bisa langsung dilompati.
>
> **Beda dengan dokumen lain:**
> - [PROJECT-UNDERSTANDING.md](PROJECT-UNDERSTANDING.md) — *konsep* (apa itu ZKP, kenapa).
> - [ZK-SNARK-DOCUMENTATION.md](ZK-SNARK-DOCUMENTATION.md) — *matematika* Schnorr/Groth16.
> - **Dokumen ini (PETA-KODE.md)** — *lokasi kode*: file & baris untuk tiap fungsi.
>
> **Snapshot**: 2026-06-22. Jika file/baris bergeser setelah refactor, perbarui referensi di sini.

---

## Daftar Isi

1. [Peta tingkat tinggi (alur request)](#1-peta-tingkat-tinggi-alur-request)
2. [Routing — pintu masuk semua request](#2-routing--pintu-masuk-semua-request)
3. [Backend: Controllers](#3-backend-controllers)
4. [Backend: Services (logika bisnis)](#4-backend-services-logika-bisnis)
5. [Backend: Models & migrasi DB](#5-backend-models--migrasi-db)
6. [Frontend: modul JavaScript (resources/js)](#6-frontend-modul-javascript-resourcesjs)
7. [Circuits (Circom) — definisi "apa yang dibuktikan"](#7-circuits-circom--definisi-apa-yang-dibuktikan)
8. [Smart contract (Solidity)](#8-smart-contract-solidity)
9. [Telusur per-fitur (di mana kode untuk X?)](#9-telusur-per-fitur-di-mana-kode-untuk-x)

---

## 1. Peta tingkat tinggi (alur request)

Setiap aksi user melewati lapisan yang sama. Contoh **transfer privat**:

```
Browser (resources/js)            Server (Laravel)              Blockchain (Amoy)
─────────────────────             ────────────────              ─────────────────
polygon-transfer.js               routes/web.php                ZKPayment.sol
  transferFromPool()      ──┐       └─> PaymentController          privateTransfer()
   • decrypt note          │            previewTransfer() ──> ZKSNARKService
   • Groth16 fullProve     │            relayRawTransaction() ─> PolygonService ──> RPC
   • sign tx (ethers)      │
   • POST /payment/relay ──┘
```

Aturan emas yang tercermin di kode:
- **Rahasia & proof dibuat di browser** (`resources/js/*`), tidak pernah di server.
- **Server hanya relay + pra-validasi** (`PaymentController` + `PolygonService`).
- **Verifikasi mengikat ada on-chain** (`ZKPayment.sol` + verifier Groth16).

---

## 2. Routing — pintu masuk semua request

**File: [routes/web.php](../routes/web.php)** — daftar semua URL → controller.

| Baris | Route | Controller method | Fungsi |
|---|---|---|---|
| [web.php:13-20](../routes/web.php#L13-L20) | `GET/POST /login`, `/register` | `AuthController` | Halaman + submit auth (guest only, `throttle:10,1`) |
| [web.php:22](../routes/web.php#L22) | `POST /logout` | `AuthController@logout` | Keluar sesi |
| [web.php:26](../routes/web.php#L26) | `GET /dashboard` | `DashboardController@index` | Dashboard utama |
| [web.php:30](../routes/web.php#L30) | `GET /live/state` | `WalletController@liveState` | Refresh saldo/state tanpa reload |
| [web.php:33](../routes/web.php#L33) | `POST /pubkeys` | `WalletController@publishPubkeys` | Publish shield/enc public key |
| [web.php:36-39](../routes/web.php#L36-L39) | `POST/GET /notes/backup` | `NoteBackupController@store`/`index` | Backup note terenkripsi lintas-device (ciphertext opaque) |
| [web.php:42-61](../routes/web.php#L42-L61) | `wallet/*` | `WalletController` | QR receive, info, sync saldo, faucet |
| [web.php:67](../routes/web.php#L67) | `POST /payment/relay` | `PaymentController@relayRawTransaction` | Broadcast raw signed tx |
| [web.php:70](../routes/web.php#L70) | `POST /payment/scan-rpc` | `PaymentController@scanRpc` | Proxy RPC read-only (scan getLogs) — API key tetap di server |
| [web.php:71](../routes/web.php#L71) | `POST /payment/record-relay` | `PaymentController@recordRelayTransfer` | Catat transfer plain ke riwayat (informatif) |
| [web.php:72](../routes/web.php#L72) | `POST /payment/record-event` | `PaymentController@recordPoolEvent` | Catat event pool (deposit/withdraw/private) ke riwayat — privacy-preserving |
| [web.php:73](../routes/web.php#L73) | `POST /payment/withdraw/verify` | `PaymentController@previewWithdraw` | Pra-cek withdraw proof |
| [web.php:74](../routes/web.php#L74) | `POST /payment/transfer/verify` | `PaymentController@previewTransfer` | Pra-cek transfer proof |
| [web.php:75](../routes/web.php#L75) | `POST /payment/qr/scan` | `PaymentController@scanQrApi` | Decode QR (static/dynamic) |

> Semua route di [web.php:25](../routes/web.php#L25) ke bawah dibungkus middleware `auth`
> (harus login). Route auth ([web.php:13](../routes/web.php#L13)) dibungkus `guest`.
> `payment/scan-rpc` di-**CSRF-exempt** di [bootstrap/app.php](../bootstrap/app.php)
> (dipanggil `ethers` JsonRpcProvider yang tak kirim token CSRF) — tetap butuh `auth`.

---

## 3. Backend: Controllers

Controller **tipis** — hanya validasi input + panggil Service. Logika berat di `Services/`.

### 3.1 AuthController — [app/Http/Controllers/AuthController.php](../app/Http/Controllers/AuthController.php)

Registrasi & login **non-custodial** (server tak pernah pegang private key).

| Baris | Method | Apa yang dikerjakan |
|---|---|---|
| [AuthController.php:36-81](../app/Http/Controllers/AuthController.php#L36-L81) | `register()` | Validasi pubkey kiriman browser ([:43-49](../app/Http/Controllers/AuthController.php#L43-L49)), buat `User` + `Wallet` dari **public key saja**. **Password tidak diterima** — `users.password` diisi hash acak placeholder ([:57](../app/Http/Controllers/AuthController.php#L57)). |
| [AuthController.php:83-136](../app/Http/Controllers/AuthController.php#L83-L136) | `login()` | **Login murni Schnorr** (tak ada `Auth::attempt`). Rate-limit per (email+IP) ([:97-104](../app/Http/Controllers/AuthController.php#L97-L104)), error generik anti-enumeration ([:108-110](../app/Http/Controllers/AuthController.php#L108-L110)), verifikasi signature ([:119](../app/Http/Controllers/AuthController.php#L119)) → `Auth::login` ([:126](../app/Http/Controllers/AuthController.php#L126)). |
| [AuthController.php:151-205](../app/Http/Controllers/AuthController.php#L151-L205) | `verifySchnorrLogin()` | **Inti login ZK.** Window 300 detik ([:171](../app/Http/Controllers/AuthController.php#L171)), bentuk pesan `email\|timestamp\|csrf` ([:180](../app/Http/Controllers/AuthController.php#L180)), `SchnorrService::verify` ([:181](../app/Http/Controllers/AuthController.php#L181)), lalu **single-use replay-nonce** via `Cache::add` ([:196-202](../app/Http/Controllers/AuthController.php#L196-L202)). |
| [AuthController.php:211-214](../app/Http/Controllers/AuthController.php#L211-L214) | `loginThrottleKey()` | Key rate-limit per (email+IP), transliterated. |
| [AuthController.php:221-273](../app/Http/Controllers/AuthController.php#L221-L273) | `syncWalletOnLogin()` | Sync saldo on-chain best-effort saat login (tidak boleh bikin login crash). |

Konstanta penting: `SCHNORR_TIMESTAMP_WINDOW = 300` detik & `LOGIN_MAX_ATTEMPTS = 5` di [AuthController.php:19-20](../app/Http/Controllers/AuthController.php#L19-L20).

### 3.2 PaymentController — [app/Http/Controllers/PaymentController.php](../app/Http/Controllers/PaymentController.php)

Orkestrasi pembayaran. **Tidak menyimpan private key**; hanya relay & pra-validasi.

| Baris | Method | Apa yang dikerjakan |
|---|---|---|
| [PaymentController.php:68-114](../app/Http/Controllers/PaymentController.php#L68-L114) | `scanQrApi()` | Decode QR: coba JSON static, kalau gagal decrypt dynamic. |
| [PaymentController.php:116-145](../app/Http/Controllers/PaymentController.php#L116-L145) | `relayRawTransaction()` | **Jantung non-custodial.** Terima `raw_tx` hex hasil sign browser, broadcast via `PolygonService::sendRawTransaction`. |
| [PaymentController.php:159-199](../app/Http/Controllers/PaymentController.php#L159-L199) | `scanRpc()` | **Proxy RPC read-only** untuk scan dana masuk. Whitelist method (`eth_getLogs`/`eth_call`/dst, [:181-185](../app/Http/Controllers/PaymentController.php#L181-L185)), teruskan ke `services.polygon.scan_rpc_url` ber-API-key — **kunci API tak pernah ke browser**. Bukan relay tulis. |
| [PaymentController.php:213-259](../app/Http/Controllers/PaymentController.php#L213-L259) | `recordRelayTransfer()` | Catat transfer plain ke riwayat. Idempoten terhadap `polygon_tx_hash` ([:225](../app/Http/Controllers/PaymentController.php#L225)). **Tidak** memutasi saldo (saldo dari RPC). |
| [PaymentController.php:286-348](../app/Http/Controllers/PaymentController.php#L286-L348) | `recordPoolEvent()` | Catat event pool (`deposit`/`withdraw`/`private_transfer`/`private_receive`). **Privacy-preserving**: untuk jalur privat, nominal & counterparty **diabaikan** (null) ([:301-304](../app/Http/Controllers/PaymentController.php#L301-L304)); penerima privat idempoten via `receipt_ref` opaque **tanpa** simpan `polygon_tx_hash` ([:309-335](../app/Http/Controllers/PaymentController.php#L309-L335), mitigasi M1). |
| [PaymentController.php:357-384](../app/Http/Controllers/PaymentController.php#L357-L384) | `previewWithdraw()` | Pra-cek withdraw proof via `ZKSNARKService::verifyWithdrawProof` ([:364](../app/Http/Controllers/PaymentController.php#L364)) — hemat gas user. |
| [PaymentController.php:391-410](../app/Http/Controllers/PaymentController.php#L391-L410) | `previewTransfer()` | Pra-cek transfer proof via `verifyTransferProof` ([:396](../app/Http/Controllers/PaymentController.php#L396)). |
| [PaymentController.php:412](../app/Http/Controllers/PaymentController.php#L412) | `transactionHistory()` | Tampilkan riwayat (paginate 20). |

### 3.3 WalletController — [app/Http/Controllers/WalletController.php](../app/Http/Controllers/WalletController.php)

| Baris | Method | Fungsi |
|---|---|---|
| [WalletController.php:26](../app/Http/Controllers/WalletController.php#L26) | `index()` | Halaman wallet |
| [WalletController.php:52](../app/Http/Controllers/WalletController.php#L52) | `generateReceiveQR()` | QR alamat untuk terima |
| [WalletController.php:173](../app/Http/Controllers/WalletController.php#L173) | `syncBalance()` | Sync saldo on-chain |
| [WalletController.php:213](../app/Http/Controllers/WalletController.php#L213) | `liveState()` | State dinamis; `?chain=1` paksa sync on-chain |
| [WalletController.php:312](../app/Http/Controllers/WalletController.php#L312) | `requestTestMatic()` | Faucet test MATIC (testnet) |
| [WalletController.php:474](../app/Http/Controllers/WalletController.php#L474) | `publishPubkeys()` | Simpan shield/enc public key user |

### 3.4 DashboardController — [app/Http/Controllers/DashboardController.php](../app/Http/Controllers/DashboardController.php)

`index()` menyiapkan data dashboard (saldo, ringkasan). Tipis.

### 3.5 NoteBackupController — [app/Http/Controllers/NoteBackupController.php](../app/Http/Controllers/NoteBackupController.php)

Backup note terenkripsi lintas-device. **Zero-knowledge terhadap server**: hanya
menyimpan ciphertext + ref opaque per-user, tak pernah plaintext/salt/nominal.

| Baris | Method | Apa yang dikerjakan |
|---|---|---|
| [NoteBackupController.php:18-37](../app/Http/Controllers/NoteBackupController.php#L18-L37) | `store()` | Validasi batch (`ref` 64-hex, `ciphertext` ≤ 4096, ≤ 200 item) → `updateOrCreate` per `(user_id, ref)`. |
| [NoteBackupController.php:39-44](../app/Http/Controllers/NoteBackupController.php#L39-L44) | `index()` | Kembalikan semua `{ref, ciphertext}` milik user (di-decrypt **di klien**). |

---

## 4. Backend: Services (logika bisnis)

### 4.1 SchnorrService — [app/Services/SchnorrService.php](../app/Services/SchnorrService.php)

Verifikasi tanda tangan Schnorr login. **Harus byte-identik dengan
[resources/js/schnorr-auth.js](../resources/js/schnorr-auth.js)** (kalau salah satu berubah, login gagal).

| Baris | Method | Fungsi |
|---|---|---|
| [SchnorrService.php:33](../app/Services/SchnorrService.php#L33) | `derivePrivateKey()` | `sha256("schnorr_v1:email:password")` → scalar |
| [SchnorrService.php:39](../app/Services/SchnorrService.php#L39) | `derivePublicKey()` | Scalar × G (secp256k1) |
| [SchnorrService.php:45](../app/Services/SchnorrService.php#L45) | `sign()` | Sign deterministik (dipakai untuk test vektor) |
| [SchnorrService.php:65](../app/Services/SchnorrService.php#L65) | `verify()` | **Dipanggil saat login.** Cek `s·G == R + e·P` |

> Pasangan sisi browser: [schnorr-auth.js:25-53](../resources/js/schnorr-auth.js#L25-L53)
> (`derivePrivateKey`, `derivePublicKey`, `sign`).

### 4.2 ZKSNARKService — [app/Services/ZKSNARKService.php](../app/Services/ZKSNARKService.php)

**Verifikasi Groth16 + guard nullifier di server** (pra-cek sebelum on-chain).

| Baris | Method | Fungsi |
|---|---|---|
| [ZKSNARKService.php:44](../app/Services/ZKSNARKService.php#L44) | `verifyBalanceProof()` | Verifikasi proof `balance_check` |
| [ZKSNARKService.php:120](../app/Services/ZKSNARKService.php#L120) | `verifyWithdrawProof()` | Verifikasi proof `withdraw` (dipakai `previewWithdraw`) |
| [ZKSNARKService.php:200](../app/Services/ZKSNARKService.php#L200) | `extractWithdrawPublicInputs()` | Ambil `[commitment, nullifier, recipient, amount]` |
| [ZKSNARKService.php:220](../app/Services/ZKSNARKService.php#L220) | `verifyTransferProof()` | Verifikasi proof `private_transfer` (dipakai `previewTransfer`) |
| [ZKSNARKService.php:274](../app/Services/ZKSNARKService.php#L274) | `extractTransferPublicInputs()` | Ambil 4 public signal transfer |
| [ZKSNARKService.php:294](../app/Services/ZKSNARKService.php#L294) | `verifyGroth16Proof()` | Inti pairing-check (private helper) |
| [ZKSNARKService.php:338](../app/Services/ZKSNARKService.php#L338) | `loadVerificationKey()` | Muat `verification_key.json` per circuit |
| [ZKSNARKService.php:366-407](../app/Services/ZKSNARKService.php#L366-L407) | `validateG1/G2Point`, `isValidFieldElement` | Validasi titik kurva & elemen field |
| [ZKSNARKService.php:542](../app/Services/ZKSNARKService.php#L542) | `verifyNullifier()` | Cek nullifier belum dipakai (cache DB) |
| [ZKSNARKService.php:559](../app/Services/ZKSNARKService.php#L559) | `markNullifierUsed()` | Tandai nullifier terpakai |

### 4.3 PolygonService — [app/Services/PolygonService.php](../app/Services/PolygonService.php)

Klien RPC ke node Polygon Amoy. **Broadcast only** untuk tx user (tidak sign tx user).

| Baris | Method | Fungsi |
|---|---|---|
| [PolygonService.php:67](../app/Services/PolygonService.php#L67) | `sendRawTransaction()` | **Dipakai relay.** Kirim raw signed tx hex ke RPC |
| [PolygonService.php:280](../app/Services/PolygonService.php#L280) | `getBalance()` | Saldo on-chain alamat |
| [PolygonService.php:660](../app/Services/PolygonService.php#L660) | `verifyTransaction()` | Cek receipt tx |
| [PolygonService.php:718](../app/Services/PolygonService.php#L718) | `transferMatic()` | Transfer MATIC dari **master wallet** (khusus faucet) |
| [PolygonService.php:773](../app/Services/PolygonService.php#L773) | `syncWalletBalance()` | Sinkron saldo on-chain → cache DB |

> `transferMatic` ([:718](../app/Services/PolygonService.php#L718)) satu-satunya tempat server pegang
> key (MASTER wallet, **hanya faucet** — bukan relayer transaksi user).

### 4.4 Service lain

| Service | File | Peran |
|---|---|---|
| **WalletService** | [WalletService.php](../app/Services/WalletService.php) | Saldo, riwayat, util wallet (`getBalance` [:29](../app/Services/WalletService.php#L29), `syncBalance` [:241](../app/Services/WalletService.php#L241)) |
| **QRCodeService** | [QRCodeService.php](../app/Services/QRCodeService.php) | QR static ([:31](../app/Services/QRCodeService.php#L31)) & dynamic ber-HMAC ([:65](../app/Services/QRCodeService.php#L65)), decode ([:118](../app/Services/QRCodeService.php#L118)) |
| **FaucetService** | [FaucetService.php](../app/Services/FaucetService.php) | Distribusi test MATIC + cooldown 24 jam ([:119](../app/Services/FaucetService.php#L119)) |

---

## 5. Backend: Models & migrasi DB

**Models** — [app/Models/](../app/Models/):

| Model | File | Tabel | Catatan |
|---|---|---|---|
| `User` | [User.php](../app/Models/User.php) | `users` | Punya `zk_public_key` (Schnorr pubkey), `zk_enabled` |
| `Wallet` | [Wallet.php](../app/Models/Wallet.php) | `wallets` | `polygon_address`, `public_key`, saldo cache. **Tanpa** `encrypted_private_key` (non-custodial) |
| `Transaction` | [Transaction.php](../app/Models/Transaction.php) | `transactions` | Riwayat; `type` enum (`transfer`/`faucet`/`deposit`/`withdraw`/`private_transfer`/`private_receive`), `amount` **nullable** (NULL untuk jalur privat), `polygon_tx_hash` sebagai sumber kebenaran |
| `ZKProof` | [ZKProof.php](../app/Models/ZKProof.php) | `zk_proofs` | Cache nullifier & proof |
| `QRCode` | [QRCode.php](../app/Models/QRCode.php) | `qr_codes` | Data QR dynamic |
| `NoteBackup` | [NoteBackup.php](../app/Models/NoteBackup.php) | `note_backups` | Backup note terenkripsi: `ref` opaque + `ciphertext` (server tak bisa decrypt) |

**Migrasi** — [database/migrations/](../database/migrations/) (urut waktu):

| File | Isi |
|---|---|
| `0001_01_01_000000_create_users_table.php` | Tabel user (`password` NOT NULL = placeholder, auth via Schnorr) |
| `2024_01_01_000003_create_wallets_table.php` | Tabel wallet (sudah termasuk kolom shield/enc pubkey — hasil konsolidasi) |
| `2024_01_01_000004_create_transactions_table.php` | Tabel transaksi (sudah termasuk `receiver_address` — hasil konsolidasi) |
| `2024_11_29_000006_create_zk_proofs_table.php` | Tabel nullifier/proof cache |
| `2024_11_29_000007_create_qr_codes_table.php` | Tabel QR dynamic |
| `2024_11_29_000010_create_faucet_requests_table.php` | Tabel cooldown faucet |
| `2026_06_08_000000_add_type_to_transactions_table.php` | Kolom `type` + longgarkan `amount`/`sender_wallet_id` jadi nullable |
| `2026_06_13_000000_add_private_receive_to_transactions_type.php` | Lebarkan enum `type` dengan nilai `private_receive` |
| `2026_06_21_000000_create_note_backups_table.php` | Tabel `note_backups` (`user_id`, `ref` char(64), `ciphertext` text, unique `(user_id, ref)`) — backup note lintas-device |

> Migrasi penambah kolom lama (`add_shield_pub`, `add_receiver_address`) sudah
> **dikonsolidasikan** ke migration `create_*` (commit `refactor(db)`). Migrasi
> `drop_*` (topup, encrypted_private_key) sudah dihapus dari repo — fitur top-up &
> kolom private-key memang tak pernah jadi bagian arsitektur final (CLAUDE.md §4).

---

## 6. Frontend: modul JavaScript (resources/js)

> Modul ini **meng-`import` library** (snarkjs, ethers, circomlibjs) → di-bundle Vite.
> Dimuat di Blade via `@vite([...])`. File classic tanpa library ada di [public/js/](../public/js/).

### 6.1 Derivasi kunci (tiga keypair domain-separated)

Semua key lahir di browser dari `(email, password)`, dibedakan prefix domain:

| Modul | File | Output | Prefix seed |
|---|---|---|---|
| **Schnorr** (auth) | [schnorr-auth.js](../resources/js/schnorr-auth.js) | keypair secp256k1 login | `schnorr_v1:` ([:26](../resources/js/schnorr-auth.js#L26)) |
| **Polygon** (sign tx) | [polygon-key.js](../resources/js/polygon-key.js) | keypair EVM | `polygon_v1:` |
| **Shield** (commitment) | [shield-key.js](../resources/js/shield-key.js) | keypair Poseidon/BN254 | `shield_v1:` ([:27](../resources/js/shield-key.js#L27)) |

[shield-key.js](../resources/js/shield-key.js) juga mengemas **zkpub** (alamat ZK penerima):
- `deriveShieldKeypair()` [shield-key.js:25-31](../resources/js/shield-key.js#L25-L31) — `shieldPub = Poseidon(shieldPriv)`.
- `packZkpub()` / `unpackZkpub()` [shield-key.js:58-70](../resources/js/shield-key.js#L58-L70) — gabung `shieldPub‖encPub` jadi 64 byte base64url (dipakai di QR Privat).

### 6.2 Login Schnorr — [schnorr-auth.js](../resources/js/schnorr-auth.js)

`sign()` [schnorr-auth.js:36-53](../resources/js/schnorr-auth.js#L36-L53) membuat tanda tangan login.
Harus cocok byte-per-byte dengan [SchnorrService.php](../app/Services/SchnorrService.php).

### 6.3 Deposit ke pool — [polygon-deposit.js](../resources/js/polygon-deposit.js)

`depositToPool()` [polygon-deposit.js:52](../resources/js/polygon-deposit.js#L52):
hitung commitment, simpan note terenkripsi (`saveNoteRecord` [:111](../resources/js/polygon-deposit.js#L111),
yang juga memicu backup ke server — lihat hook di bawah), sign tx `deposit(commitment)`
payable, relay. Ini titik "fiat ramp" yang publik.
Setelah broadcast, browser **`waitForTransaction`** sampai tx ter-mined sebelum
menyatakan sukses — karena `activeCommitments[commitment]` baru `true` setelah blok
ter-mined, jadi tanpa menunggu, saldo pool yang dihitung langsung sesudahnya akan kosong.

### 6.4 Transfer privat — [polygon-transfer.js](../resources/js/polygon-transfer.js) ⭐ modul inti

**Entry point (UI):** alur privat dipicu lewat (a) **Scan QR Privat** — `/payment/scan`
([qr-scanner.js](../resources/js/qr-scanner.js)), atau (b) **Transfer Manual tab Privat** —
`/payment` (tempel viewing key penerima). Keduanya memanggil helper bersama
`sendPrivate()` ([private-send.js](../resources/js/private-send.js)) yang mem-parse kode
penerima + memilih note pool terkecil yang cukup, lalu memanggil `transferFromPool()`.

`transferFromPool()` [polygon-transfer.js:60-188](../resources/js/polygon-transfer.js#L60-L188) — 8 langkah:

| Langkah | Baris | Aksi |
|---|---|---|
| 1 | [:70-74](../resources/js/polygon-transfer.js#L70-L74) | Decrypt note pengirim dari localStorage |
| 2 | [:83-85](../resources/js/polygon-transfer.js#L83-L85) | Derive shieldPriv + ambil shieldPub penerima |
| 3 | [:87-93](../resources/js/polygon-transfer.js#L87-L93) | Hitung `newSelfCommitment` (kembalian), `recipientCommitment`, `nullifier` (Poseidon) |
| 4 | [:95-113](../resources/js/polygon-transfer.js#L95-L113) | **Groth16 `fullProve`** witness `private_transfer` |
| 5 | [:115-120](../resources/js/polygon-transfer.js#L115-L120) | Bungkus note penerima jadi memo **ECIES** |
| 6 | [:122-139](../resources/js/polygon-transfer.js#L122-L139) | Preview verify ke `/payment/transfer/verify` (hemat gas) |
| 7 | [:141-174](../resources/js/polygon-transfer.js#L141-L174) | **User sign sendiri** `privateTransfer`, POST `/payment/relay` |
| 8 | [:176-187](../resources/js/polygon-transfer.js#L176-L187) | Simpan note kembalian (`saveNoteRecord`) + tandai note lama used |

Penyusunan kalimat proof untuk Solidity (urutan `b` dibalik) ada di [:148-150](../resources/js/polygon-transfer.js#L148-L150).

`scanIncomingNotes()` [polygon-transfer.js:284](../resources/js/polygon-transfer.js#L284) — **penemuan dana masuk**:
baca event `EncryptedNote` lewat **proxy server** (`SCAN_RPC_URL` → `/payment/scan-rpc`,
[:295-298](../resources/js/polygon-transfer.js#L295-L298)); batch handler trial-decrypt tiap memo
([:319](../resources/js/polygon-transfer.js#L319)), verifikasi commitment cocok ([:321-322](../resources/js/polygon-transfer.js#L321-L322)),
cek masih aktif `isCommitmentActive` ([:325](../resources/js/polygon-transfer.js#L325)), simpan note ([:327-331](../resources/js/polygon-transfer.js#L327-L331)).
Catat terima privat **tanpa tx_hash** via `receipt_ref` opaque ([:338-343](../resources/js/polygon-transfer.js#L338-L343), mitigasi M1).
Probe range besar→kecil + `isRangeError` ([:236](../resources/js/polygon-transfer.js#L236), [:361-373](../resources/js/polygon-transfer.js#L361-L373)) untuk siasati limit `getLogs`; cursor resumable `SCAN_CURSOR_PREFIX` ([:194](../resources/js/polygon-transfer.js#L194), [:300-306](../resources/js/polygon-transfer.js#L300-L306)).

### 6.5 Withdraw — [polygon-withdraw.js](../resources/js/polygon-withdraw.js)

Decrypt note → Groth16 `withdraw` → preview `/payment/withdraw/verify` → sign & relay tx `withdraw`.
Full-burn (`amount == balance` note).

### 6.6 Note (penyimpanan & kripto) & utilitas lain

| Modul | File | Peran |
|---|---|---|
| **note-store.js** | [note-store.js](../resources/js/note-store.js) | Simpan/baca note terenkripsi di localStorage (AES-GCM, kunci PBKDF2 `password+email`); `saveNoteRecord` ([:100](../resources/js/note-store.js#L100)) memicu **backup ke server** via hook `window.NoteBackup.pushBackup` ([:118-119](../resources/js/note-store.js#L118-L119)); `decryptNote` |
| **note-backup.js** | [note-backup.js](../resources/js/note-backup.js) | Backup note terenkripsi ke server: `computeRef` (ref opaque), `pushBackup` (+antrian), `flushPending` (kirim ulang tanpa password), `syncOnLogin` (PULL decrypt + SWEEP push, butuh password). Server tak pernah lihat plaintext |
| **account-guard.js** | [account-guard.js](../resources/js/account-guard.js) | `verify`/`assertPassword`: derive Schnorr pubkey dari `(email,password)` lalu cocokkan dengan `zk_public_key` akun (meta `account-schnorr-pub`) — batalkan operasi bila password salah, **tanpa kirim ke server** |
| **note-crypto.js** | [note-crypto.js](../resources/js/note-crypto.js) | ECIES (`eciesEncrypt`/`eciesDecrypt`) + derive enc-keypair untuk memo penerima |
| **payment-relay.js** | [payment-relay.js](../resources/js/payment-relay.js) | Helper fee floor Amoy (`amoyFloorFees`) + util relay |
| **pool-balance.js** | [pool-balance.js](../resources/js/pool-balance.js) | Hitung saldo pool dari note lokal (`computePoolBalance` butuh password; `tallyActiveNotes` re-cek on-chain tanpa password → refresh live di Dashboard) |
| **private-send.js** | [private-send.js](../resources/js/private-send.js) | Orkestrasi transfer privat: `parseRecipientCode` (URI/zkpub) + pilih note + `transferFromPool`. Dipakai scan & manual |
| **record-event.js** | [record-event.js](../resources/js/record-event.js) | `recordEvent()` → `POST /payment/record-event` (best-effort). **Privasi**: `private_transfer` kirim hanya `tx_hash`+`type`; `private_receive` kirim hanya `receipt_ref` opaque (**tanpa** tx_hash) — gagal mencatat tak membatalkan tx |
| **zk-snark.js** | [zk-snark.js](../resources/js/zk-snark.js) | `generateBalanceProof` ([:162](../resources/js/zk-snark.js#L162)) + util Poseidon fallback/verify |
| **qr-scanner.js** | [qr-scanner.js](../resources/js/qr-scanner.js) | Scan QR (kamera/manual), mode-aware: plain → relay; privat → `sendPrivate` |
| **receive-qr.js / xevou-uri.js** | [receive-qr.js](../resources/js/receive-qr.js) | Render QR terima + parse URI `xevouzk:` |

> Catatan: [zk-snark.js](../resources/js/zk-snark.js) berisi jalur Poseidon *fallback* dan
> *simulated proof* ([:248](../resources/js/zk-snark.js#L248)) — itu untuk dev/test; commitment
> nyata transfer/withdraw memakai Poseidon asli circomlibjs di [polygon-transfer.js](../resources/js/polygon-transfer.js)/[shield-key.js](../resources/js/shield-key.js).

---

## 7. Circuits (Circom) — definisi "apa yang dibuktikan"

**Folder: [circuits/](../circuits/)**. Tiap `.circom` mendefinisikan constraint yang harus dipenuhi proof.

### 7.1 balance_check.circom — [circuits/balance_check.circom](../circuits/balance_check.circom)

Buktikan `balance >= minAmount` tanpa bongkar `balance`.
- Witness (rahasia): `balance`, `salt` ([:23-24](../circuits/balance_check.circom#L23-L24)).
- Public: `minAmount`, `balanceCommitment` ([:27-28](../circuits/balance_check.circom#L27-L28)).
- Constraint 1: `Poseidon(balance, salt) == balanceCommitment` ([:32-35](../circuits/balance_check.circom#L32-L35)).
- Constraint 2: `balance >= minAmount` ([:38-41](../circuits/balance_check.circom#L38-L41)).

### 7.2 private_transfer.circom — [circuits/private_transfer.circom](../circuits/private_transfer.circom) ⭐

Membelanjakan 1 note → mint 2 note (kembalian + penerima). Public signal = 4 nilai
yang persis dipakai kontrak ([:31-34](../circuits/private_transfer.circom#L31-L34)).

| Constraint | Baris | Makna |
|---|---|---|
| `senderShieldPub = Poseidon(senderShieldPriv)` | [:36-38](../circuits/private_transfer.circom#L36-L38) | Bukti tahu shieldPriv |
| `senderCommitment = Poseidon(amountIn, pub, senderSalt)` | [:41-45](../circuits/private_transfer.circom#L41-L45) | Note lama valid |
| `amountIn >= transferAmount` | [:48-51](../circuits/private_transfer.circom#L48-L51) | Saldo cukup |
| `change = amountIn - transferAmount` (≥0) | [:54-59](../circuits/private_transfer.circom#L54-L59) | Kembalian non-negatif |
| `nullifier = Poseidon(senderShieldPriv, senderCommitment)` | [:62-65](../circuits/private_transfer.circom#L62-L65) | Anti double-spend |
| `newSelfCommitment = Poseidon(change, pub, changeSalt)` | [:68-72](../circuits/private_transfer.circom#L68-L72) | Note kembalian |
| `recipientCommitment = Poseidon(transferAmount, recipientShieldPub, recipientSalt)` | [:75-79](../circuits/private_transfer.circom#L75-L79) | Note penerima |

### 7.3 withdraw.circom — [circuits/withdraw.circom](../circuits/withdraw.circom)

Buktikan kepemilikan note → tarik MATIC. `recipient` & `amount` **publik** (ujung fiat ramp).
- `shieldPub = Poseidon(shieldPriv)` ([:30-31](../circuits/withdraw.circom#L30-L31)).
- `commitment = Poseidon(amount, shieldPub, salt)` ([:34-38](../circuits/withdraw.circom#L34-L38)).
- `nullifier = Poseidon(shieldPriv, commitment)` ([:41-44](../circuits/withdraw.circom#L41-L44)).
- `recipient` passthrough anti-frontrun ([:47-48](../circuits/withdraw.circom#L47-L48)).

### 7.4 Script build circuit — [circuits/scripts/](../circuits/scripts/)

| Script | Fungsi |
|---|---|
| [setup.js](../circuits/scripts/setup.js) | Compile circuit + trusted setup Phase 2 → `*_final.zkey` |
| [export-keys.js](../circuits/scripts/export-keys.js) | Export `verification_key.json` |
| [export-verifiers.js](../circuits/scripts/export-verifiers.js) | Export verifier Solidity dari VK |
| [test-proofs.js](../circuits/scripts/test-proofs.js) | Test generate/verify proof (+ negative case) |

> **Konsistensi wajib**: `verification_key.json` (client zkey) dan verifier Solidity
> on-chain harus dari setup yang sama. Ubah circuit → build ulang ketiga rantai.

---

## 8. Smart contract (Solidity)

**Folder: [contracts/contracts/](../contracts/contracts/)**.

### 8.1 ZKPayment.sol — [contracts/contracts/ZKPayment.sol](../contracts/contracts/ZKPayment.sol) ⭐ kontrak utama

Pool commitment (model Tornado-light). State penting:
- `activeCommitments` mapping ([:32](../contracts/contracts/ZKPayment.sol#L32)) — note hidup/burned.
- `nullifiers` mapping ([:33](../contracts/contracts/ZKPayment.sol#L33)) — **anti double-spend canonical**.

| Baris | Fungsi | Apa yang dikerjakan |
|---|---|---|
| [ZKPayment.sol:76-85](../contracts/contracts/ZKPayment.sol#L76-L85) | `deposit(commitment)` payable | Aktifkan commitment, tambah `totalDeposited`, emit `Deposit` |
| [ZKPayment.sol:105-134](../contracts/contracts/ZKPayment.sol#L105-L134) | `privateTransfer(...)` | **Inti transfer privat.** Cek nullifier ([:118](../contracts/contracts/ZKPayment.sol#L118)), verifikasi proof ([:124](../contracts/contracts/ZKPayment.sol#L124)), burn lama + mint 2 baru ([:126-129](../contracts/contracts/ZKPayment.sol#L126-L129)), emit `PrivateTransfer` + `EncryptedNote` ([:132-133](../contracts/contracts/ZKPayment.sol#L132-L133)) |
| [ZKPayment.sol:143-169](../contracts/contracts/ZKPayment.sol#L143-L169) | `withdraw(...)` | Verifikasi proof, burn commitment, **transfer MATIC** ke recipient ([:165](../contracts/contracts/ZKPayment.sol#L165)) |
| [ZKPayment.sol:171-181](../contracts/contracts/ZKPayment.sol#L171-L181) | `isCommitmentActive` / `isNullifierUsed` / `getContractBalance` | View helper |
| [ZKPayment.sol:183-199](../contracts/contracts/ZKPayment.sol#L183-L199) | `update*Verifier` | `onlyOwner` ganti alamat verifier |

Public signal urutan (penting, harus cocok circuit & client):
- `privateTransfer`: `[oldCommitment, nullifier, newSelfCommitment, recipientCommitment]` ([:103](../contracts/contracts/ZKPayment.sol#L103)).
- `withdraw`: `[commitment, nullifier, recipient_uint, amount_wei]` ([:139](../contracts/contracts/ZKPayment.sol#L139)).

> Catatan privasi: `privateTransfer` **sengaja tidak cek `msg.sender`** ([:105-111](../contracts/contracts/ZKPayment.sol#L105-L111))
> — keabsahan 100% dari ZK proof + nullifier. Itu yang memungkinkan relayer ditambah kelak.

### 8.2 Verifier — [contracts/contracts/verifiers/](../contracts/contracts/verifiers/)

`BalanceCheckVerifier.sol`, `PrivateTransferVerifier.sol`, `WithdrawVerifier.sol` —
**di-generate snarkjs** dari `verification_key.json`. Jangan edit manual.
`Mock*Verifier.sol` hanya untuk test.

### 8.3 Script & test — [contracts/scripts/](../contracts/scripts/) & [contracts/test/](../contracts/test/)

| File | Fungsi |
|---|---|
| [deploy.js](../contracts/scripts/deploy.js) | Deploy 3 verifier + ZKPayment ke Amoy |
| [test-transfer-e2e.js](../contracts/scripts/test-transfer-e2e.js) | E2E deposit→transfer→withdraw real on-chain |
| [test/ZKPayment.test.js](../contracts/test/ZKPayment.test.js) | 34 unit test Hardhat (lifecycle, double-spend, admin guard) |

---

## 9. Telusur per-fitur (di mana kode untuk X?)

Tabel cepat "saya mau lihat fitur X, mulai dari mana":

| Fitur | Browser | Server | On-chain |
|---|---|---|---|
| **Register non-custodial** | derive 3 key ([polygon-key.js](../resources/js/polygon-key.js), [schnorr-auth.js](../resources/js/schnorr-auth.js), [shield-key.js](../resources/js/shield-key.js)) | [AuthController@register :36](../app/Http/Controllers/AuthController.php#L36) | — |
| **Login Schnorr** | [schnorr-auth.js@sign :36](../resources/js/schnorr-auth.js#L36) | [AuthController@verifySchnorrLogin :151](../app/Http/Controllers/AuthController.php#L151) + [SchnorrService@verify :65](../app/Services/SchnorrService.php#L65) | — |
| **Deposit** | [polygon-deposit.js@depositToPool :52](../resources/js/polygon-deposit.js#L52) | [PaymentController@relayRawTransaction :116](../app/Http/Controllers/PaymentController.php#L116) | [ZKPayment@deposit :76](../contracts/contracts/ZKPayment.sol#L76) |
| **Transfer privat** | [polygon-transfer.js@transferFromPool :60](../resources/js/polygon-transfer.js#L60) | [PaymentController@previewTransfer :391](../app/Http/Controllers/PaymentController.php#L391) + `relay` | [ZKPayment@privateTransfer :105](../contracts/contracts/ZKPayment.sol#L105) |
| **Terima (scan note)** | [polygon-transfer.js@scanIncomingNotes :284](../resources/js/polygon-transfer.js#L284) | [PaymentController@scanRpc :159](../app/Http/Controllers/PaymentController.php#L159) (proxy getLogs; decrypt di klien) | event `EncryptedNote` ([ZKPayment.sol:46](../contracts/contracts/ZKPayment.sol#L46)) |
| **Withdraw** | [polygon-withdraw.js](../resources/js/polygon-withdraw.js) | [PaymentController@previewWithdraw :357](../app/Http/Controllers/PaymentController.php#L357) + `relay` | [ZKPayment@withdraw :143](../contracts/contracts/ZKPayment.sol#L143) |
| **QR (static/dynamic)** | [qr-scanner.js](../resources/js/qr-scanner.js), [receive-qr.js](../resources/js/receive-qr.js) | [PaymentController@scanQrApi :68](../app/Http/Controllers/PaymentController.php#L68) + [QRCodeService](../app/Services/QRCodeService.php) | — |
| **Faucet test MATIC** | UI dashboard | [WalletController@requestTestMatic :312](../app/Http/Controllers/WalletController.php#L312) + [FaucetService](../app/Services/FaucetService.php) | tx dari MASTER wallet |
| **Backup note lintas-device** | [note-backup.js](../resources/js/note-backup.js) (hook di [note-store.js:118](../resources/js/note-store.js#L118)) | [NoteBackupController@store :18](../app/Http/Controllers/NoteBackupController.php#L18) | — (off-chain, ciphertext opaque) |
| **Account guard (cek password)** | [account-guard.js@assertPassword](../resources/js/account-guard.js) | — (verifikasi 100% di klien) | — |
| **Anti double-spend** | hitung nullifier ([polygon-transfer.js:93](../resources/js/polygon-transfer.js#L93)) | [ZKSNARKService@verifyNullifier :542](../app/Services/ZKSNARKService.php#L542) | `nullifiers` mapping ([ZKPayment.sol:33](../contracts/contracts/ZKPayment.sol#L33)) |

---

> **Cara pakai dokumen ini**: cari fitur di §9 → lompat ke file/baris → baca penjelasan
> per-baris di §3–§8. Untuk *kenapa* di balik desain, lihat
> [PROJECT-UNDERSTANDING.md](PROJECT-UNDERSTANDING.md); untuk *matematika*, lihat
> [ZK-SNARK-DOCUMENTATION.md](ZK-SNARK-DOCUMENTATION.md).
