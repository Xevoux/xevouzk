# Pengujian & Evaluasi — XevouZK

> **Tujuan dokumen**: panduan + **template hasil** untuk bab *Pengujian* TA. Berisi
> 7 jenis pengujian, dipetakan ke **klaim yang divalidasi**, lengkap dengan
> command, metrik, **template tabel hasil**, dan cara **capture bukti**.
>
> **Hubungan dengan dokumen lain** (jangan duplikasi — dokumen ini mengorganisir,
> yang lain memuat detail):
> - [TESTING-EVIDENCE.md](TESTING-EVIDENCE.md) — runbook command mentah + gas detail.
> - [PRIVACY-GAP-ANALYSIS.md](PRIVACY-GAP-ANALYSIS.md) — klaim privasi per data field.
> - [ZK-SNARK-DOCUMENTATION.md](ZK-SNARK-DOCUMENTATION.md) §9 — threat model.
> - [PROJECT-STATUS.md](PROJECT-STATUS.md) §7 — demo script TA.
>
> **Snapshot**: 2026-06-22. Runtime lokal via Laravel Herd; shell contoh **PowerShell**.

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
| 7 | Keamanan autentikasi Schnorr *(opsional)* | **Keamanan auth** | Replay/invalid-signature/brute-force ditolak | Uji manual (curl/browser) |

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

## 7. Keamanan autentikasi Schnorr *(opsional)*

> **Status**: opsional — boleh tidak dipakai. Suite PHPUnit (yang dulu menguji ini
> otomatis) sudah dihapus dari repo, jadi pengujian kini **manual**.

**Tujuan**: membuktikan login Schnorr tahan replay, signature palsu, dan brute-force.

**Klaim yang diuji**: [ZK-SNARK §9.3](ZK-SNARK-DOCUMENTATION.md) (baris replay/login) +
[PRIVACY-GAP §3.F](PRIVACY-GAP-ANALYSIS.md).

**Skenario manual** (browser DevTools / curl):

| Skenario | Langkah | Harapan |
|---|---|---|
| Replay signature | Tangkap request `POST /login` yang sukses, kirim ulang signature yang sama | Ditolak (single-use nonce + window 5 menit) |
| Signature palsu | Ubah 1 karakter `schnorr_signature` lalu submit | Ditolak (verifikasi gagal) |
| Brute-force | Submit login gagal > 5× untuk email yang sama | Diblokir sementara (rate limit per email+IP) |
| Timestamp kedaluwarsa | Kirim `schnorr_timestamp` > 300 detik lalu | Ditolak (di luar window) |

**Cara capture bukti**: screenshot response server (403/422/429) + pesan error generik.

> Kalau ingin reproducible (bukan klik manual), skenario ini bisa dibuat skrip kecil
> Node/curl — minta dibuatkan bila perlu.

---

## 8. Kerangka bab Pengujian (usulan)

Susunan yang mengalir untuk laporan (sesuaikan aturan skripsi):

1. **Lingkungan pengujian** — spesifikasi mesin, OS, Node/PHP, browser, jaringan,
   alamat kontrak Amoy (dari [PROJECT-STATUS §2](PROJECT-STATUS.md)).
2. **Pengujian fungsional** — alur deposit → transfer → withdraw berhasil (smoke test
   browser, [DEPLOY-GUIDE §5](DEPLOY-GUIDE.md)).
3. **Pengujian privasi** — §1 (Plain vs Privat) + §6 (by inspection).
4. **Pengujian performa** — §2 (waktu proof) + §3 (gas).
5. **Pengujian keamanan/correctness** — §4 (Hardhat) + §5 (soundness) + §7 (auth, opsional).
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
