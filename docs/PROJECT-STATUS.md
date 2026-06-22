# XevouZK — Project Status & TA Defense Brief

> Snapshot terakhir: **2026-06-22**.
> Audience: penulis TA (pertahanan panel) + reviewer code.
>
> Update 2026-06-22: (1) **Backup note terenkripsi lintas-device** — browser
> mem-push ciphertext AES-GCM + `ref` opaque ke `note_backups` lewat
> `POST/GET /notes/backup`; server tak pernah melihat plaintext/salt/nominal
> (lihat §3.8). (2) **Account guard** — operasi sensitif memverifikasi password
> via pencocokan Schnorr pubkey turunan vs `zk_public_key` akun, tanpa kirim
> password (§3.8). (3) **Proxy scan RPC** `POST /payment/scan-rpc` — `scanIncomingNotes`
> memanggil same-origin proxy agar RPC ber-API-key (`POLYGON_SCAN_RPC_URL`) tetap
> di server (§3.4). (4) `bootstrap/app.php` mempercayai `X-Forwarded-*` (akses via
> tunnel untuk uji HP) + CSRF-exempt `payment/scan-rpc`. (5) **Suite PHPUnit + `phpunit.xml`
> dihapus** dari repo — bukti TA via Hardhat + E2E on-chain + snarkjs (hasil terukur
> 2026-06-22 di [TESTING-EVIDENCE](TESTING-EVIDENCE.md)). Catatan: harness vektor interop
> Schnorr (`tools/schnorr-interop-vectors.mjs`) **belum ada di repo** — jangan diklaim sbg bukti.
>
> Koreksi 2026-06-19: penyelarasan penamaan dengan source circom —
> `newCommitment`→`newSelfCommitment` (skenario demo §7), `wrong-secret`→
> `wrong-shieldPriv` (negative case proof). Detail skema circuit terkoreksi penuh di
> [ZK-SNARK-DOCUMENTATION §5](ZK-SNARK-DOCUMENTATION.md).
>
> Update 2026-06-13: (1) login kini **murni Schnorr** — `Auth::attempt` dihapus,
> password **tidak lagi dikirim** ke server (login maupun register); kolom
> `users.password` diisi **hash acak placeholder**, bukan hash password user.
> Ditambah single-use **replay-nonce** + **rate limiting** login. (2) Sisi penerima
> transfer privat kini dicatat sebagai `private_receive` lewat
> `POST /payment/record-event` ([record-event.js](../resources/js/record-event.js)) —
> nominal & hash tx **tidak** disimpan (unlinkable, mitigasi M1). Lihat §3.2, §3.4, §4.
>
> Update 2026-06-06: transfer privat **self-signed oleh user** (commitment
> pool + memo ECIES via event `EncryptedNote`); jalur mock master-relay
> (`/payment/process`) dihapus — master wallet = faucet saja, bukan gasless
> relayer. Frontend tetap fully **Vite-bundled** dari `node_modules` di
> `resources/js/`. Runtime: lokal via Laravel Herd (tanpa hosting).

---

## 1. Executive Summary

**XevouZK** = sistem pembayaran digital **P2P non-custodial** yang mengintegrasikan
**Schnorr signature** (autentikasi), **Groth16 zk-SNARK** (verifikasi saldo dan
transaksi privat), dan **QR Code** di atas **Polygon Amoy testnet**.

**Status**: Code base lengkap, smart contract live di Amoy, end-to-end ZK pathway
terbukti on-chain, dan wallet user non-custodial (private key di-derive di
browser, tidak pernah masuk ke server).

### Highlight fungsional

| Aspek | Implementasi |
|---|---|
| Autentikasi | Schnorr signature (secp256k1, Fiat-Shamir), password tidak ditransmit |
| Wallet | Non-custodial — keypair Schnorr + keypair Polygon di-derive deterministik di browser dari `(email, password)` |
| Tx outbound user | Browser sign pakai `ethers.js v6` → server relay raw tx via `POST /payment/relay` (`eth_sendRawTransaction`) |
| Transfer privat | Groth16 proof di browser, verifikasi on-chain via `ZKPayment.privateTransfer`, anti double-spend via nullifier |
| Settlement on-chain | Pool commitment-based: deposit MATIC → privateTransfer (burn old, mint new) → withdraw ke recipient (full-burn) |
| QR Code P2P | Static (alamat) + Dynamic (payment request dengan HMAC signature + 15 menit expiration) |

### Bukti fungsionalitas end-to-end

