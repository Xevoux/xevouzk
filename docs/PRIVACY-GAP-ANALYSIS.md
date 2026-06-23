# Gap Analysis Privasi — XevouZK

> Tujuan: catat **secara eksplisit** apa yang tersembunyi vs terlihat pada
> setiap layer (client, server, DB, on-chain), agar klaim privasi di laporan
> TA selaras dengan implementasi nyata.

- **Tanggal**: 2026-06-22 (sebelumnya 2026-06-19, 2026-06-13, 2026-06-08, 2026-06-06, 2026-06-05, 2026-05-22)
- **Perubahan 2026-06-22 (backup note + proxy scan)**: (1) ditambah store server
  baru `note_backups` — backup note terenkripsi lintas-device; server menyimpan
  **ciphertext opaque + `ref` hash saja**, tak pernah plaintext/salt/nominal
  (lihat **§3.J** + baris baru di §2). (2) Penemuan dana masuk kini lewat proxy
  server `POST /payment/scan-rpc` (RPC ber-API-key tetap di server; trial-decrypt
  memo tetap di klien) — implikasi metadata di **§3.K**.
- **Koreksi 2026-06-19**: §H diselaraskan — hapus overclaim "unlinkability deposit↔withdraw terjaga oleh skema pool"; XevouZK **tanpa anonymity set** (tanpa Merkle tree), graf commitment dapat ditelusuri on-chain. Lihat PROJECT-UNDERSTANDING §7.8–7.9.
- **Scope**: alur autentikasi (Schnorr) + pembayaran zk-SNARK + QR Code P2P + non-custodial wallet
- **Sumber**: audit kode aktual
- **Perubahan sejak 2026-06-13 (sisi penerima transfer privat + mitigasi M1)**:
  penerima kini mendapat saldo **dan riwayat** transfer masuk. `scanIncomingNotes`
  mencatat `private_receive` ke DB (`receiver_wallet_id`; nominal & pengirim
  **tidak** disimpan — null). **Mitigasi M1 langsung diterapkan**: baris penerima
  **TIDAK menyimpan `polygon_tx_hash`** — kalau disimpan, ia sama dengan baris
  `private_transfer` pengirim sehingga bisa di-`JOIN` → menautkan **S↔R**.
  Idempotensi diganti `receipt_ref` opaque = `sha256(commitment‖salt)` (salt
  rahasia note, tak pernah on-chain) yang **tidak** dikirim sebagai tx_hash dan
  tak bisa direkomputasi pihak luar. Hasil: server **tidak** dapat menautkan
  penerima ke transaksi/pengirim (sisa: hanya korelasi waktu lemah). Lihat **§3.I**.
  Sekaligus klaim "menyembunyikan nominal" **menguat**: nominal transfer privat
  tak pernah ke server (null di DB) — lihat §3.B yang direvisi.
- **Perubahan sejak 2026-06-06 (auth hardening)**: login kini **murni Schnorr** —
  `Auth::attempt` dihapus, password **tidak lagi dikirim** ke server saat login
  maupun register (form tidak punya field `name="password"`). Kolom `users.password`
  diisi **hash acak placeholder** (bukan bcrypt password user) karena tak lagi dipakai
  untuk auth. Ditambah: **single-use replay-nonce** untuk signature + **rate limiting**
  login per (email+IP). Lihat §2 (baris Password), §3.F/§3.G.
- **Perubahan sejak 2026-06-05**: transfer privat kini **self-signed oleh user**
  (commitment pool + memo ECIES via event `EncryptedNote`); jalur mock master-relay
  (`/payment/process`) dihapus. Master wallet = faucet saja, **bukan** gasless
  relayer. Akibatnya `msg.sender` transaksi privat = user — lihat §H & §6.

---

## 1. Ringkasan klaim TA vs realitas

| Klaim TA                                                 | Realitas (kode aktual)                                                |
|----------------------------------------------------------|----------------------------------------------------------------------|
| Verifikasi transaksi tanpa mengungkap **saldo**          | ✅ Saldo aktual sender tetap di client. On-chain hanya Poseidon commitment |
| Verifikasi transaksi tanpa mengungkap **nominal**         | ✅ Jalur PRIVAT: nominal **tak pernah** ke server — `transactions.amount` = NULL untuk `private_transfer`/`private_receive`; on-chain hanya commitment Poseidon. Jalur plain/deposit/withdraw menyimpan amount, tapi nominalnya memang **publik on-chain** (transfer MATIC biasa). Lihat §3.B |
| Verifikasi transaksi tanpa mengungkap **kredensial**      | ✅ Password tidak pernah dikirim. Schnorr signature dari private key yang derive dari password (client-only) |
| Settlement on-chain untuk **transparansi**                | ✅ ZKPayment.privateTransfer dipanggil per transaksi privat; nullifier + commitment terbroadcast |
| **Schnorr** untuk autentikasi                             | ✅ `SchnorrService` secp256k1 + Fiat-Shamir; JS & PHP mengikuti algoritma identik (dipakai di login). Harness vektor byte-identik otomatis belum ada |
| **Groth16** untuk saldo & transaksi privat               | ✅ Circuits + Verifier siap. PHP verify struct-only (pairing didelegasikan on-chain) |
| **Nullifier** mencegah double-spend                       | ⚠️ DB sebagai source of truth utama; mapping `nullifiers` di kontrak sebagai backup canonical (lihat §3.D) |
| **QR hanya untuk alamat / payment request ringkas**       | ✅ Static QR plain JSON (alamat saja). Dynamic QR HMAC-signed + 15 menit expiration |

