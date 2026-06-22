# Deploy Guide — XevouZK Smart Contracts ke Polygon Amoy

> Operational runbook untuk men-deploy 4 kontrak XevouZK ke Polygon Amoy testnet.
> Estimasi: **30–60 menit** termasuk verifikasi source di PolygonScan.

---

## 0. Pra-syarat

- [ ] Node.js ≥ 18 + npm
- [ ] `circuits/keys/withdraw_final.zkey`, `balance_check_final.zkey`, `private_transfer_final.zkey` sudah ada (output trusted setup)
- [ ] `contracts/contracts/verifiers/*.sol` sudah di-export dari snarkjs
- [ ] `.env` punya `DEPLOYER_PRIVATE_KEY` (wallet deployer — least privilege, terpisah dari MASTER)
- [ ] Deployer wallet di-funded **minimal 0.4 MATIC** di Amoy.
      Faucet publik: https://faucet.polygon.technology/
- [ ] `.env` punya `POLYGONSCAN_API_KEY` (opsional, untuk verify source)

Cek funding deployer:
```powershell
cd contracts
npx hardhat run scripts/check-balance.js --network amoy
```

---

## 1. Pre-flight check (lokal)

```powershell
# Compile + behavioral test
cd contracts
npx hardhat compile
npx hardhat test                                # 34/34 behavioral test harus hijau

# Local proof generation + verify
cd ..\circuits
node scripts/test-proofs.js                     # 3/3 circuit (balance, transfer, withdraw)
```

Kalau ada yang merah, fix lokal dulu — **jangan deploy**.

---

## 2. Deploy 4 kontrak ke Amoy

```powershell
cd contracts
npx hardhat run scripts/deploy.js --network amoy
```

Output sukses akan menampilkan 4 alamat:

```
Network: amoy
Deployer: 0xF90BA9d8AD592b2B2deB7495C9357383E273Adf4
Balance: 0.5 MATIC

1/4 Deploying BalanceCheckVerifier...    → 0x...
2/4 Deploying PrivateTransferVerifier... → 0x...
3/4 Deploying WithdrawVerifier...        → 0x...
4/4 Deploying ZKPayment v2...            → 0x...

Verifying constructor wiring...
  ✓ all wiring correct

Initial state:
  totalDeposited = 0, totalWithdrawn = 0, transactionCount = 0

Deployment info → contracts/deployments/amoy.json
```

**Simpan keempat alamat** — masuk ke `.env` di step berikutnya.

Estimasi waktu: ~2–5 menit (4× konfirmasi blok Amoy).
Estimasi gas: ~0.05–0.15 MATIC total.

---

## 3. Update `.env`

```env
POLYGON_CONTRACT_ADDRESS=0x<ZKPayment_address>
POLYGON_BALANCE_VERIFIER_ADDRESS=0x<BalanceVerifier_address>
POLYGON_TRANSFER_VERIFIER_ADDRESS=0x<TransferVerifier_address>
POLYGON_WITHDRAW_VERIFIER_ADDRESS=0x<WithdrawVerifier_address>

# Scan dana masuk (privat) — proxy server /payment/scan-rpc memakai RPC ini.
# RPC publik gratis kini membatasi getLogs historis → pakai RPC ber-API-key
# (mis. Alchemy Amoy). URL ber-API-key TAK pernah dikirim ke browser.
POLYGON_SCAN_RPC_URL=https://polygon-amoy.g.alchemy.com/v2/<API_KEY>
# Blok deploy ZKPayment (dari output deploy / amoy.json) → mempersempit rentang scan
POLYGON_CONTRACT_DEPLOY_BLOCK=<deploy_block>
```

Lalu jalankan migrasi (termasuk `note_backups` untuk backup note lintas-device)
+ clear cache Laravel:
```powershell
php artisan migrate
php artisan config:clear
```

---

## 4. Verify source di PolygonScan

Output dari step 2 mencantumkan 4 command `npx hardhat verify`. Jalankan:

```powershell
cd contracts
npx hardhat verify --network amoy <BalanceVerifier_address>
npx hardhat verify --network amoy <TransferVerifier_address>
npx hardhat verify --network amoy <WithdrawVerifier_address>
npx hardhat verify --network amoy <ZKPayment_address> <BalanceVerifier_address> <TransferVerifier_address> <WithdrawVerifier_address>
```

Setelah verify sukses, tab "Contract" di PolygonScan menampilkan source lengkap.
Ini penting untuk klaim transparansi pada laporan TA.

---

## 5. Smoke test on-chain end-to-end

