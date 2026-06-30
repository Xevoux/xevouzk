# Pengujian & Evaluasi — XevouZK

> **Tujuan dokumen**: panduan + **template hasil** untuk bab *Pengujian* TA. Berisi
> 7 jenis pengujian, dipetakan ke **klaim yang divalidasi**, lengkap dengan
> command, metrik, **template tabel hasil**, dan cara **capture bukti**.
> **Mulai cepat**: lihat **[§10](#10-bukti-yang-wajib-masuk-ke-ta-kit-artefak--cara-dapat)**
> untuk checklist artefak bukti yang ditempel ke laporan + perintah pembangkitnya.
>
> **Hubungan dengan dokumen lain** (jangan duplikasi — dokumen ini mengorganisir,
> yang lain memuat detail):
> - [TESTING-EVIDENCE.md](TESTING-EVIDENCE.md) — runbook command mentah + gas detail.
> - [PRIVACY-GAP-ANALYSIS.md](PRIVACY-GAP-ANALYSIS.md) — klaim privasi per data field.
> - [ZK-SNARK-DOCUMENTATION.md](ZK-SNARK-DOCUMENTATION.md) §9 — threat model.
> - [PROJECT-STATUS.md](PROJECT-STATUS.md) §7 — demo script TA.
>
> **Snapshot**: 2026-06-30. Runtime lokal via Laravel Herd; shell contoh **PowerShell**.

---

## 0. Ringkasan: 7 pengujian → kategori → klaim

Urutan penomoran boleh diatur ulang sesuai aturan skripsi. Beberapa pengujian
**berbagi data** tapi **mengukur hal berbeda** (lihat catatan overlap di bawah).

| # | Pengujian | Kategori | Klaim yang diuji | Bukti utama |
|---|---|---|---|---|
| 1 | Perbandingan Plain vs Privat | **Privasi** | Nominal, saldo, & identitas penerima tersembunyi di explorer pada mode privat | Capture PolygonScan (plain vs privat) + tabel field |
| 2 | Waktu pembangkitan proof | **Performa** | Proof Groth16 praktis dibuat di sisi klien | Log `proof generated in <ms>ms` (×N → rata-rata) |
| 3 | Biaya gas on-chain | **Performa / biaya** | Verifikasi ZK on-chain feasible & biayanya terukur | Tabel gas-reporter + receipt PolygonScan |
| 4 | Hardhat test | **Keamanan / correctness kontrak** | Anti double-spend, kontrol akses, invariant pool | `34 passing` |
| 5 | Completeness & soundness ZK | **Keamanan ZK** | Proof valid diterima; proof palsu/curang ditolak | Negative case `test-proofs.js` |
| 6 | Verifikasi by inspection | **Privasi / non-custodial** | Password & private key tak pernah terkirim/disimpan | DevTools Network + inspeksi DB |
| 7 | Keamanan autentikasi Schnorr | **Keamanan auth** | Login sah diterima (klien≡server) + replay/sig-palsu/timestamp/brute-force ditolak | Skrip live reproducible (`5/5 passed`) |

### Catatan overlap (penting — menjawab "apakah #1 = #3?")

- **#1 vs #3 berbagi DATA, beda METRIK.** Boleh memakai **set transaksi yang sama**
  (deposit → privateTransfer → withdraw), tetapi:
  - **#1** menjawab *"apa yang TERLIHAT?"* → privasi (field nominal/penerima).
  - **#3** menjawab *"berapa BIAYANYA?"* → performa (gas used + fee).
  Jadi satu set tx hash bisa menghasilkan **dua tabel berbeda**. Boleh digabung jadi
  satu sub-bab atau dipisah — terserah aturan skripsi.
- **#4 vs #5 saling melengkapi, bukan sama.** #4 menguji **kontrak** (Solidity/EVM:
  apakah kontrak menolak nullifier dobel, pemanggil non-owner, dst). #5 menguji
  **circuit/proof** (snarkjs: apakah proof palsu/witness curang ditolak di level
  kriptografi). Keduanya menutup lapis yang berbeda.