| Layer | Bukti |
|---|---|
| Smart contract behavioral | **34 Hardhat test passing** untuk `ZKPayment.sol` (deposit lifecycle, privateTransfer state machine + `EncryptedNote` memo event, withdraw + invariant `pool == deposited − withdrawn`, double-spend revert, admin guard) |
| Local Groth16 proof | snarkjs generate + verify untuk `balance_check`, `private_transfer` (skema shielded-keypair pool-compatible), dan `withdraw` (termasuk negative case: tampered proof rejected, wrong-shieldPriv rejected) |
| **On-chain real proof (Fase 2b)** | **Lifecycle private transfer terbukti end-to-end on-chain** di Amoy (2026-06-05, VK real circuit baru): deposit [`0x1e551699…`](https://amoy.polygonscan.com/tx/0x1e55169930abda795dd33273c20a234e5f0de8e3ef84fe183ed0e6ba565f5362) → privateTransfer burn+mint2+`EncryptedNote` [`0x94e91fe7…`](https://amoy.polygonscan.com/tx/0x94e91fe79f2f72d869a201dcd4f4eed068f9ddb232da87d827d6c39ad9e31d45) → withdraw note penerima [`0xe1b4002b…`](https://amoy.polygonscan.com/tx/0xe1b4002b90b0a1c63ff15d482fff9a384b581afc72e1b80db213e5b294d9a68a) → replay ditolak. Harness: [`contracts/scripts/test-transfer-e2e.js`](../contracts/scripts/test-transfer-e2e.js) |
| Schnorr JS↔PHP | Implementasi [`schnorr-auth.js`](../resources/js/schnorr-auth.js) (browser, `@noble/curves`) & [`SchnorrService.php`](../app/Services/SchnorrService.php) (server, `simplito/elliptic-php`) mengikuti **algoritma + domain-separation identik** (dapat diverifikasi dari sumber) dan dipakai di alur login. ⚠️ Harness vektor byte-identik otomatis (`tools/schnorr-interop-vectors.mjs`) **belum ada** — future work |
| Jalur HTTP server | Suite PHPUnit + `phpunit.xml` **dihapus** dari repo (2026-06-22). Correctness server dipertanggungjawabkan lewat E2E on-chain di Amoy + Hardhat; fitur sisi-klien baru (backup note, account-guard, proxy scan) diverifikasi via smoke test browser manual |

---

## 2. Deployed contracts (Polygon Amoy, chainId 80002)

Versi live (redeploy 2026-06-05, Fase 2b — `privateTransfer` + memo `EncryptedNote`,
verifier `private_transfer` skema shielded-keypair baru):

| Contract | Address | PolygonScan |
|---|---|---|
| BalanceCheckVerifier | `0x5653778d4c1C2257Eb65fAa69B714364E7a01363` | [view](https://amoy.polygonscan.com/address/0x5653778d4c1C2257Eb65fAa69B714364E7a01363#code) |
| PrivateTransferVerifier | `0x5500d21AC089152c0131eC0B7fB97Ad72ED40457` | [view](https://amoy.polygonscan.com/address/0x5500d21AC089152c0131eC0B7fB97Ad72ED40457#code) |
| WithdrawVerifier | `0xa6ff8557D425Bc32D582c544E3DBBfd48Ec56056` | [view](https://amoy.polygonscan.com/address/0xa6ff8557D425Bc32D582c544E3DBBfd48Ec56056#code) |
| **ZKPayment v2** | **`0x105e6DB96C697DA8ca0952116bEA12AAbFF359B5`** | [view](https://amoy.polygonscan.com/address/0x105e6DB96C697DA8ca0952116bEA12AAbFF359B5#code) |

Owner kontrak v2: **`0xF90BA9d8AD592b2B2deB7495C9357383E273Adf4`** (= wallet
deployer, **terpisah** dari MASTER wallet runtime). Klaim least privilege berlaku
untuk kontrak live — lihat §5.

State awal post-deploy terverifikasi: `totalDeposited = 0`, `totalWithdrawn = 0`,
`transactionCount = 0`. **Source keempat kontrak ter-verify di PolygonScan** (2026-06-06).
Deploy lama (2026-05-21) di-supersede.

Deployment artifact: [contracts/deployments/amoy.json](../contracts/deployments/amoy.json).
Untuk reproduce / re-deploy, lihat [DEPLOY-GUIDE.md](DEPLOY-GUIDE.md).

---

## 3. Arsitektur

### 3.1 Wallet non-custodial

Saat register, browser men-derive **dua keypair secp256k1** deterministik dari
`(email, password)`:

- **Schnorr key** dengan label `schnorr_v1:` → untuk autentikasi.
- **Polygon key** dengan label `polygon_v1:` → untuk wallet (key separation).

Polygon address = EIP-55 checksum dari `keccak256(pub_x || pub_y)[-20:]`.

POST `/register` hanya mengirim public artifacts: `schnorr_public_key`,
`polygon_address`, `polygon_public_key`. Tabel `wallets` **tidak punya kolom**
`encrypted_private_key`. Server tidak punya kuasa untuk mengirim atas nama user
pada mode plain transfer — semua tx outbound di-sign di browser.

### 3.2 Autentikasi Schnorr

Client memakai Schnorr key untuk sign message
`lower(email) || "|" || timestamp || "|" || csrf_token`
(anti-replay window 5 menit, anti cross-session via CSRF binding).

Server verify dengan `SchnorrService::verify` (secp256k1 + Fiat-Shamir, lib
`simplito/elliptic-php`) → jika lulus → `Auth::login($user)` Laravel standar.
`Auth::attempt` (password) **sudah dihapus** — login 100% Schnorr.

**Password tidak pernah ditransmit** untuk autentikasi **maupun register** — form
tidak punya field `name="password"`; password hanya dipakai di browser untuk
derive key. Kolom `users.password` diisi **hash acak placeholder**
(`Hash::make(Str::random(40))`), **bukan** hash password user, dan tak pernah
dicocokkan. Hardening login: timestamp window 300 detik, **single-use replay-nonce**
(`Cache::add` atomik per `sha256(signature)`), serta **rate limiting** per
(email+IP) maks 5 gagal + `throttle:10,1` per-IP di route.

### 3.3 Payment plain non-custodial

```
Client (browser)                 Server (Laravel)              Polygon Amoy
─────────────────                ──────────────                ────────────
prompt password
priv_polygon = derive(email, password, "polygon_v1")
fetch nonce + feeData
build EIP-1559 tx
sign tx (ethers.js v6)
POST /payment/relay ───────────►  validate { raw_tx: 0x... }
{ raw_tx }                        PolygonService::
                                    sendRawTransaction ─────► eth_sendRawTransaction
                                                                  (node validate sig + nonce + gas)
                                  return { tx_hash }
◄──────────────────────────────  JSON { success, tx_hash }
```

`msg.sender` di explorer = polygon_addr user. Server hanya broadcaster.

### 3.4 Payment privat ZK (commitment pool, self-signed)

Transfer privat = belanjakan note pool → mint note kembalian + note penerima,
kirim note penerima sebagai memo ECIES di event on-chain. **Semua di-sign oleh
user sendiri** (tidak ada gasless relayer; master = faucet saja). Entry point:
`/payment/scan` (scan **QR Privat (zkpub)** penerima) **atau** `/payment` tab
**Privat (Pool)** (tempel viewing key) → keduanya lewat `sendPrivate` → `transferFromPool`.

```
Client (browser)                 Server (Laravel)              Polygon Amoy
─────────────────                ──────────────                ────────────
decrypt note pengirim (localStorage)
derive shieldPriv + Polygon key
pilih changeSalt + recipientSalt
generate transfer proof
  (snarkjs.fullProve private_transfer)
ECIES memo {amount,salt,commitment}
POST /payment/transfer/verify ─►  verifyTransferProof
  (preview, hemat gas)              (struct + nullifier DB)   ← TIDAK relay di sini
◄──────────────────────────────  { ok, public_inputs }
sign privateTransfer(a,b,c,
  pubSignals, encryptedNote)       [user key]
POST /payment/relay ───────────►  sendRawTransaction ───────► ZKPayment.privateTransfer
{ raw_tx }                        (broadcast only)              - verify Groth16 (pairing)
                                                                - require !nullifiers[n]
                                                                - nullifiers[n] = true
                                                                - burn senderCommitment
                                                                - mint newSelfCommitment + recipientCommitment
                                                                - emit EncryptedNote(recipientCommitment, memo)
                                  ◄── { tx_hash }             [msg.sender = USER]
simpan note kembalian + mark note used
```

Penerima menemukan dananya lewat **`scanIncomingNotes`** (`/dashboard` → "Cek
Transfer Masuk"): scan event `EncryptedNote`, trial-decrypt memo ECIES dengan
enc-key penerima, verifikasi `recipientCommitment`, simpan note. Scan dilakukan
lewat **proxy server `POST /payment/scan-rpc`** (bukan langsung dari browser):
RPC publik gratis kini membatasi `eth_getLogs` historis, jadi proxy meneruskan
method read-only yang di-whitelist ke RPC ber-API-key (`POLYGON_SCAN_RPC_URL`) —
kunci API tetap di server, **dekripsi memo tetap 100% di klien**. Rentang scan
dipersempit dari `POLYGON_CONTRACT_DEPLOY_BLOCK` dengan cursor resumable di
localStorage.

**Riwayat (best-effort, privacy-preserving)**: tiap sisi mencatat event pool ke
DB lewat `POST /payment/record-event` ([record-event.js](../resources/js/record-event.js) →
`PaymentController::recordPoolEvent`), tapi nominal transfer privat **tidak pernah**
dikirim (`transactions.amount` = NULL untuk `private_transfer`/`private_receive`).
Baris penerima (`private_receive`) **tidak menyimpan `polygon_tx_hash`** dan
idempotensinya pakai `receipt_ref` opaque (`sha256(commitment‖salt)`, salt rahasia
note) → server **tak bisa men-`JOIN`** penerima ke transaksi pengirim (unlinkable;
mitigasi M1, lihat [PRIVACY-GAP-ANALYSIS §3.I](PRIVACY-GAP-ANALYSIS.md)).

**Catatan privasi (jujur)**: kontrak `privateTransfer(a,b,c,pubSignals,memo)`
tidak mengecek `msg.sender`, dan validitas 100% dari ZK proof + nullifier guard.
Karena user yang menandatangani, `msg.sender = alamat user` → pengamat tahu
*bahwa* user melakukan privateTransfer, tetapi **nominal & penerima tetap
tersembunyi** (hanya commitment Poseidon + memo terenkripsi). Untuk menyembunyikan
metadata sender juga, gasless relayer bisa ditambahkan kelak tanpa redeploy
(perubahan sisi client) — lihat §7 Q&A & roadmap.

### 3.5 Deposit & withdraw pool

- **Deposit**: browser hitung `commitment = Poseidon(amount_wei, secret, salt)`,
  encrypt note (`{secret, salt, amount}`) pakai AES-GCM (key = PBKDF2 dari
  `password + email`), simpan di `localStorage`. Sign tx
  `ZKPayment.deposit{value: amount}(commitment)` di browser, relay via
  `/payment/relay`.
- **Withdraw**: browser decrypt note, generate Groth16 proof via
  `snarkjs.fullProve` (~5–30 detik), preview validity via
  `POST /payment/withdraw/verify`, sign tx
  `ZKPayment.withdraw(a, b, c, pubSignals)`, relay via `/payment/relay`.
  Recipient + amount adalah public signals (sengaja transparan — ujung "fiat
  ramp"). Full-burn semantics: tidak ada partial withdraw; untuk partial,
  privateTransfer dulu ke diri sendiri untuk memecah note.

### 3.6 QR Code P2P

- **Static QR**: alamat wallet only (no DB, no expiration), untuk receive ad-hoc.
- **Dynamic QR**: `{amount, description}` + HMAC signature, persist di
  `qr_codes`, expire 15 menit, anti-replay via `used_at` flag.
- Server-side `/payment/qr/scan` validate signature + expiration; client tidak
  decrypt sendiri.

### 3.7 Smart contract layer

`ZKPayment.sol` (v2) — commitment pool + 3 verifier:

```
state:
  mapping(uint256 => bool) activeCommitments   // note pool
  mapping(bytes32 => bool) nullifiers           // anti-replay
  totalDeposited / totalWithdrawn / transactionCount
  owner

methods:
  deposit(commitment) payable
  privateTransfer(a, b, c, pubSignals[4])
  withdraw(a, b, c, pubSignals[4])
  admin: updateVerifier×3, emergencyWithdraw

invariant:
  address(this).balance == totalDeposited − totalWithdrawn
```

Trusted setup pakai Powers of Tau `pot14` + Phase 2 single-party contribution
(detail di [ZK-SNARK-DOCUMENTATION.md §6](ZK-SNARK-DOCUMENTATION.md)).

### 3.8 Backup note lintas-device & account guard

**Masalah**: note pool hanya hidup di `localStorage` perangkat → clear/ganti
browser = note hilang (kecuali ditemukan-ulang via scan event).

- **Backup note (zero-knowledge ke server)** — `resources/js/note-backup.js`,
  `NoteBackupController`, tabel `note_backups`. Browser mem-push **ciphertext
  AES-GCM yang identik dengan blob localStorage** + `ref` opaque
  (`sha256("xevou-note-backup-v1:"+commitment+":"+salt)`) ke `POST /notes/backup`.
  Server **tidak pernah** melihat plaintext/salt/nominal/commitment — hanya
  ciphertext + ref + `user_id`. Sinkron dua-arah saat reveal saldo
  (`syncOnLogin`: PULL decrypt blob server → merge localStorage; SWEEP push note
  lokal yang belum ter-backup), plus `flushPending` (kirim ulang antrian best-effort
  saat dashboard load). **Bukan password recovery** — kunci AES tetap turun dari
  `password+email`. Detail privasi: [PRIVACY-GAP-ANALYSIS §3.J](PRIVACY-GAP-ANALYSIS.md).
- **Account guard** — `resources/js/account-guard.js`. Password salah **tidak gagal**;
  ia diam-diam menurunkan identitas lain. Maka tiap operasi sensitif (deposit,
  withdraw, transfer, reveal saldo) memanggil `AccountGuard.assertPassword`: derive
  Schnorr pubkey dari `(email,password)` lalu cocokkan dengan `zk_public_key` akun
  (anchor publik `<meta name="account-schnorr-pub">`). Salah → dibatalkan **di klien**,
  password tak pernah dikirim.

---

## 4. Privacy posture (klaim akurat untuk TA)

Detail penuh di [PRIVACY-GAP-ANALYSIS.md](PRIVACY-GAP-ANALYSIS.md). Ringkasan:

### Yang TERSEMBUNYI dari Polygon explorer publik

- Saldo aktual sender (hanya commitment Poseidon yang muncul).
- Nominal exact transaksi privat (hanya `recipientCommitment` Poseidon + memo ECIES).
- Identitas penerima pada mode privat (recipient address tidak di event, hanya commitment; note dikirim terenkripsi via `EncryptedNote`).
- Password user (tak pernah dikirim ke server; `users.password` = hash acak placeholder, bukan hash password; di client hanya untuk derive key).
- **Nominal transfer privat juga tersembunyi dari server** — `transactions.amount` = NULL untuk `private_transfer`/`private_receive` (tak pernah dikirim).
- **Unlinkability** deposit ↔ withdraw (withdraw membuktikan keanggotaan pool tanpa mengungkap commitment mana) **dan** pengirim ↔ penerima privat (baris `private_receive` tanpa tx_hash, idempotensi via `receipt_ref` opaque).

### Yang TERLIHAT di explorer

- Sender mode plain (msg.sender pemanggil plain transfer = user wallet).
- **Sender mode privat = user wallet** (`msg.sender = user`; tx di-sign sendiri, bukan via relayer). Yang terbongkar hanya *fakta* user melakukan privateTransfer — **bukan** nominal atau penerima.
- Deposit & withdraw individu = publik (sifat ujung "fiat ramp").
- Nullifier hash (anonim, tidak terkait identitas).
- Timestamp tx, block number, gas.

### Yang TERLIHAT di server XevouZK (trusted)

- Saldo DB shadow (sync dari blockchain, untuk UX render).
- Nominal **hanya** untuk transaksi publik (`transactions.amount` cleartext untuk
  plain/deposit/withdraw — toh sudah publik on-chain). Nominal transfer privat = NULL.
- Public keys Schnorr & Polygon, alamat Polygon.
- DB sender/receiver wallet linkage **untuk jalur plain**; untuk privat, fakta "R menerima"
  tercatat (`receiver_wallet_id`) tapi **tanpa** tautan ke pengirim/tx (lihat §3.4 & M1).

### Yang TIDAK ADA di server

- ❌ Private key Polygon user — di-derive di browser, tidak pernah dikirim.
- ❌ Schnorr private key — di-derive di browser.
- ❌ Password (tak pernah diterima — login & register tidak mengirim field password; `users.password` = hash acak placeholder, bukan hash password user).
- ❌ Kolom `encrypted_private_key` di `wallets` — tidak pernah ada / dihapus permanen.

### Klaim TA yang akurat

> "XevouZK menggunakan Schnorr signature (secp256k1, Fiat-Shamir) untuk
> autentikasi tanpa mengirim password, dan Groth16 zk-SNARK untuk pembayaran P2P
> di mana nominal transaksi, saldo sender, dan identitas penerima tidak terlihat
> di Polygon blockchain explorer publik. Server XevouZK menyimpan ledger
> operasional internal untuk kebutuhan UX history dan audit."

### Klaim yang HARUS DIHINDARI

- ❌ "Saldo enkripsi end-to-end" (DB shadow ada).
- ❌ "Nominal tersembunyi total" (DB cleartext untuk history).
- ❌ "Trustless / tidak butuh percaya server" (nullifier source of truth = DB).
- ❌ "Trusted setup multi-party" (Phase 2 single-party).
- ❌ "Identitas sender transaksi privat tersembunyi" — `msg.sender = user` (tx di-sign sendiri). Yang tersembunyi adalah nominal & penerima, **bukan** fakta bahwa user bertransaksi.

---

## 5. Security posture — Prinsip Least Privilege

> Relevan untuk bab Keamanan TA.

### 5.1 Definisi

**Least privilege** = setiap aktor (user, service, wallet) hanya diberi
kewenangan **minimum yang ia butuhkan untuk perannya**. Kalau salah satu aktor
di-compromise, blast radius terbatas pada kewenangan minimum tersebut.

Di XevouZK, prinsip ini diterapkan pada **pemisahan dua wallet Polygon**.

### 5.2 Pemisahan deployer ↔ master

> Catatan runtime: XevouZK dijalankan **lokal via Laravel Herd** (tanpa hosting
> publik), jadi kedua key saat ini berada di mesin lokal yang sama. Pemisahan di
> bawah dipertahankan sebagai **keputusan desain** — MASTER tidak punya hak owner
> kontrak terlepas dari di mana ia dijalankan, dan pemisahan ini yang membuat
> deployment ke hosting (bila suatu saat dilakukan) tidak mewariskan hak admin.

| Wallet | Address | Peran | Frekuensi | Lokasi key |
|---|---|---|---|---|
| **MASTER** (`POLYGON_PRIVATE_KEY`) | `0x16a747...E6a4` | Faucet test MATIC (distribusi token testnet). **Bukan relayer** — tx privat user di-sign sendiri | Saat user minta faucet | Runtime lokal (Herd); **boleh** di hosting bila nanti di-deploy |
| **DEPLOYER** (`DEPLOYER_PRIVATE_KEY`) | `0xF90BA9...Adf4` | Tanda tangan tx deploy kontrak → otomatis jadi `owner` (hak `updateVerifier`, `emergencyWithdraw`) | Sesekali (saat deploy/upgrade) | Mesin lokal developer **saja** — by design tidak pernah di hosting |

### 5.3 Threat model

> Karena prototipe ini berjalan **lokal (Herd) tanpa hosting publik**, permukaan
> serangan jaringan praktis minimal saat ini. Skenario "hosting di-hack" di bawah
> bersifat **hipotetis / forward-looking** — relevan jika XevouZK kelak di-deploy
> ke server publik, dan menunjukkan bahwa desain pemisahan key sudah siap untuk itu.

| Skenario kompromi | Sebelum pemisahan | Setelah pemisahan |
|---|---|---|
| Hosting di-hack, `.env` server bocor (hipotetis — saat ini lokal) | Penyerang dapat hak owner: bisa swap verifier palsu, drain `emergencyWithdraw`, lock fungsi onlyOwner | Penyerang hanya dapat MASTER — kerugian terbatas pada saldo faucet test MATIC. Hak owner tetap aman di mesin lokal developer |
| Laptop dev di-malware | Penyerang dapat dua-duanya | Sama — laptop tetap single point of failure. Mitigasi: hardware wallet / disk encryption |
| Repo `.env` ter-commit accidental | Penyerang dapat full control kontrak | Penyerang dapat key yang ada di file tersebut saja. Kalau yang ter-commit `.env` hosting → DEPLOYER aman |

### 5.4 Catatan implementasi

- `ZKPayment.sol` **tidak punya `transferOwnership()`** — owner ditentukan
  permanen oleh siapa yang men-deploy. Pemisahan deployer/master efektif penuh
  sejak deploy 2026-05-21.
- Kontrak live di-own oleh DEPLOYER `0xF90BA9...Adf4` (verified on-chain via
  `zk.owner()`).
- File [.env.production.example](../.env.production.example) sengaja
  **menghilangkan `DEPLOYER_PRIVATE_KEY`** sebagai control teknis untuk skenario
  hosting (disiapkan, belum dipakai — runtime saat ini lokal via Herd) — server
  tidak punya kemampuan deploy walaupun ada yang mencoba menjalankan Hardhat dari
  dalam server.

### 5.5 Klaim TA yang akurat

> "XevouZK menerapkan prinsip least privilege dengan memisahkan wallet
> **deployer** (penanda tangan deploy kontrak, otomatis menjadi owner dengan
> hak administratif) dari wallet **master** runtime (sumber dana faucet test
> MATIC). Prototipe dijalankan lokal (Laravel Herd) tanpa
> hosting publik; private key deployer hanya berada di lingkungan pengembang.
> Sebagai keputusan desain, kompromi pada server (bila kelak di-host) tidak
> memberikan penyerang hak administratif atas smart contract karena server hanya
> memegang key MASTER yang tanpa hak owner."

### 5.6 Klaim yang harus DIHINDARI

- ❌ "Hardware wallet / multisig" — XevouZK pakai plaintext private key di `.env`.
- ❌ "Zero trust" — server Laravel masih trusted (source of truth nullifier & ledger UX).

---

## 6. File map kritis

### Backend (PHP)
```
app/Services/
├── SchnorrService.php           Schnorr secp256k1 + Fiat-Shamir
├── ZKSNARKService.php           Groth16 verify struct + nullifier DB
├── QRCodeService.php            QR static + dynamic, HMAC, expiration
├── PolygonService.php           Web3 RPC + sendTransaction + sendRawTransaction
├── FaucetService.php            Test MATIC distribution (5 MATIC / 24h)
└── WalletService.php            Wallet helper

app/Http/Controllers/
├── AuthController.php           Register non-custodial + Schnorr-only login (replay-nonce + rate limit)
├── PaymentController.php        relayRawTransaction + scanRpc (proxy getLogs) + recordRelayTransfer + recordPoolEvent + previewTransfer/previewWithdraw + QR scan
├── WalletController.php         Wallet info + faucet + receive-QR/decode-QR + liveState + publishPubkeys
└── NoteBackupController.php     Backup note terenkripsi lintas-device (store/index ciphertext opaque)
```

### Smart contracts
```
contracts/contracts/
├── ZKPayment.sol                Main: deposit + privateTransfer + withdraw
├── verifiers/
│   ├── BalanceCheckVerifier.sol   snarkjs-exported, real VK
│   ├── PrivateTransferVerifier.sol snarkjs-exported, real VK
│   └── WithdrawVerifier.sol       snarkjs-exported, real VK
└── MockVerifier.sol             Hardhat test helper

contracts/test/
└── ZKPayment.test.js            34 behavioral test

contracts/scripts/
├── deploy.js                    Deploy 4 kontrak → Amoy
├── check-balance.js             Pre-deploy balance check
└── test-transfer-e2e.js         End-to-end real proof di Amoy (cetak gas used per tx)
```

### Circuits
```
circuits/
├── balance_check.circom         2 public inputs (Poseidon + GreaterEqThan)
├── private_transfer.circom      4 public inputs (full transfer logic)
├── withdraw.circom              4 public inputs (commitment + nullifier + recipient + amount)
├── build/                       Compiled wasm + r1cs
├── keys/                        Final zkey + vkey JSON
└── scripts/
    ├── setup.js                 Trusted setup Phase 2 per circuit (setup:all)
    ├── export-keys.js           Salin vkey → storage/app/zk-keys
    ├── export-verifiers.js      Generate Solidity verifier → contracts/
    └── test-proofs.js           Local Groth16 generate + verify
```

### Frontend (JavaScript)

Modul ber-`import` library → di-bundle Vite dari `node_modules`, ditempatkan di
`resources/js/` (dimuat via `@vite([...])`). File classic tanpa library tetap di
`public/js/` (dimuat via `asset('js/...')`).
```
resources/js/                    (di-bundle Vite; @vite)
├── schnorr-auth.js              Schnorr key derivation + sign (@noble/curves)
├── polygon-key.js               Polygon key derivation + EIP-55 address
├── shield-key.js                Shielded keypair (Poseidon) untuk pool
├── note-store.js                Encrypted note ↔ localStorage (AES-GCM)
├── note-crypto.js               ECIES memo encrypt/decrypt + enc-keypair
├── payment-relay.js             ethers.js v6 signing (plain) → relay
├── pool-balance.js              Hitung saldo pool dari note lokal
├── polygon-deposit.js           Commitment + note + deposit tx (self-sign)
├── polygon-withdraw.js          Decrypt note + withdraw proof + tx (self-sign)
├── polygon-transfer.js          private_transfer proof + memo + scanIncomingNotes
├── private-send.js              Orkestrasi transfer privat (pilih note + transferFromPool) — scan & manual
├── record-event.js              Catat event pool ke riwayat server (privacy-preserving; private_receive tanpa tx_hash)
├── note-backup.js               Backup note terenkripsi ke server (pushBackup/flushPending/syncOnLogin)
├── account-guard.js             Verifikasi password vs zk_public_key akun (tanpa kirim ke server)
├── zk-snark.js                  Wrapper snarkjs (import, bukan window-global)
├── qr-scanner.js                qr-scanner + server scan
├── xevou-uri.js                 Encode/parse skema URI xevouzk:
├── receive-qr.js                Generate QR terima (plain + privat zkpub)
└── vendor-lucide.js             Bundle ikon Lucide

public/js/                       (classic, tanpa library; asset())
└── app.js · dashboard.js · wallet.js · live-updates.js
```

### Documentation
```
docs/
├── PROJECT-UNDERSTANDING.md     Primer konseptual: ZKP, arsitektur, istilah, catatan TA
├── PROJECT-STATUS.md            This file
├── ZK-SNARK-DOCUMENTATION.md    ZKP foundations + Schnorr + Groth16 + circuits + threat model + glossary
├── PRIVACY-GAP-ANALYSIS.md      Klaim TA vs realitas per data field
├── PENGUJIAN.md                 Panduan + template hasil bab Pengujian TA (7 jenis uji → klaim/command/tabel/bukti)
├── TESTING-EVIDENCE.md          Runbook + hasil terukur uji (Hardhat/gas/snarkjs/E2E)
└── DEPLOY-GUIDE.md              Operational runbook deploy ke Amoy
```

---

## 7. Demo script untuk TA defense

### Persiapan
```powershell
cd <project-root>
npm run build   # build asset Vite
# Herd menyajikan PHP di https://xevouzk.test (jalankan `herd link xevouzk` sekali).
# Untuk active development dengan HMR: npm run dev
```

### Skenario 0 — Non-custodial wallet derivation (5 menit, paling fundamental)
1. `/register` — isi form (autentikasi Schnorr **wajib**; tidak ada lagi checkbox "Schnorr Mode")
2. Open DevTools **Network** tab → submit form
3. Tunjukkan POST body: hanya `name`, `email`, `schnorr_public_key`,
   `polygon_address`, `polygon_public_key`. **Tidak ada private key — dan tidak ada
   `password`** (password hanya dipakai di browser untuk derive key).
4. Open DB (tinker / phpMyAdmin): `SELECT * FROM wallets WHERE user_id = ?`
5. Tunjukkan kolom `encrypted_private_key` **tidak ada** — server tidak punya kuasa.
   `users.password` berisi hash acak placeholder, bukan hash password user.
6. Konsekuensi: server bahkan tidak bisa transfer atas nama user, hanya relay
   raw signed tx yang dibuat di browser.

### Skenario 1 — Schnorr login (3 menit)
1. Logout dari akun yang baru dibuat.
2. Login lagi dengan email + password.
3. Tunjukkan Network tab: tidak ada password cleartext di request body, hanya
   `schnorr_signature` + `schnorr_timestamp` + `schnorr_public_key`.
4. Server verify signature → `Auth::login($user)` → session.

### Skenario 2 — QR Code P2P (3 menit)
1. `/wallet` — tunjukkan static QR (address only).
2. `/payment` tab "Generate QR" — buat dynamic QR dengan amount.
3. Akun lain `/payment/scan` — scan QR.
4. Tunjukkan server scan endpoint validate signature + expiration.

### Skenario 3 — Privacy ZK transaction (10 menit, paling impresif)
1. `/payment` → tab **Privat (Pool)** → tempel viewing key penerima + jumlah (atau `/payment/scan` → scan QR Privat).
2. Submit → tunjukkan DevTools console: snarkjs proof generation.
3. Setelah success → buka PolygonScan tab transaksi.
4. **Tunjukkan**: tx muncul sebagai `privateTransfer(...)` dengan input
   `senderCommitment`, `nullifier`, `newSelfCommitment`, `recipientCommitment`
   (semua hash Poseidon, **bukan** address + amount cleartext).
5. Bandingkan dengan tx plain MATIC transfer (jika ada): address `to` + value
   `eth` jelas.
6. Bukti: panel auditor blockchain tidak bisa tahu berapa amount, ke siapa, dst.

### Skenario 4 — Deposit & withdraw pool (10 menit, opsional advanced)
1. Login Alice → `/wallet` → klik **Request MATIC** (faucet) → tunggu konfirmasi.
2. Section "Pool Privat (ZKPayment)" → input `0.01` MATIC → **Sign & Deposit**.
   - Output: `tx_hash`, `commitment`, encrypted note di localStorage.
3. Logout, register Bob.
4. Login Alice, `/payment` → "Withdraw dari Pool Privat" → pilih note → recipient = Bob's address.
5. **Generate Proof & Withdraw** → tunggu 5–30 detik (proof) + konfirmasi tx.
6. Login Bob, `/wallet` → saldo on-chain Bob bertambah 0.01 MATIC.
7. Bukti: settlement on-chain sungguh-sungguh terjadi, bukan hanya simulasi DB.

### Q&A panel: jawaban siap-pakai
- *"Berapa user yang sudah pakai sistem ini?"* — "Ini prototipe TA, bukan
  production. Correctness dibuktikan via 34 Hardhat behavioral test untuk smart
  contract, 3× tx privateTransfer real on-chain di Amoy yang terverifikasi
  PolygonScan, dan end-to-end smoke test browser deposit→privateTransfer→withdraw
  yang berhasil transfer MATIC sungguhan."
- *"Apakah trustless?"* — "Tidak sepenuhnya. Nullifier source of truth di DB
  internal (lihat PRIVACY-GAP-ANALYSIS §3.D). Untuk trustless penuh, butuh
  server cek contract state sebelum DB insert."
- *"Apakah non-custodial?"* — "Ya, penuh. Private key user lahir di browser dari
  password; **semua** tx — plain transfer, deposit, privateTransfer, withdraw —
  ditandatangani di browser dengan key user, server hanya relay raw signed tx
  (`/payment/relay`). Master wallet **tidak** menandatangani transaksi user; ia
  hanya melayani faucet test MATIC. Konsekuensinya `msg.sender` transaksi privat
  = alamat user, tapi nominal & penerima tetap tersembunyi (commitment Poseidon
  + memo ECIES). Menyembunyikan metadata sender juga butuh gasless relayer —
  future work, dan kontrak sudah siap karena tidak mengecek `msg.sender`."
- *"Berapa biaya transaksi?"* — "Gas terukur on-chain di Amoy (35 gwei,
  2026-06-13): privateTransfer **478k** gas (~0.0167 MATIC), withdraw **428k**
  (~0.0150 MATIC), deposit **75k** (~0.0026 MATIC), plain transfer ~21k. Deposit
  murah karena **tidak** memverifikasi proof; withdraw/privateTransfer mahal
  karena menjalankan verifikasi Groth16 (pairing EIP-197) on-chain. Biaya
  verifikasi ini **konstan** terhadap nominal, jadi note sangat kecil tidak
  ekonomis untuk di-withdraw (lihat [PROJECT-UNDERSTANDING §10](PROJECT-UNDERSTANDING.md#10-penjelasan-istilah-yang-sering-ditanya)).
  Untuk production scale butuh optimisasi Poseidon round atau Plonk."
- *"Apa yang terjadi kalau user lupa password?"* — "Wallet hilang permanen.
  Ada backup note terenkripsi lintas-device, tapi itu menyelamatkan note saat
  ganti/hilang **device** — **bukan** recovery saat password lupa (kunci AES tetap
  turun dari password). Untuk scope production butuh BIP-39 mnemonic backup."
- *"Note saya hilang kalau ganti browser?"* — "Tidak, selama backup aktif: note
  di-push terenkripsi (AES-GCM) ke server dengan ref opaque, lalu di-pull & decrypt
  di device lain saat login (butuh password). Server tak pernah lihat isi note."
- *"Mengapa trusted setup single-party?"* — "Trade-off prototipe TA. Risiko:
  kalau toxic waste Phase 2 bocor, fabricate proof palsu mungkin. Untuk
  production, ceremony multi-party diperlukan. Phase 1 (Powers of Tau)
  XevouZK pakai pot14 dari Hermez community ceremony multi-party, jadi yang
  single-party hanya kontribusi Phase 2 circuit-specific."

---

## 8. Acknowledgement & references

- **Circom 2.1.6** + **snarkjs** untuk circuit + trusted setup.
- **circomlib** untuk Poseidon + GreaterEqThan primitives.
- **@noble/curves** v1.4 untuk secp256k1 client-side.
- **simplito/elliptic-php** untuk Schnorr server-side.
- **Hardhat** + **ethers.js v6** untuk Solidity dev & deploy.
- **Laravel 12** + **web3p/web3.php** untuk backend.
- **Polygon Amoy testnet** untuk on-chain settlement.
- **Powers of Tau ceremony (Hermez)** untuk Phase 1 SRS pot14.

> Komentar penutup: XevouZK adalah artefak prototipe TA untuk *"Implementasi
> Sistem Pembayaran Digital Menggunakan Zero-Knowledge Proof dan QR Code"*.
> Kontrak live di Polygon Amoy, ZK pathway terbukti end-to-end on-chain, dan
> wallet user sungguh-sungguh non-custodial (server tidak menyimpan kuasa apa
> pun untuk bertindak atas nama user pada mode plain).
