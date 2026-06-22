# Testing & Evidence — XevouZK

> Runbook **reproduksi bukti pengujian** untuk laporan/pertahanan TA. Setiap
> bagian menyebut: **command** yang dijalankan, **bukti** yang dihasilkan, dan
> **apa yang di-capture** (screenshot/log) untuk laporan.
>
> Untuk **kerangka bab Pengujian** (7 jenis uji dipetakan ke klaim + template tabel
> hasil), lihat [PENGUJIAN.md](PENGUJIAN.md). Dokumen ini = command mentahnya.
>
> Snapshot: 2026-06-22. Runtime lokal via Laravel Herd (lihat [DEPLOY-GUIDE.md](DEPLOY-GUIDE.md)).
> Shell contoh: **PowerShell** (Windows + Herd).
>
> **Catatan 2026-06-22**: suite **PHPUnit** (jalur HTTP server) + `phpunit.xml`
> dihapus dari repo. Bukti correctness TA kini bersandar pada lapis: Hardhat
> behavioral kontrak, gas (lokal + riil on-chain Amoy), dan proof Groth16 lokal
> (snarkjs). Fitur baru sisi-klien (backup note lintas-device, account-guard,
> proxy scan RPC) diverifikasi via **smoke test browser E2E manual**, bukan unit test.
>
> ### Hasil eksekusi terakhir — 2026-06-22 (offline, reproducible)
>
> **Lingkungan**: Node v22.14.0 · AMD Ryzen 5 6600H (12 vCPU) · 16,4 GB RAM · Windows.
>
> | § | Pengujian | Status | Ringkas hasil |
> |---|---|---|---|
> | §1 | Hardhat behavioral (34 test) | ✅ **34 passing (2s)** | semua hijau |
> | §1.1 | Gas-reporter (lokal, MockVerifier) | ✅ jalan | deposit ~65.7k, privateTransfer ~128k, withdraw ~88.7k gas |
> | §3 | Proof Groth16 lokal (verify + negative) | ✅ **ALL TESTS PASSED** | 3 circuit verified, 4 negative case ditolak |
> | §3.1 | Bench waktu proof (N=15) | ✅ jalan | mean 110 / 298 / 229 ms (balance/transfer/withdraw) |
> | §2 | Gas riil on-chain (Amoy) | ⏸️ tidak dijalankan ulang | butuh dana + RPC; angka historis di §2 |
> | §4 | Sanity verify-deployment | ⏸️ tidak dijalankan | butuh `.env` + RPC Amoy |
> | §5 | Interop Schnorr JS↔PHP | ❌ tidak dapat dijalankan | skrip `tools/schnorr-interop-vectors.mjs` **tidak ada** di repo (lihat §5) |

---

## 0. Ringkasan: jenis bukti → command → artefak

| Bukti | Command | Artefak untuk laporan |
|---|---|---|
| Behavioral smart contract (34 test) | `cd contracts && npx hardhat test` | Output `34 passing` |
| **Gas used per method** | `$env:REPORT_GAS="true"; npx hardhat test` | Tabel gas-reporter (min/max/avg gas + deploy cost) |
| **Gas used + gas price on-chain (real)** | `npx hardhat run scripts/test-transfer-e2e.js --network amoy` | Log `gasUsed` per tx + tx hash; halaman tx di PolygonScan (Gas Used, Gas Price, Txn Fee) |
| Proof Groth16 lokal (generate + verify) | `cd circuits && node scripts/test-proofs.js` | Log waktu generate (ms) + `proof verified` + negative-case rejected |
| Source kontrak ter-verify | `npx hardhat verify --network amoy <addr> [args]` | Tab "Contract" hijau di PolygonScan |
| Konfigurasi `.env` ↔ kontrak hidup | `php tools/verify-deployment.php` | Log `owner()` cocok + RPC reachable |
| Waktu pembangkitan proof (statistik) | `cd circuits && node scripts/bench-proofs.js [N]` | Tabel mean/median/p95/stdev per circuit + constraint |
| ~~Schnorr JS↔PHP byte-identik~~ | ~~`node tools/schnorr-interop-vectors.mjs`~~ | ⚠️ skrip **tidak ada** di repo saat ini — lihat §5 |