**Test #1 — Direct contract call via Hardhat console** (cepat, tanpa UI):

```powershell
cd contracts
npx hardhat console --network amoy
```

Di console:
```javascript
const ZKPayment = await ethers.getContractFactory("ZKPayment");
const zk = ZKPayment.attach("0x<ZKPayment_address>");

// State fresh
await zk.totalDeposited();      // → 0n
await zk.totalWithdrawn();      // → 0n
await zk.transactionCount();    // → 0n
await zk.owner();               // → deployer address

// Verifier wiring
await zk.balanceVerifier();
await zk.transferVerifier();
await zk.withdrawVerifier();
```

**Test #2 — Browser end-to-end:**

```powershell
npm run build    # build asset Vite (Herd menyajikan PHP-nya, tak perlu artisan serve)
```

Pastikan proyek sudah ter-link ke Herd (`herd link xevouzk`), lalu buka `https://xevouzk.test`:

1. **Register** Alice (`alice@test.com` + password 8+ karakter)
   - Network tab: POST `/register` hanya kirim `schnorr_public_key`, `polygon_address`, `polygon_public_key` — **tidak ada private key**
2. **Login** → dashboard
3. `/wallet` → **Request MATIC** → tunggu konfirmasi, saldo bertambah 5 MATIC
4. `/wallet` → section "Pool Privat (ZKPayment)" → input `0.01` MATIC →
   **Sign & Deposit**
   - Prompt password
   - Tx confirmed, output: `tx_hash`, `commitment`, `storage_key`
   - Cek PolygonScan: tx `deposit(uint256)` value 0.01 MATIC
5. Logout Alice, register Bob (`bob@test.com`)
6. Login Bob, copy `bob.polygon_address`
7. Logout Bob, login Alice
8. `/wallet` → section "Withdraw dari Pool Privat":
   - Dropdown → prompt password → pilih note 0.01 MATIC
   - Recipient: address Bob
   - **Generate Proof & Withdraw** (user sign sendiri, lalu di-relay)
   - Tunggu 5–30 detik (proof generation di browser)
   - Tx confirmed, output `tx_hash` + `nullifier`
9. PolygonScan: tx `withdraw(...)`, recipient = Bob, value 0.01 MATIC
10. Login Bob, `/wallet` → saldo on-chain Bob bertambah 0.01 MATIC

Kalau step 10 OK, settlement on-chain bekerja end-to-end. Ini adalah skenario
demo paling kuat untuk TA defense.

> **Uji transfer privat + terima**: untuk menguji `privateTransfer` → "Cek Transfer
> Masuk" (penemuan note via event), pastikan `POLYGON_SCAN_RPC_URL` di `.env` mengarah
> ke RPC ber-API-key (Alchemy) dan `POLYGON_CONTRACT_DEPLOY_BLOCK` ter-set — scan
> dilakukan lewat proxy `/payment/scan-rpc`. Tanpa ini, scan getLogs historis gagal.
>
> **Akses dari HP** (uji kamera QR): jalankan tunnel publik (mis. `herd share` atau
> ngrok). `bootstrap/app.php` sudah `trustProxies(at: '*')` sehingga URL/HTTPS memakai
> domain tunnel (login & aset tak rusak di HP); route `payment/scan-rpc` di-CSRF-exempt
> (dipanggil `ethers` JsonRpcProvider), tetap di belakang `auth`.

---

## 6. Update dokumentasi address

Setelah deploy sukses, edit [PROJECT-STATUS.md](PROJECT-STATUS.md) §2 dengan alamat baru.

---

## Troubleshooting

### "insufficient funds for gas"
Deployer wallet balance < 0.4 MATIC. Top-up dari faucet.

### "nonce too low" atau "replacement transaction underpriced"
Tx sebelumnya stuck. Cek `https://amoy.polygonscan.com/address/<deployer>` dan tunggu sampai pending tx confirmed.

### Hardhat verify gagal "Already Verified"
Source sudah ter-verify (kontrak deterministik). Skip aman.

### `.env` tidak ter-pickup
```powershell
php artisan config:clear
php artisan cache:clear
```

### Deploy error "Cannot find module verifiers/WithdrawVerifier.sol"
Verifier belum di-export. Re-run:
```powershell
cd circuits
npm run setup:withdraw
npm run export:verifiers
```

### Hardhat test gagal di pre-flight
Cek `contracts/test/ZKPayment.test.js` exception trace. Pastikan `circuits/keys/*.zkey` ada (verifier Solidity dependent pada VK constants).