- **#1 dan #6 sama-sama "by inspection"** tapi sasaran beda: #1 = privasi *on-chain*
  (PolygonScan), #6 = non-custodial *di sisi klien/server* (Network tab + DB).

---

## 1. Perbandingan Plain vs Privat (privasi)

**Tujuan**: membuktikan klaim inti TA — pada mode privat, nominal & identitas
penerima **tidak terlihat** di blockchain explorer publik, sementara mode plain
transparan.

**Klaim yang diuji**: lihat [PRIVACY-GAP-ANALYSIS §1–2](PRIVACY-GAP-ANALYSIS.md).

**Bahan** (kamu sudah punya): tx hash + capture PolygonScan untuk (a) satu transfer
**plain** dan (b) satu **privateTransfer** (idealnya juga deposit & withdraw).

**Template tabel hasil** — bandingkan field per transaksi:

| Field | Plain transfer | privateTransfer (privat) |
|---|---|---|
| Method | `transfer` (MATIC) | `privateTransfer(...)` |
| `to` / penerima | ✅ alamat terlihat | ❌ hanya `recipientCommitment` (Poseidon) |
| Nominal (value) | ✅ terlihat | ❌ tidak ada (0 value; hanya commitment) |
| Saldo pengirim | ✅ dapat ditelusuri | ❌ tidak terungkap |
| `from` / `msg.sender` | ✅ alamat user | ⚠️ **alamat user** (tx di-sign sendiri) |
| Isi event | transfer biasa | `EncryptedNote` (memo terenkripsi) |

**Cara capture bukti**:
1. Buka `https://amoy.polygonscan.com/tx/<hash>` untuk tiap tx → screenshot tab
   **Overview** + tab **Logs**.
2. Untuk privateTransfer, tunjukkan input berupa 4 angka hash Poseidon
   (`senderCommitment`, `nullifier`, `newSelfCommitment`, `recipientCommitment`) —
   **bukan** alamat + nominal.

**Catatan jujur (wajib di laporan)**: yang tersembunyi adalah **nominal & penerima**,
**bukan** fakta bahwa user bertransaksi (`msg.sender` = alamat user, karena belum ada
gasless relayer) dan **bukan** graf commitment (XevouZK tanpa anonymity set/Merkle
tree). Detail: [PRIVACY-GAP §H](PRIVACY-GAP-ANALYSIS.md) & [PROJECT-UNDERSTANDING §7.9](PROJECT-UNDERSTANDING.md).

---

## 2. Waktu pembangkitan proof (performa)

**Tujuan**: mengukur berapa lama klien membuat proof Groth16 per circuit — metrik
performa paling khas untuk sistem ZKP.

**Klaim yang diuji**: proof Groth16 praktis dibuat di perangkat user (succinct prover).