Alamat kontrak hidup (Amoy) ada di [PROJECT-STATUS §2](PROJECT-STATUS.md) /
`contracts/deployments/amoy.json`.

---

## 1. Smart contract — Hardhat behavioral test

```powershell
cd contracts
npm install            # sekali
npx hardhat compile
npx hardhat test
```

**Bukti**: `34 passing` — mencakup deposit lifecycle, `privateTransfer`
(burn + mint 2 commitment + event `EncryptedNote`), `withdraw` + invariant
`pool == totalDeposited − totalWithdrawn`, double-spend revert, dan admin guard.

> **Hasil terukur 2026-06-22**: `34 passing (2s)` — seluruh 34 test hijau
> (constructor/zero-addr, deposit, privateTransfer incl. nullifier-reuse &
> collision revert, withdraw incl. invariant, updateVerifier owner-guard,
> emergencyWithdraw).

> Test ini jalan di EVM in-memory Hardhat (tanpa biaya, tanpa internet) — bukti
> **correctness** logika kontrak, bukan biaya gas riil.

### 1.1 Bukti gas used per method (gas reporter)

`hardhat-toolbox` sudah menyertakan **hardhat-gas-reporter**. Aktifkan lewat env:

```powershell
cd contracts
$env:REPORT_GAS="true"; npx hardhat test
```

**Bukti**: tabel gas — kolom **Min / Max / Avg gas** per method (`deposit`,
`privateTransfer`, `withdraw`, dst.) plus **biaya deploy** tiap kontrak. Ini angka
gas-used hasil eksekusi di EVM lokal — cocok untuk tabel "biaya komputasi" di laporan.

> Blok `gasReporter` sudah ditambahkan ke [`contracts/hardhat.config.js`](../contracts/hardhat.config.js)
> (`enabled: process.env.REPORT_GAS === "true"`, `gasPrice: 35` gwei = jaringan Amoy).

**Hasil terukur 2026-06-22** (EVM lokal, **MockVerifier — tanpa pairing**):

| Method | Min gas | Max gas | Avg gas | # calls |
|---|---|---|---|---|
| `deposit` | 50.783 | 67.883 | 65.746 | 24 |
| `privateTransfer` | — | — | 128.462 | 6 |
| `withdraw` | — | — | 88.739 | 4 |
| `emergencyWithdraw` | — | — | 30.429 | 2 |
| `updateBalanceVerifier` | — | — | 30.899 | 1 |
| `updateWithdrawVerifier` | — | — | 30.953 | 1 |
| **Deploy `ZKPayment`** | — | — | **1.236.471** | (4,1% block limit) |

> ⚠️ **Angka ini LOKAL dengan `MockVerifier`** (verifier dummy `return true`, **tanpa**
> pairing) — jadi `privateTransfer`/`withdraw` jauh lebih kecil dari on-chain riil
> (§2: 478k / 428k). Ini mengukur **bookkeeping kontrak** saja. Untuk biaya verifikasi
> Groth16 sebenarnya, pakai angka on-chain §2. Sebutkan **keduanya** dan jelaskan bedanya.

---

## 2. Bukti gas riil on-chain (Polygon Amoy)

Skenario lifecycle nyata: deposit → privateTransfer → withdraw → tolak replay,
memakai **real Groth16 proof** yang diverifikasi on-chain.

> ⏸️ **Tidak dijalankan ulang pada sesi 2026-06-22** (butuh `DEPLOYER_PRIVATE_KEY`
> ber-saldo + RPC Amoy). Angka di tabel bawah adalah **bukti historis** dari run
> 2026-06-13 yang masih valid (kontrak sama). Jalankan command di bawah untuk reproduksi.

```powershell
cd contracts
# .env harus punya DEPLOYER_PRIVATE_KEY ber-saldo (cek dulu)
npx hardhat run scripts/check-balance.js --network amoy
npx hardhat run scripts/test-transfer-e2e.js --network amoy
```

**Bukti dari output script** (mencetak per langkah):
- `deposit tx: 0x… (block N)`
- `transfer tx: 0x… (gas <gasUsed>)`
- `withdraw tx: 0x… (gas <gasUsed>)`
- `✓ replay ditolak (Nullifier already used)`
- `pool balance now: … MATIC`