---

## 2. Matriks visibility per data field

Untuk setiap data sensitif, di mana ia tampak dan dalam bentuk apa.

| Data field          | Client (browser) | Server (Laravel)               | DB MySQL                                | On-chain (Polygon Amoy)                  |
|---------------------|------------------|--------------------------------|-----------------------------------------|------------------------------------------|
| Password user       | ✅ plaintext (input field, transient — hanya untuk derive key di browser) | ✅ **tidak pernah diterima** — form login & register tidak mengirim password; server hanya verifikasi signature Schnorr | ❌ `users.password` = **hash acak placeholder** (`Hash::make(Str::random)`), bukan hash password user; tak dipakai untuk auth | ❌ tidak pernah |
| Schnorr private key | ✅ derived deterministik per session (label `schnorr_v1:`), tidak dipersist | ❌ tidak pernah | ❌ tidak pernah | ❌ tidak pernah |
| Schnorr public key  | ✅ derived saat register, dikirim | ✅ disimpan `users.zk_public_key` | ✅ cleartext compressed point hex | ❌ tidak pernah (auth = server-side) |
| **Polygon private key** | ✅ derived deterministik per session (label `polygon_v1:`), tidak dipersist | ❌ **tidak pernah** | ❌ **tidak pernah** — tidak ada kolom `encrypted_private_key` | ❌ tidak pernah (signing di browser) |
| **Polygon public key + address** | ✅ derived saat register, dikirim | ✅ disimpan `wallets.public_key` + `wallets.polygon_address` | ✅ cleartext | ✅ public oleh natur (address on-chain) |
| Saldo Polygon nyata | ✅ ada saat generate proof | ✅ via PolygonService::syncWalletBalance | ✅ `wallets.balance` (DB shadow, di-sync dari blockchain) | ✅ `address.balance` di Amoy (PUBLIK by nature) |
| Nominal transfer — **privat/pool** | ✅ user input | ❌ **tidak dikirim** — `recordEvent` privat (kirim & terima) hanya kirim `receipt_ref` opaque + `type`; sejak Tingkat 1, `tx_hash` **tak** dikirim | ✅ **NULL** — tak disimpan untuk `private_transfer`/`private_receive` | ❌ tidak di-broadcast (hanya commitment Poseidon) |
| Nominal transfer — **plain/deposit/withdraw** | ✅ user input | ✅ `Request::amount` | ✅ `transactions.amount` cleartext | ✅ **publik on-chain** (value MATIC tx) — DB tidak bocorkan apa pun yang baru |
| Recipient — **plain** (internal) | ✅ user input | ✅ lookup `wallets.wallet_address` | ✅ `transactions.receiver_wallet_id` (FK) | ⚠️ `to` field bila plain MATIC |
| Recipient — **privat** | ✅ user input (viewing key) | ✅ baris `private_receive` simpan `receiver_wallet_id=R` saja; **tx_hash tidak dikirim** (M1) | ✅ `receiver_wallet_id=R` **tanpa** `polygon_tx_hash` → **tak joinable** ke baris pengirim (S↔R terputus; sisa korelasi waktu lemah). Lihat §3.I | ❌ tidak (hanya `recipientCommitment` Poseidon) |
| Recipient polygon address | ✅ saat scan QR static | ✅ via Wallet model | ✅ `wallets.polygon_address` | ⚠️ kalau plain MATIC transfer (non-ZK) terbroadcast sebagai `to` field |
| Nullifier (transaksi) | ✅ dihitung Poseidon(secret, commitment) | ✅ di-extract dari proof | ✅ `zk_proofs.nullifier` cleartext | ✅ `nullifiers[nullifier]=true` di ZKPayment.sol |
| Sender commitment   | ✅ Poseidon hash | ✅ extracted from proof | ✅ `zk_proofs.public_inputs.senderCommitment` | ✅ pubSignals `privateTransfer` (Poseidon hash, di-burn) |
| New self (change) commitment | ✅ Poseidon hash | ✅ extracted | ✅ `transactions.zk_public_inputs` JSON | ✅ pubSignals `privateTransfer` (Poseidon hash) |
| Recipient commitment | ✅ Poseidon(amount, recipientShieldPub, salt) | ✅ extracted | ✅ same as above | ✅ pubSignals + di-index di event `EncryptedNote` (hash) |
| Memo note penerima (ECIES) | ✅ plaintext sebelum encrypt | ❌ tidak (di-encrypt di client) | ❌ tidak | ✅ `EncryptedNote.memo` — **terenkripsi** (hanya penerima bisa baca) |
| Note backup (ciphertext) | ✅ plaintext note sebelum encrypt | ❌ **hanya ciphertext AES-GCM** (tak bisa decrypt — kunci PBKDF2 dari password) | ✅ `note_backups.ciphertext` + `ref` opaque + `user_id` (server tahu *jumlah* note & waktu, bukan isi) | ❌ tidak (off-chain) |
| Note backup `ref`  | ✅ dihitung `sha256(prefix+commitment+salt)` | ⚠️ diterima sebagai indeks idempotensi | ✅ `note_backups.ref` (opaque — salt rahasia tak on-chain → tak bisa direkomputasi pihak luar) | ❌ tidak |
| QR static payload   | ✅ plain JSON (alamat) | ✅ generated server-side | ❌ tidak dipersist (no DB) | ❌ tidak |
| QR dynamic payload  | ✅ encrypted bytes (Crypt::encryptString APP_KEY) | ✅ decrypt + verify signature server-side | ✅ `qr_codes.qr_data` (JSON cleartext untuk audit), `qr_codes.signature` (HMAC) | ❌ tidak |