**Command** ([test-proofs.js](../circuits/scripts/test-proofs.js) sudah mencetak ms
per circuit, [baris 38–40](../circuits/scripts/test-proofs.js#L38-L40)):

```powershell
cd circuits
node scripts/test-proofs.js          # 1× run (balance_check, private_transfer, withdraw)
```

**Ukur N kali** lalu ambil rata-rata (≥ 10× agar representatif):

```powershell
cd circuits
1..15 | ForEach-Object { node scripts/test-proofs.js } | Select-String "generated in"
```

**Template tabel hasil** (isi dari N run):

| Circuit | Run 1 (ms) | … | Run N (ms) | Mean (ms) | Median | Stdev |
|---|---|---|---|---|---|---|
| `balance_check` | | | | | | |
| `private_transfer` | | | | | | |
| `withdraw` | | | | | | |

**Metrik pelengkap** (untuk tabel "kompleksitas circuit"):

```powershell
npx snarkjs r1cs info build/private_transfer/private_transfer.r1cs   # jumlah constraint
```

- **Ukuran proof**: ~**192 byte** (konstan, 3 elemen grup) untuk semua circuit —
  pembanding kuat vs zk-STARK (~100 KB). Lihat [ZK-SNARK §4.4 & §5.5](ZK-SNARK-DOCUMENTATION.md).

**Catatan jujur (wajib)**:
- Sebut **spesifikasi mesin** (CPU, RAM), **OS**, dan **runtime** (Node vXX) — waktu
  proof sangat bergantung hardware.
- `test-proofs.js` mengukur di **Node**. Di **browser** (tempat proof sebenarnya
  dibuat saat dipakai) biasanya **lebih lambat** — kalau mau angka browser yang
  otentik, ukur dengan `performance.now()` di sekitar `snarkjs.groth16.fullProve`
  (mis. di [polygon-transfer.js](../resources/js/polygon-transfer.js)) lalu baca di
  Process Logs / DevTools. Sebutkan environment mana yang dipakai.

---

## 3. Biaya gas on-chain (performa / biaya)

**Tujuan**: mengukur biaya komputasi on-chain (gas) tiap operasi — khususnya biaya
verifikasi Groth16.

**Klaim yang diuji**: verifikasi ZK on-chain **feasible** dan biayanya **terukur &
konstan terhadap nominal**.

**Dua sumber angka (sebutkan keduanya, beda makna):**

```powershell
cd contracts
# (a) Gas LOKAL (gas-reporter, EVM Hardhat, MockVerifier tanpa pairing) — reproducible
$env:REPORT_GAS="true"; npx hardhat test

# (b) Gas RIIL on-chain di Amoy (dengan verifikasi Groth16/pairing penuh)
npx hardhat run scripts/test-transfer-e2e.js --network amoy
```

**Angka acuan terukur** (receipt e2e di Amoy, 35 gwei — lihat [TESTING-EVIDENCE §2](TESTING-EVIDENCE.md#2-bukti-gas-riil-on-chain-polygon-amoy)):

| Method | Gas used (real) | Fee @ 35 gwei | Verifikasi Groth16 on-chain? |
|---|---|---|---|
| `deposit` | 74.963 | ~0,0026 MATIC | ❌ tidak (hanya catat commitment) |
| `withdraw` | 427.667 | ~0,0150 MATIC | ✅ ya |
| `privateTransfer` | 478.101 | ~0,0167 MATIC | ✅ ya |
| plain transfer | ~21.000 | ~0,0007 MATIC | — |

**Cara capture bukti**: buka tiap tx hash di PolygonScan → screenshot **Gas Used by
Txn**, **Gas Price**, **Txn Fee**.

**Catatan jujur (wajib)**: asimetri `deposit` (murah) vs `withdraw`/`privateTransfer`
(mahal) = harga **verifikasi pairing EIP-197**, **bukan** komisi protokol; biaya ini
**konstan** terhadap nominal → note sangat kecil tak ekonomis. Penjelasan:
[PROJECT-UNDERSTANDING §10](PROJECT-UNDERSTANDING.md#10-penjelasan-istilah-yang-sering-ditanya).

---

## 4. Hardhat test (keamanan & correctness kontrak)

**Tujuan**: membuktikan smart contract `ZKPayment.sol` berperilaku benar & aman.

**Command**:

```powershell
cd contracts
npx hardhat test                     # target: 34 passing
```

**Pemetaan test → serangan yang dicegah** (untuk tabel TA):

| Serangan / risiko | Test yang membuktikan |
|---|---|
| Double-spend (nullifier reuse) | `reverts when nullifier reused` (transfer & withdraw) |
| Spend note tak valid | `reverts when verifier returns false`, `commitment not active` |
| Akses admin tak sah | `non-owner cannot updateVerifier / emergencyWithdraw` |
| Frontrun withdraw | recipient di-bind di proof → ganti penerima = proof invalid |
| Pencurian dana pool | invariant `pool == totalDeposited − totalWithdrawn` |
| Input jahat | `zero address`, `value == 0`, `commitment == 0` |

**Cara capture bukti**: screenshot ringkasan `34 passing`. Sumber test:
[contracts/test/ZKPayment.test.js](../contracts/test/ZKPayment.test.js).

**(Opsional, berbobot) Static analysis**:

```powershell
pip install slither-analyzer
cd contracts; slither .
```

---

## 5. Completeness & soundness ZK (negative testing)

**Tujuan**: membuktikan dua properti ZKP secara empiris.
- **Completeness**: proof yang **benar** → **diterima**.
- **Soundness**: proof **palsu/curang** → **ditolak**.

**Command** ([test-proofs.js](../circuits/scripts/test-proofs.js) menjalankan keduanya):

```powershell
cd circuits
node scripts/test-proofs.js
```

**Template tabel hasil**:

| Kasus | Circuit | Harapan | Hasil |
|---|---|---|---|
| Proof valid (completeness) | balance_check / private_transfer / withdraw | `proof verified` ✅ | |
| Proof di-tamper 1 byte | semua | `tampered proof rejected` ✅ | |
| `shieldPriv` salah (bukan pemilik) | withdraw | `wrong shieldPriv rejected` ✅ | |
| Saldo < minAmount | balance_check | `insufficient balance rejected` ✅ | |

**Cara capture bukti**: screenshot output yang memuat `proof verified`, `tampered
proof rejected`, `wrong shieldPriv rejected`, `insufficient balance rejected`, dan
`ALL TESTS PASSED`.

---

## 6. Verifikasi by inspection (privasi / non-custodial)

**Tujuan**: membuktikan klaim non-custodial — password & private key **tidak pernah**
dikirim ke / disimpan di server.

**Langkah & cara capture bukti**:

| Yang dibuktikan | Cara | Bukti |
|---|---|---|
| Password tak dikirim saat register/login | DevTools → **Network** → submit form → cek request payload | Screenshot payload: hanya `schnorr_public_key`, `polygon_address`, `polygon_public_key` (+signature saat login); **tidak ada** field `password` |
| Private key tak dikirim | Sda. | Tidak ada private key di payload mana pun |
| Server tak simpan private key | DB: `SELECT * FROM wallets WHERE user_id=?` (tinker/phpMyAdmin) | Kolom `encrypted_private_key` **tidak ada** |
| `users.password` bukan password user | `SELECT password FROM users` | Hash acak placeholder (bukan bcrypt dari password user) |
| Privasi on-chain | PolygonScan privateTransfer | Hanya hash Poseidon (lihat §1) |
| Backup note opaque | `SELECT ref, ciphertext FROM note_backups` | Server hanya simpan ciphertext + ref hash, tak bisa decrypt (lihat [PRIVACY-GAP §3.J](PRIVACY-GAP-ANALYSIS.md)) |
| Server tak terima proof/commitment transfer | DevTools → Network saat transfer/withdraw privat | **Tidak ada** `POST /payment/transfer/verify` atau `/payment/withdraw/verify` — verifikasi proof 100% di klien (`zk-verify.js`) |
| DB tak menautkan pengirim privat ke tx on-chain | `SELECT type, polygon_tx_hash FROM transactions WHERE type='private_transfer'` | `polygon_tx_hash = NULL` (idempotensi via `receipt_ref` opaque turunan salt rahasia) — tak bisa di-JOIN ke blockchain |
| Log server tak menautkan akun ke tx privat | `storage/logs/laravel.log` | "Raw tx relayed" tanpa `user_id`/`tx_hash`; "Pool event recorded" private_transfer ber-`ref: receipt_ref(opaque)` |

Acuan demo lengkap: [PROJECT-STATUS §7 Skenario 0–1](PROJECT-STATUS.md#7-demo-script-untuk-ta-defense).

---

## 7. Keamanan autentikasi Schnorr (reproducible, live)

**Tujuan**: membuktikan login Schnorr **benar & aman** secara end-to-end —
signature sah diterima, dan replay, signature palsu, timestamp basi, serta
brute-force **ditolak**.

**Klaim yang diuji**: [ZK-SNARK §9.3](ZK-SNARK-DOCUMENTATION.md) (baris replay/login) +
[PRIVACY-GAP §3.F](PRIVACY-GAP-ANALYSIS.md).

Satu skrip reproducible (menggantikan uji manual lama; **tidak butuh PHPUnit**).
[scripts/test-schnorr-auth-live.mjs](../scripts/test-schnorr-auth-live.mjs)
menabrak endpoint `/login` **yang sungguhan** memakai modul **klien asli**
([schnorr-auth.js](../resources/js/schnorr-auth.js) + polygon-key.js) untuk
men-derive keypair & menandatangani — persis seperti browser. Mendaftarkan
user uji segar tiap run (email unik → **tak menyentuh akun nyata**). Server
memverifikasi dengan [SchnorrService](../app/Services/SchnorrService.php) di
[AuthController](../app/Http/Controllers/AuthController.php).

| Skenario | Harapan | Mekanisme yang membuktikan |
|---|---|---|
| **A. Login sah** | diterima (→ dashboard) | `verify(pub, msg, sig) == true` — sekaligus bukti **klien≡server**: signature JS diterima `verify()` PHP |
| **B. Replay signature** (request identik dikirim ulang) | ditolak | nonce single-use + window 300 dtk + csrf terikat sesi |
| **C. Signature palsu** (tamper 1 karakter) | ditolak | verifikasi gagal (soundness) |
| **D. Timestamp > 300 dtk** | ditolak | di luar window anti-replay |
| **E. Brute-force** (login gagal berulang) | diblokir di percobaan ke-6 | rate-limit per (email+IP) |

```powershell
# server hidup dulu — Herd: https://xevouzk.test (perlu `herd link xevouzk`)
node scripts/test-schnorr-auth-live.mjs   # target: ALL SCHNORR AUTH LIVE TESTS PASSED (5/5)

# override host bila perlu:
$env:BASE_URL="https://zk_wallet.test"; node scripts/test-schnorr-auth-live.mjs   # link default folder
$env:BASE_URL="http://127.0.0.1:8000";  node scripts/test-schnorr-auth-live.mjs   # php artisan serve
```

**Cara capture bukti**: screenshot output `5/5` + baris "diblokir pada percobaan
ke-6". Status exit: `echo $LASTEXITCODE` → `0`.

**Catatan jujur (wajib)**:
- Skenario **A** sekaligus membuktikan interoperabilitas **klien (JS) ≡ server
  (PHP)** di level yang mengikat (signature JS diverifikasi PHP). Sistem ini
  signing **selalu di klien**; server **hanya verify** ([CLAUDE.md](../CLAUDE.md) §3.2) — jadi
  tak perlu menguji `SchnorrService::sign()` terpisah (tak dipakai di produksi).
- Skrip mematikan verifikasi TLS untuk sertifikat lokal Herd
  (`NODE_TLS_REJECT_UNAUTHORIZED=0`) — **hanya untuk uji lokal**, bukan produksi.
- Tiap run brute-force memakai email throwaway → **tidak** mengunci akun nyata.
  User uji `schnorr-test-<ts>@xevou.test` tertinggal di DB (boleh dibersihkan).
- Replay ditolak oleh **kombinasi** guard (nonce + window + csrf sesi); skrip
  memverifikasi **properti keamanannya** ("request yang sama tak bisa dipakai
  ulang untuk dapat akses"), bukan satu mekanisme tunggal.

---

## 8. Kerangka bab Pengujian (usulan)

Susunan yang mengalir untuk laporan (sesuaikan aturan skripsi):

1. **Lingkungan pengujian** — spesifikasi mesin, OS, Node/PHP, browser, jaringan,
   alamat kontrak Amoy (dari [PROJECT-STATUS §2](PROJECT-STATUS.md)).
2. **Pengujian fungsional** — alur deposit → transfer → withdraw berhasil (smoke test
   browser, [DEPLOY-GUIDE §5](DEPLOY-GUIDE.md)).
3. **Pengujian privasi** — §1 (Plain vs Privat) + §6 (by inspection).
4. **Pengujian performa** — §2 (waktu proof) + §3 (gas).
5. **Pengujian keamanan/correctness** — §4 (Hardhat) + §5 (soundness) + §7 (auth Schnorr live: completeness/interop + replay/sig-palsu/timestamp/brute-force).
6. **Analisis & keterbatasan** — kaitkan ke threat model [ZK-SNARK §9](ZK-SNARK-DOCUMENTATION.md)
   & batas privasi [PRIVACY-GAP](PRIVACY-GAP-ANALYSIS.md).

## 9. Hal yang harus jujur (anti-overclaim) saat menyajikan hasil

- Ini **prototipe TA**, bukan audit keamanan profesional — sebut "pengujian tingkat prototipe".
- **Trusted setup Phase 2 single-party** (risiko toxic waste) — bukan multi-party.
- **Nullifier source-of-truth di DB** (server semi-trusted) — kontrak sebagai backup.
- **Graf commitment dapat ditelusuri** (tanpa anonymity set); `msg.sender` privat = user.
- Pisahkan **gas lokal** (gas-reporter, tanpa pairing) vs **gas riil on-chain** (dengan pairing).
- Sertakan **N pengulangan + mean ± stdev** untuk metrik waktu (bukan satu angka).

Mengakui batasan ini **menguatkan** pembelaan — semua sudah terdokumentasi di
[ZK-SNARK §9.4](ZK-SNARK-DOCUMENTATION.md) & [PRIVACY-GAP §6](PRIVACY-GAP-ANALYSIS.md).

---

## 10. Bukti yang wajib masuk ke TA (kit artefak + cara dapat)

> Bagian ini **mengonsolidasi** semua bukti dari §1–§7 jadi satu checklist
> actionable. Tiap pengujian di atas sudah punya sub-"Cara capture bukti";
> di sini disusun jadi daftar artefak yang benar-benar **ditempel ke laporan**
> beserta perintah pembangkitnya.

**Prinsip bukti TA:**
- **Reproducible** — sertakan perintah persis + lingkungan, supaya penguji bisa
  mengulang dan dapat hasil yang sama.
- **Jujur** — tampilkan apa adanya (termasuk angka yang kurang ideal); kaitkan
  keterbatasan ke §9.
- **Tertaut klaim** — tiap bukti menjawab satu klaim spesifik (kolom "membuktikan").

### 10.1 Daftar artefak (tempel ke bab Pengujian/Lampiran)

| # | Artefak bukti | Dari | Membuktikan | Cara mendapatkan (perintah/langkah) | Bentuk |
|---|---|---|---|---|---|
| 0 | **Tabel lingkungan uji** (CPU, RAM, OS, Node, PHP, browser, alamat kontrak Amoy) | §8.1 | Konteks reproducibility | `node -v`, `php -v`, spesifikasi mesin; alamat kontrak dari [PROJECT-STATUS §2](PROJECT-STATUS.md) | Tabel |
| 1 | **Screenshot alur fungsional** deposit → transfer → withdraw sukses + tx hash | §8.2 | Sistem bekerja end-to-end | Smoke test browser, [DEPLOY-GUIDE §5](DEPLOY-GUIDE.md) | Screenshot |
| 2 | **Capture PolygonScan** plain vs privateTransfer (tab Overview + Logs) | §1 | Nominal & penerima tersembunyi on-chain | `https://amoy.polygonscan.com/tx/<hash>` → screenshot | Screenshot |
| 3 | **Tabel perbandingan field** Plain vs Privat | §1 | Field mana terlihat / tersembunyi | Isi template tabel §1 dari capture #2 | Tabel |
| 4 | **Tabel waktu proof** (N≥10 run, mean/median/stdev) + ukuran proof ~192 B | §2 | Proof Groth16 praktis di klien | `cd circuits; 1..15 \| ForEach-Object { node scripts/test-proofs.js } \| Select-String "generated in"` | Tabel + log |
| 5 | **Tabel gas** (lokal gas-reporter + riil on-chain) + screenshot fee PolygonScan | §3 | Verifikasi ZK on-chain feasible & terukur | `cd contracts; $env:REPORT_GAS="true"; npx hardhat test`; receipt e2e | Tabel + screenshot |
| 6 | **Screenshot `34 passing`** (Hardhat) | §4 | Kontrak benar & aman (anti double-spend, akses) | `cd contracts; npx hardhat test` | Screenshot |
| 7 | **Screenshot output `test-proofs.js`** (`proof verified`, `tampered proof rejected`, …, `ALL TESTS PASSED`) | §5 | Completeness & soundness ZK | `cd circuits; node scripts/test-proofs.js` | Screenshot |
| 8 | **Screenshot DevTools Network** payload register/login (tanpa `password`/private key) | §6 | Non-custodial: rahasia tak terkirim | DevTools → Network → submit form → lihat payload | Screenshot |
| 9 | **Capture query DB** (wallets tanpa `encrypted_private_key`; `users.password` placeholder; `note_backups` ciphertext; `transactions.private_transfer.polygon_tx_hash = NULL`) | §6 | Server tak simpan/menautkan rahasia | `php artisan tinker` / phpMyAdmin → `SELECT ...` (lihat tabel §6) | Screenshot/teks |
| 10 | **Cuplikan `laravel.log`** tanpa tautan akun→tx privat | §6 | Log tak membocorkan linkage | Buka `storage/logs/laravel.log` | Teks |
| 11 | **Screenshot `ALL SCHNORR AUTH LIVE TESTS PASSED (5/5)`** + baris "diblokir pada percobaan ke-6" | §7 | Auth sah diterima; replay/palsu/timestamp/brute-force ditolak | `node scripts/test-schnorr-auth-live.mjs` (server hidup) | Screenshot |

> Opsional berbobot: output **Slither** (§4) dan angka **constraint r1cs** (§2).

### 10.2 Cara mengambil screenshot yang sah (anti-diragukan)

- **Sertakan perintah + timestamp dalam satu frame.** Untuk output terminal,
  screenshot sekaligus baris perintah dan hasilnya; tambahkan exit code:
  ```powershell
  node scripts/test-schnorr-auth-live.mjs; "exit=$LASTEXITCODE"   # exit=0 = lulus
  ```
- **PolygonScan**: pastikan **tx hash + jaringan (Amoy)** ikut terlihat di frame.
- **DevTools**: tab **Network** → pilih request `login`/`register` → panel
  **Payload/Request** terlihat penuh.
- Untuk metrik waktu (§2), simpan **raw log N run** sebagai lampiran, bukan cuma
  tabel ringkas — penguji bisa cek perhitungan mean/stdev.

### 10.3 Saran organisasi berkas bukti

Kumpulkan di satu folder (mis. `bukti-pengujian/`) dengan penamaan jelas, agar
mudah dirujuk dari laporan:

```
bukti-pengujian/
├── 00-lingkungan.png
├── 01-fungsional-deposit-transfer-withdraw.png
├── 02-polygonscan-plain.png / 02-polygonscan-private.png
├── 04-waktu-proof-15run.txt
├── 05-gas-reporter.png / 05-gas-onchain.png
├── 06-hardhat-34passing.png
├── 07-test-proofs-output.png
├── 08-network-login-payload.png
├── 09-db-wallets-users.png
└── 11-schnorr-auth-5of5.png
```

### 10.4 JANGAN dimasukkan ke bukti (keamanan)

- ❌ Isi `.env`, private key, mnemonic, atau `*.ptau`/proving key besar.
- ❌ Screenshot yang memuat password asli atau private key di DevTools/console.
- ❌ Saldo/akun bernilai riil — gunakan **akun & kunci khusus testnet** saja.
- Bila sebuah screenshot tak sengaja memuat rahasia, **redaksi/blur** dulu.