**Bukti dari PolygonScan** (buka tiap tx hash di `https://amoy.polygonscan.com/tx/<hash>`):
- **Gas Used by Txn**, **Gas Price** (≈ 35 gwei sesuai config), dan **Txn Fee**
  (= Gas Used × Gas Price) terlihat langsung — capture halaman ini untuk laporan.
- Tab **Logs** menampilkan event `EncryptedNote` (transfer) dan transfer MATIC (withdraw).

Angka acuan **terukur** dari receipt e2e di Amoy (35 gwei, 2026-06-13 via
`eth_getTransactionReceipt`):

| Method | Gas used (real) | Fee @ 35 gwei | Verifikasi Groth16 on-chain |
|---|---|---|---|
| `deposit` | 74.963 | ~0,0026 MATIC | ❌ tidak |
| `withdraw` | 427.667 | ~0,0150 MATIC | ✅ ya |
| `privateTransfer` | 478.101 | ~0,0167 MATIC | ✅ ya |
| plain transfer | ~21.000 | ~0,0007 MATIC | — |

> **Asimetri deposit vs withdraw**: `deposit` hanya mencatat commitment (tanpa
> proof) → murah; `withdraw`/`privateTransfer` menjalankan verifikasi Groth16
> (pairing via precompile EIP-197, ~250k–340k gas) → mahal. Biaya verifikasi ini
> **konstan terhadap nominal**, jadi withdraw note sangat kecil (mis. 0,0001
> MATIC) tidak ekonomis. Penjelasan lengkap: [PROJECT-UNDERSTANDING §10](PROJECT-UNDERSTANDING.md#10-penjelasan-istilah-yang-sering-ditanya).
>
> Catatan: tabel gas-reporter (§1.1) memakai **MockVerifier** (tanpa pairing),
> jadi angkanya lebih kecil dari on-chain — itu mengukur *bookkeeping* kontrak
> saja, bukan biaya verifikasi proof riil.

> Tx historis yang sudah terbukti tercatat di [PROJECT-STATUS §1](PROJECT-STATUS.md)
> (lengkap dengan tautan PolygonScan).

---

## 3. Bukti proof zk-SNARK (snarkjs, lokal)

```powershell
cd circuits
npm install            # sekali
# butuh artefak build: npm run build  (compile + trusted setup + export)
node scripts/test-proofs.js
```

**Bukti** untuk 3 circuit (`balance_check`, `private_transfer`, `withdraw`):
- `✓ proof generated in <ms>ms` — waktu pembangkitan proof di mesin.
- `✓ proof verified` — `snarkjs.groth16.verify` mengembalikan `true`.
- `✓ tampered proof rejected` — proof yang diubah 1 byte ditolak (soundness).
- Negative case: `✓ wrong shieldPriv rejected` & `✓ insufficient balance rejected`.
- Penutup `✓ ALL TESTS PASSED`.

> **Hasil terukur 2026-06-22**: `✓ ALL TESTS PASSED` — ketiga circuit
> `proof verified`, ketiga `tampered proof rejected`, plus negative case
> `wrong shieldPriv rejected` & `insufficient balance rejected`. Membuktikan
> **completeness** (proof valid diterima) + **soundness** (proof curang/witness
> salah ditolak).

Karakteristik proof Groth16 (untuk laporan): ukuran ~**192 byte** (3 elemen grup:
2×G1 + 1×G2), public signals 2–4 field element, verifikasi konstan ~3 pairing.

### 3.1 Waktu pembangkitan proof — statistik (performa)

[bench-proofs.js](../circuits/scripts/bench-proofs.js) menjalankan tiap circuit N
kali (1 warmup dibuang) lalu mencetak statistik + jumlah constraint.

```powershell
cd circuits
node scripts/bench-proofs.js 15        # N=15 (default)
```

**Hasil terukur 2026-06-22** — **Node v22.14.0 · AMD Ryzen 5 6600H (12 vCPU) · 16,4 GB RAM** (N=15):

| Circuit | Constraints | Mean (ms) | Median | p95 | Min | Max | Stdev |
|---|---|---|---|---|---|---|---|
| `balance_check` | 586 | 110 | 107 | 157 | 91 | 157 | 14,5 |
| `private_transfer` | 2.886 | 298 | 289 | 341 | 257 | 341 | 21,0 |
| `withdraw` | 1.537 | 229 | 227 | 260 | 201 | 260 | 16,8 |

> **Catatan jujur**: angka **bergantung hardware** — selalu sebutkan spesifikasi
> mesin di laporan. Ini diukur di **Node**; di **browser** (tempat proof dibuat saat
> dipakai) biasanya **lebih lambat**. Waktu sebanding dengan jumlah constraint
> (`private_transfer` paling banyak → paling lama).

---

## 4. Sanity konfigurasi ↔ kontrak hidup

> Jalur HTTP server **tidak lagi** punya suite PHPUnit (dihapus dari repo; lihat
> catatan di header). Sanity check di bawah tetap berguna untuk membuktikan
> aplikasi lokal benar-benar terhubung ke kontrak yang ter-deploy.
>
> ⏸️ **Tidak dijalankan pada sesi 2026-06-22** (butuh `.env` terisi + RPC Amoy
> reachable). Jalankan via PowerShell/Herd saat demo.

```powershell
php tools/verify-deployment.php
```

**Bukti**: mencetak `POLYGON_CONTRACT_ADDRESS` dari `.env`, memanggil `owner()`
kontrak via RPC, dan mengonfirmasi alamat reachable + cocok. (Butuh RPC Amoy
reachable.)

---

## 5. Bukti interoperabilitas Schnorr (JS ↔ PHP) — ⚠️ skrip belum ada

> **Status 2026-06-22**: skrip `tools/schnorr-interop-vectors.mjs` yang dirujuk
> di sini **tidak ada di repo** (folder `tools/` hanya berisi `verify-deployment.php`).
> Pengujian ini **belum dapat dijalankan** dan **belum** boleh diklaim sebagai bukti.

**Yang sudah ada sebagai bukti konsistensi Schnorr JS↔PHP saat ini**: kode
[schnorr-auth.js](../resources/js/schnorr-auth.js) (browser) dan
[SchnorrService.php](../app/Services/SchnorrService.php) (server) memakai derivasi
& algoritma yang sama (`sha256("schnorr_v1:email:password")` → scalar; verifikasi
`s·G == R + e·P`), dan **login E2E berhasil** (browser sign → server verify) di
smoke test — itu bukti fungsional bahwa keduanya kompatibel.

**Untuk bukti formal "byte-identik"** perlu skrip harness baru (Node + PHP) yang
membandingkan vektor pada witness sama. Kalau diperlukan untuk laporan, harness ini
bisa dibuat (Node mereplikasi `schnorr-auth.js` + PHP memanggil `SchnorrService`,
lalu bandingkan output). Sampai itu dibuat, jangan tulis "interop byte-identik" sebagai
hasil yang sudah terbukti.

---

## 6. Catatan untuk laporan TA

- **Pisahkan dua jenis "biaya"**: gas-reporter (§1.1) = gas-used hasil eksekusi
  EVM lokal (reproducible, deterministik); PolygonScan (§2) = gas-used + gas
  price + fee **riil** di testnet. Sebutkan keduanya bila perlu.
- **Reproducibility**: §1 (Hardhat), §1.1 (gas lokal), §3 (snarkjs lokal), dan §3.1
  (bench waktu proof) tidak butuh internet/biaya — aman dijalankan berulang saat sidang
  (semua sudah dijalankan & lulus 2026-06-22). §2 butuh saldo testnet (faucet) + RPC Amoy;
  §4 butuh RPC Amoy reachable; §5 **belum ada skripnya**.
- **Waktu proof bergantung hardware**: selalu cantumkan spesifikasi mesin (lihat §3.1)
  dan jumlah N saat melaporkan angka performa — bukan satu kali run.
- **Jangan klaim** privasi yang tidak benar saat menyajikan bukti — lihat
  batasan di [PRIVACY-GAP-ANALYSIS.md](PRIVACY-GAP-ANALYSIS.md) (mis. `msg.sender`
  transaksi privat = user; yang tersembunyi nominal & penerima).
- Detail trusted setup & circuit: [ZK-SNARK-DOCUMENTATION.md §6](ZK-SNARK-DOCUMENTATION.md).