**Legend**: ✅ visible/handled, ❌ not present, ⚠️ partial / needs attention.

---

## 3. Gap & rekomendasi

### A. Saldo aktual masih cleartext di `wallets.balance`

- **Status**: ⚠️ Trade-off konsisten. `wallets.balance` adalah DB shadow yang
  di-sync dari `address.balance` Polygon (yang memang publik). Tidak melanggar
  klaim privasi terhadap **pihak eksternal**, tapi DB-internal melihat saldo.
- **Catatan TA**: bila TA hanya klaim "tersembunyi di on-chain ZK pathway", aman.
  Bila klaim "tersembunyi sama sekali di sistem", ini overclaim — perlu kalimat
  hati-hati di laporan.
- **Rekomendasi**: jangan klaim "saldo enkripsi end-to-end". Klaim yang akurat:
  "saldo tidak terungkap **dalam proof on-chain** maupun bagi pihak ketiga yang
  hanya melihat blockchain explorer". DB shadow adalah optimisasi UX yang server
  butuhkan untuk render dashboard cepat.

### B. Nominal transaksi — privat NULL di DB, plain publik on-chain (REVISI 2026-06-13)

- **Status**: ✅ **Lebih baik dari catatan lama.** Setelah audit ulang:
  - **Jalur PRIVAT** (`private_transfer`/`private_receive`): `transactions.amount`
    = **NULL**. Nominal tak pernah dikirim ke server (`recordEvent` privat hanya
    mengirim `tx_hash`+`type`, lihat [record-event.js](../resources/js/record-event.js)
    dan `recordPoolEvent` di [PaymentController](../app/Http/Controllers/PaymentController.php)).
    On-chain hanya commitment Poseidon. → klaim "menyembunyikan nominal" berlaku
    **end-to-end** untuk jalur privat (client → tidak ke server → null di DB → commitment on-chain).
  - **Jalur plain/deposit/withdraw**: `transactions.amount` disimpan cleartext,
    **tetapi** nominalnya memang **publik on-chain** (deposit/withdraw = value MATIC
    ke/dari kontrak; plain = transfer MATIC biasa). DB tidak membocorkan apa pun
    yang tidak sudah terlihat di explorer.
- **Implikasi**: tidak ada transaksi yang nominalnya disembunyikan on-chain tapi
  bocor cleartext di DB. Catatan §3.B lama (semua nominal cleartext di DB)
  **sudah tidak akurat** sejak `amount` di-null-kan untuk jalur privat.
- **Rekomendasi**: klaim TA yang akurat — "Nominal transaksi privat tidak terlihat
  di explorer **maupun** di basis data server (disimpan NULL); hanya transaksi
  publik (plain/deposit/withdraw) menyimpan nominal, yang toh sudah publik on-chain."

### C. Pemisahan jalur plain vs privat (per 2026-06-06)

- **Status**: ✅ Dipisah secara struktural, bukan lagi checkbox satu form.
- **Jalur plain** ([form.blade.php](../resources/views/payment/form.blade.php) →
  `/payment/relay`): user sign transfer MATIC biasa di browser. Recipient address
  + amount **terbroadcast cleartext** ke Amoy. Mode ini ada di tab **Plain (Publik)**
  pada form Transfer Manual + `alert alert-warning` yang menjelaskan alamat &
  nominal terlihat di explorer.
- **Jalur privat** (Scan QR Privat di `/payment/scan`, **atau** tab **Privat (Pool)**
  pada form Transfer Manual `/payment` — keduanya via `sendPrivate`
  [private-send.js](../resources/js/private-send.js) → `transferFromPool`
  [polygon-transfer.js](../resources/js/polygon-transfer.js)): user sign
  `ZKPayment.privateTransfer(...)` di browser. Recipient hanya muncul sebagai
  `recipientCommitment` (Poseidon) + memo ECIES via event `EncryptedNote`. Catatan:
  transfer privat butuh **viewing key** penerima (zkpub), jadi tidak bisa ke alamat 0x.
- **Catatan**: keduanya **self-signed** (`msg.sender = user`). Tidak ada lagi
  `processPlainPayment`/`processZkPayment` server-side maupun checkbox mode —
  pemisahan kini lewat halaman/entry-point berbeda. Gap awareness tertutup karena
  form plain tidak bisa "tidak sengaja" dipakai untuk transaksi privat.

### D. Nullifier source of truth: DB-primary

- **Status**: ⚠️ Aktif by-design. `ZKPayment.sol` punya `nullifiers` mapping,
  tapi DB Laravel adalah source of truth utama untuk cek cepat di alur server.
- **Implikasi**: Server XevouZK adalah trusted party untuk anti-double-spend.
  Bila server compromised, attacker bisa pakai ulang nullifier (skip cek DB).
  On-chain check tetap ada di contract (`require(!nullifiers[n])`),
  tapi karena setiap tx tetap memanggil contract, nullifier tersimpan otomatis.
  Yang TIDAK ada: server cek contract `nullifiers[n]` sebelum memutuskan tolak.
- **Rekomendasi**: Untuk TA, eksplisit di laporan: "nullifier disimpan di DB
  internal Laravel sebagai cek cepat; **contract** mempunyai mapping nullifier
  sebagai backup canonical. Belum ada sinkronisasi 2-arah otomatis." Untuk
  trust-less property penuh, server bisa query contract `isNullifierUsed(n)`
  sebelum insert ke DB.

### E. QR dynamic: `qr_codes.qr_data` JSON cleartext di DB

- **Status**: ⚠️ Field `qr_data` dicast `array` JSON tanpa enkripsi tambahan
  (lihat [QRCode model](app/Models/QRCode.php)). Bila DB leaked, attacker tahu
  payment requests historis (sender wallet sudah tahu via blockchain explorer).
- **Mengapa cleartext di DB**: server butuh `qr_data` untuk verifySignature saat
  scan; signature itself sudah HMAC pakai APP_KEY.
- **Rekomendasi**: low priority. APP_KEY rotation + DB access control sudah
  mitigasi cukup. Bila TA klaim "QR data terenkripsi end-to-end", encrypt
  `qr_data` JSON di model `set` mutator. Sederhana, tapi tidak dilakukan
  default.

### F. Anti-replay & rate limiting login

- **Status**: ✅ Good. Tiga lapis pertahanan pada login Schnorr:
  1. **CSRF binding** — message yang ditandatangani =
     `lower(email) || "|" || timestamp || "|" || csrf_token` → signature mengikat
     sesi server, tahan replay antar-sesi.
  2. **Timestamp window** — `abs(now - ts) ≤ 300s` ([AuthController](../app/Http/Controllers/AuthController.php)),
     tolak signature kedaluwarsa.
  3. **Single-use nonce** — setelah verify sukses, hash signature disimpan di
     cache (`schnorr_nonce:{user}:{sha256(sig)}`, TTL = window) via `Cache::add`
     (put-if-absent atomik). Signature yang sama tak bisa dipakai dua kali dalam
     window (tutup celah double-submit intra-sesi).
- **Rate limiting**: `RateLimiter` per (email+IP), maks 5 percobaan gagal sebelum
  diblokir sementara; plus `throttle:10,1` per-IP di route. Mencegah brute-force
  /credential-stuffing.
- **Catatan**: kalau session rotate CSRF (Laravel default tidak per-request),
  signature stale.

### G. `users.password` = placeholder, Schnorr public key publik di DB

- **Status**: ✅ Akurat. Login non-custodial tidak memakai password: `users.password`
  diisi **hash acak** (`Hash::make(Str::random(40))`) yang tak akan pernah dicocokkan,
  sekadar mengisi kolom NOT NULL. `users.zk_public_key` compressed point (public,
  OK to expose). Server tidak menyimpan material derivasi private key apa pun, dan
  tidak pernah menerima password (lihat §2 baris Password).

---

### H. Sender transaksi privat = user (self-signed), `msg.sender` terlihat

- **Status**: ⚠️ Bukan gap custody, tapi **batas privasi yang harus diakui jujur**.
- **Mekanisme aktual** ([polygon-transfer.js](../resources/js/polygon-transfer.js)):
  `privateTransfer` & `withdraw` **ditandatangani dan dibayar gas oleh user
  sendiri** di browser (`deriveWallet` → `signTransaction` → `/payment/relay`).
  Master wallet **tidak** terlibat — perannya hanya faucet. Tidak ada gasless
  relayer (jalur mock master-relay `/payment/process` sudah dihapus).
- **Implikasi privasi**: `msg.sender = alamat user`. Pengamat blockchain bisa
  melihat *bahwa* alamat X melakukan `privateTransfer`, **tetapi tidak** nominal
  maupun penerimanya (hanya commitment Poseidon + memo ECIES).
- **Batas unlinkability (jujur)**: XevouZK memakai pola commitment-pool, **tetapi
  tanpa Merkle tree / anonymity set** seperti Tornado Cash. `senderCommitment`
  (commitment yang di-burn) tampil **eksplisit** sebagai public signal & di event
  `PrivateTransfer` di **setiap** hop, sehingga graf commitment (old→new) dapat
  ditelusuri on-chain. Maka **jangan klaim** "deposit↔withdraw unlinkable" sekuat
  Tornado — yang tersembunyi adalah **nominal** dan **identitas penerima**, bukan
  keberadaan tautan antar-commitment. Anonymity set adalah *future work* (lihat
  PROJECT-UNDERSTANDING §7.8–7.9).
- **Klaim TA yang akurat**: "Transfer privat memakai commitment pool: nominal,
  saldo, dan identitas penerima tidak terlihat di explorer. Karena tx di-sign user
  sendiri, alamat pengirim (sebagai `msg.sender`) terlihat — yang disembunyikan
  adalah isi & tujuan transaksi, bukan fakta bahwa user bertransaksi. Sistem belum
  menyediakan anonymity set, jadi graf commitment bersifat publik."
- **Klaim yang harus dihindari**: ❌ "Identitas sender transaksi privat tersembunyi"
  (`msg.sender = user`); ❌ "deposit↔withdraw unlinkable / seanonim Tornado Cash"
  (tidak ada anonymity set — graf commitment dapat ditelusuri).
- **Future work (opsional)**: tambahkan gasless relayer agar `msg.sender` ≠ user.
  Kontrak `privateTransfer` tidak mengecek `msg.sender`, jadi ini perubahan sisi
  client tanpa redeploy.

---

### I. Riwayat sisi penerima transfer privat — unlinkable (M1 diterapkan 2026-06-13)

- **Status**: ✅ **Dimitigasi.** Risiko link S↔R diidentifikasi lalu **ditutup
  langsung** dengan M1 — penerima tetap dapat saldo + riwayat (sinkron lintas
  device), tapi server tak bisa menautkannya ke pengirim/transaksi.
- **Risiko awal (sebelum M1)**: agar penerima melihat dana + riwayat, `scanIncomingNotes`
  mencatat `private_receive`. Bila baris itu menyimpan `polygon_tx_hash` yang **sama**
  dengan baris `private_transfer` pengirim, pihak ber-akses DB bisa
  `JOIN ... ON polygon_tx_hash` → menautkan **pengirim S ↔ penerima R**. (On-chain
  tetap aman: penerima hanya `recipientCommitment` Poseidon.)
- **M1 — yang diterapkan** ([PaymentController::recordPoolEvent](../app/Http/Controllers/PaymentController.php),
  [record-event.js](../resources/js/record-event.js), [polygon-transfer.js](../resources/js/polygon-transfer.js)):
  1. Baris `private_receive` **tidak menyimpan `polygon_tx_hash`** (NULL). Tidak ada
     kolom yang sama dengan baris pengirim → `JOIN` mustahil.
  2. Client **tidak mengirim** tx_hash untuk receive. Idempotensi pakai
     `receipt_ref = sha256("xevou-receive-v1:" + recipientCommitment + ":" + salt)`.
     `salt` rahasia (hanya di memo ECIES, **tak pernah on-chain cleartext**), jadi
     receipt_ref **tak bisa direkomputasi** oleh pihak yang hanya punya DB + chain,
     dan tak menautkan ke tx mana pun. Deterministik per note → tetap idempoten.
- **Sisa risiko (residual)**: korelasi **waktu** lemah — `created_at` baris penerima
  (saat scan) vs baris pengirim (saat kirim). Biasanya berjauhan (penerima scan
  belakangan) sehingga bukan tautan pasti; bukan join kriptografis.
- **Klaim TA yang kini akurat**: "Identitas penerima transfer privat tidak terlihat
  di explorer publik **maupun** dapat ditautkan ke pengirim oleh server: baris
  riwayat penerima tidak menyimpan hash transaksi, dan kunci idempotensinya
  diturunkan dari rahasia note yang tak pernah on-chain."
- **Catatan UX**: konsekuensi M1, baris riwayat penerima **tidak punya tombol "view"
  ke explorer** (server memang tak tahu tx-nya). Penerima masih bisa melihat tx di
  note lokalnya bila perlu.

---

### J. Backup note lintas-device — server simpan ciphertext opaque (2026-06-22)

- **Status**: ✅ **Zero-knowledge terhadap server** — store baru, tapi dirancang agar
  server tak menambah pengetahuan tentang isi note.
- **Apa yang baru**: tabel `note_backups` (`user_id`, `ref`, `ciphertext`). Browser
  mem-push **ciphertext AES-GCM yang identik dengan blob localStorage** (kunci =
  PBKDF2 dari `password+email`, tak pernah ke server) lewat `POST /notes/backup`,
  ditarik kembali via `GET /notes/backup` saat login lalu di-decrypt **di klien**
  ([note-backup.js](../resources/js/note-backup.js),
  [NoteBackupController](../app/Http/Controllers/NoteBackupController.php)).
- **Yang server LIHAT**: ciphertext (tak bisa decrypt), `ref` opaque
  (`sha256("xevou-note-backup-v1:" + commitment + ":" + salt)` — `salt` rahasia note,
  **tak pernah on-chain cleartext**, jadi `ref` tak bisa direkomputasi/ditautkan ke
  commitment on-chain oleh pihak yang hanya punya DB + chain), `user_id`, dan
  `created_at/updated_at`.
- **Yang server TIDAK LIHAT**: plaintext note, `amount`, `salt`, `commitment` mentah.
- **Residual (metadata, jujur)**: server tahu **berapa banyak** note yang di-backup
  seorang user dan **kapan** (timestamps) — bukan nilai/penerimanya. Ini metadata
  count/timing, bukan kebocoran isi. Validasi membatasi ukuran (`MAX_CIPHERTEXT=4096`,
  `MAX_BATCH=200`) untuk cegah abuse.
- **Klaim TA yang akurat**: "Backup note bersifat zero-knowledge terhadap server —
  server menyimpan ciphertext + indeks opaque saja, tak pernah isi note; dekripsi
  hanya terjadi di perangkat user dengan kunci turunan password."
- **Klaim yang harus dihindari**: ❌ "Backup membuat wallet recoverable tanpa password"
  — kunci AES turun dari password, lupa password = ciphertext tak bisa dibuka.

### K. Proxy scan RPC — `/payment/scan-rpc` (2026-06-22)

- **Status**: ✅ Netral-privasi; menyimpan kunci API di server, bukan menambah
  eksposur data user yang bermakna.
- **Apa yang baru**: `scanIncomingNotes` tidak lagi memanggil RPC langsung dari
  browser melainkan lewat proxy same-origin `POST /payment/scan-rpc`
  ([PaymentController::scanRpc](../app/Http/Controllers/PaymentController.php)).
  Proxy hanya meneruskan method **read-only ter-whitelist** (`eth_getLogs`,
  `eth_call`, `eth_blockNumber`, dst.) ke upstream ber-API-key
  (`POLYGON_SCAN_RPC_URL`). URL berisi API key **tak pernah** dikirim ke peramban.
- **Implikasi privasi**: kueri `eth_getLogs` menyasar event `EncryptedNote` kontrak
  (publik on-chain), bukan data pribadi user; **trial-decrypt memo tetap 100% di
  klien**. Yang berubah: server (dan upstream RPC) kini melihat bahwa *user-yang-login
  sedang men-scan* (IP + waktu), korelasi lemah yang sudah inheren pada arsitektur
  klien-server. Tidak ada plaintext note yang melewati server.
- **Catatan keamanan**: route ini di-**CSRF-exempt** ([bootstrap/app.php](../bootstrap/app.php))
  karena dipanggil `ethers` `JsonRpcProvider` (tak kirim token CSRF), tetapi tetap
  di belakang middleware `auth` (butuh sesi) dan whitelist method read-only — bukan
  relay tulis umum (menulis tx tetap lewat `/payment/relay`).

---

### L. Korelasi nominal — tanpa denominasi tetap (diakui sebagai batasan)

- **Status**: ⚠️ **Batas privasi yang diakui jujur** (bukan gap custody). Komplemen
  §3.H (graf commitment) pada sumbu berbeda: **nilai nominal**.
- **Mekanisme aktual**: XevouZK **tidak** memakai **denominasi tetap**. Deposit
  menerima nominal bebas (`msg.value` **publik** on-chain), withdraw mengeluarkan
  nominal pasti (**publik** on-chain), dan `privateTransfer` memecah note jadi note
  penerima (`transferAmount`) + note kembalian (sisa) — keduanya nominal **sembarang**
  (tersembunyi sebagai commitment Poseidon, tetapi nilainya unik).
- **Implikasi (korelasi nominal)**: meski `privateTransfer` tak menampilkan nominal
  on-chain (value = 0, hanya commitment), nominal pada **titik ujung publik** (deposit
  & withdraw) dapat **dikorelasikan**. Contoh: deposit `0.0137` lalu suatu saat
  withdraw `0.0137` → nilai unik bertindak sebagai **sidik jari** yang menautkan kedua
  ujung, walau commitment di antaranya tak di-reveal nilainya. Anonymity set yang kecil
  + nominal sembarang memperkuat korelasi ini.
- **Mitigasi yang dievaluasi (1d, 2026-06-23) — ditunda**: membatasi nominal ke
  **pecahan baku** (pola Tornado / e-cash). Hanya model **whole-note** (kirim satu note
  utuh, kembalian = 0, deposit pecahan baku) yang memberi denominasi seragam sungguhan;
  itu **mengubah UX inti** (pilih "lembar", nominal non-baku butuh > 1 tx) → **ditunda
  sebagai future work**. Versi ringan (denominasi deposit saja / kembalian bebas) nyaris
  **kosmetik** karena kembalian & transfer sembarang memunculkan kembali korelasi.
- **Klaim TA yang akurat**: "Nominal transaksi privat tidak ditampilkan on-chain (hanya
  commitment), tetapi karena tidak ada denominasi tetap maupun anonymity set, nominal
  pada titik ujung publik (deposit/withdraw) **dapat dikorelasikan**. Menyembunyikan
  korelasi nominal adalah *future work* (denominasi tetap dan/atau anonymity set)."
- **Klaim yang harus dihindari**: ❌ "Nominal sepenuhnya tak dapat ditelusuri /
  deposit↔withdraw tak dapat dikorelasikan lewat nominal."

---

## 4. Mitigasi follow-up (kalau klaim TA mau diperketat)

| # | Gap | Action | Effort |
|---|-----|--------|--------|
| 1 | ~~§3.C — UI tidak warning non-ZK transfer~~ | ✅ **SELESAI** — tab **Plain** berlabel publik + banner `alert-warning`; transfer privat punya tab **Privat** sendiri (tempel viewing key) dan tetap bisa via Scan QR | — |
| 2 | §3.A/B — klaim TA potensi overclaim | Pastikan teks laporan match realitas DB shadow | S (15 menit) |
| 3 | §3.D — nullifier double-check contract | Server query `isNullifierUsed` sebelum DB insert untuk trust-less penuh | M (1–2 jam) |
| 4 | §3.E — qr_data encryption at-rest | Mutator encrypt di QRCode model | S (30 menit) |
| 5 | ~~§3.I — DB link S↔R via `polygon_tx_hash` pada `private_receive`~~ | ✅ **SELESAI (M1)** — baris penerima tak simpan tx_hash; idempotensi `receipt_ref` opaque dari salt rahasia | — |
| 6 | §3.L — korelasi nominal (tanpa denominasi tetap) | **Denominasi tetap** model whole-note/e-cash (deposit pecahan baku, transfer note utuh) — **dievaluasi 2026-06-23, ditunda** (ubah UX inti); alternatif kuat = **anonymity set Merkle** (§3.H). Versi ringan kosmetik. | L (future work) |

#1 dan #2 selesaikan overclaim risk dengan cepat. #3 dan #4 bisa
didokumentasikan sebagai future work, bukan blocker TA. #5 sudah ditutup — klaim
"server tak bisa menautkan penerima ke pengirim" kini **boleh** dipakai (lihat §3.I).

---

## 5. Hal-hal yang **sudah benar** (jangan ubah)

- Schnorr private key tidak pernah keluar dari client.
- Password tidak pernah ditransmit (Schnorr replacement untuk login).
- Poseidon commitment untuk balance/transfer (matching circuit).
- Nullifier per-transaction (Poseidon(secret, commitment)).
- Settlement on-chain via `ZKPayment.privateTransfer` untuk ZK path.
- QR static = address only (no signature needed); QR dynamic = HMAC + Crypt.
- Polygon private key user tidak pernah ada di server. Kolom
  `encrypted_private_key` tidak ada di tabel `wallets`. Tx outbound user
  di-sign di browser dengan `ethers.js v6`.
- Smart contract correctness ter-verifikasi via 34 Hardhat behavioral test +
  lifecycle on-chain real proof di Amoy (lihat [PROJECT-STATUS §1](PROJECT-STATUS.md)).

---

## 6. Kesimpulan untuk laporan TA

Klaim yang **akurat** untuk laporan:

> XevouZK adalah sistem pembayaran P2P **non-custodial** yang mengintegrasikan
> **Schnorr (secp256k1, Fiat-Shamir)** untuk autentikasi tanpa mengirim
> password, dan **Groth16 zk-SNARK** untuk pembayaran privat di mana nominal
> transaksi, saldo sender, dan identitas penerima tidak terlihat di Polygon
> blockchain explorer publik. **Private key Polygon user di-derive
> deterministik di browser dari password — server tidak menyimpan kuasa untuk
> mengirim atas nama user pada semua mode (plain, deposit, privateTransfer,
> withdraw di-sign user; server hanya relay raw tx).** Transfer privat memakai
> commitment pool (pola commitment + nullifier; **tanpa anonymity set/Merkle tree**
> seperti Tornado): nominal, saldo, dan identitas penerima tidak
> terlihat di explorer (commitment Poseidon + memo ECIES via event
> `EncryptedNote`) — namun graf commitment tetap publik (lihat §H). Karena tx
> di-sign user, `msg.sender` transaksi privat =
> alamat user — yang tersembunyi adalah isi & tujuan, bukan fakta bertransaksi.
> Server XevouZK menyimpan ledger operasional internal (nominal **hanya untuk
> transaksi publik** — plain/deposit/withdraw; nominal transfer privat = NULL,
> FK wallet, dan **sisi penerima transfer privat**) untuk kebutuhan UX history
> dan audit. Sisi penerima transfer privat dicatat **tanpa** hash transaksi
> (idempotensi via `receipt_ref` opaque dari rahasia note), sehingga server
> **tidak dapat menautkan** pasangan pengirim–penerima dari basis datanya (lihat
> §3.I; sisa hanya korelasi waktu lemah). Anti-double-spend menggunakan nullifier
> Poseidon yang dicatat di database internal dan di contract `ZKPayment` sebagai backup.

Klaim yang **harus dihindari**:

- "Saldo terenkripsi end-to-end" (saldo di-shadow di DB cleartext untuk UX).
- "Nominal transaksi tersembunyi total" (DB internal menyimpan cleartext).
- "Trustless / tidak butuh percaya server" (nullifier source of truth = DB,
  server bisa skip cek).
- "Fully on-chain settlement" (path plain transfer tetap ada untuk fallback).
- "Nominal transaksi sepenuhnya tak dapat ditelusuri / deposit↔withdraw tak dapat
  dikorelasikan lewat nominal" — tanpa denominasi tetap & anonymity set, nominal di
  titik ujung publik (deposit/withdraw) bisa menjadi sidik jari yang menautkan ujung
  (§3.L). 1d (denominasi) dievaluasi 2026-06-23 lalu ditunda.
- "Identitas sender transaksi privat tersembunyi" — `msg.sender = user` karena
  tx di-sign sendiri; yang tersembunyi nominal & penerima **di explorer publik**,
  bukan fakta bertransaksi. (Gasless relayer untuk menyembunyikan sender = future work.)
- "Server sama sekali tidak tahu seorang user pernah **menerima** transfer privat"
  — masih ❌: baris `private_receive` mencatat `receiver_wallet_id=R` (fakta bahwa R
  menerima sesuatu). Yang **kini boleh** diklaim (setelah M1, §3.I): "server tidak
  dapat **menautkan** penerima ke pengirim/transaksi" — baris penerima tak menyimpan
  hash tx dan kunci idempotensinya turunan rahasia note, jadi `JOIN` ke baris
  pengirim mustahil (sisa hanya korelasi waktu lemah).
- "Wallet bisa di-recover tanpa password" — saat ini lupa password = wallet
  hilang permanen. Backup note lintas-device (§3.J) menyelamatkan note dari
  ganti/hilang **device**, **bukan** dari lupa password (kunci AES turun dari
  password). Recovery flow (BIP-39 mnemonic) belum diimplementasikan.

Klaim **akurat** lebih kuat untuk pembelaan dibanding klaim **overstated** yang
bisa dipatahkan oleh penguji TA dengan satu pertanyaan teknis.
