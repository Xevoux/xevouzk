@extends('layouts.app')

@section('title', 'My Wallet — XevouZK')

@section('content')
<div class="wallet-container">
    <header class="wallet-header is-page">
        <span class="page-header__eyebrow">WALLET · POLYGON AMOY</span>
        <h1><i data-lucide="wallet"></i> My Wallet</h1>
        <p>Kelola alamat wallet, terima pembayaran via QR, dan request test MATIC.</p>
    </header>

    @if($wallet->isBalanceStale())
        @php($staleness = $wallet->balanceStaleness())
        <div id="balanceStaleBanner" class="alert alert-{{ $staleness === 'offline' ? 'error' : 'warning' }}">
            <i data-lucide="{{ $staleness === 'offline' ? 'wifi-off' : 'triangle-alert' }}"></i>
            <span>
                <strong>Saldo on-chain {{ $staleness === 'offline' ? 'belum tersinkron' : 'mungkin tidak terkini' }}.</strong>
                @if($wallet->last_sync_at)
                    Last sync: <span class="text-mono">{{ $wallet->last_sync_at->diffForHumans() }}</span>.
                @else
                    Belum pernah sync dengan Polygon RPC.
                @endif
                Saldo yang ditampilkan adalah cache database. Klik <em>refresh</em> di Wallet atau coba lagi nanti.
            </span>
        </div>
    @endif

    <div class="wallet-grid">
        {{-- Wallet info card --}}
        <section class="wallet-info-card">
            <h2><i data-lucide="contact"></i> Informasi Wallet</h2>

            <div class="wallet-detail">
                <label><i data-lucide="coins"></i> Saldo Tersedia</label>
                <div class="balance-display">
                    <h1 class="balance-amount">{{ number_format($wallet->balance, 8) }} MATIC</h1>
                </div>
            </div>

            <div class="wallet-detail">
                <label><i data-lucide="fingerprint"></i> Alamat Wallet</label>
                <div class="address-field">
                    <code id="walletAddress">{{ $wallet->wallet_address }}</code>
                    <button class="btn-copy" onclick="copyToClipboard('{{ $wallet->wallet_address }}', 'Alamat wallet')" title="Copy wallet address">
                        <i data-lucide="copy"></i>
                    </button>
                </div>
            </div>

            @if($wallet->polygon_address)
            <div class="wallet-detail">
                <label><i data-lucide="link"></i> Polygon Address</label>
                <div class="address-field">
                    <code>{{ $wallet->polygon_address }}</code>
                    <button class="btn-copy" onclick="copyToClipboard('{{ $wallet->polygon_address }}', 'Polygon address')" title="Copy polygon address">
                        <i data-lucide="copy"></i>
                    </button>
                </div>
            </div>
            @endif

            <div class="wallet-detail">
                <label><i data-lucide="key"></i> Public Key</label>
                <div class="address-field">
                    <code
                        id="publicKeyDisplay"
                        data-full-key="{{ $wallet->public_key }}"
                    >{{ str_repeat('•', 16) }}</code>
                    <button
                        class="btn-copy"
                        onclick="copyToClipboard('{{ $wallet->public_key }}', 'Public key')"
                        title="Copy full public key"
                    >
                        <i data-lucide="copy"></i>
                    </button>
                    <button
                        class="btn-toggle"
                        onclick="togglePublicKeyDisplay()"
                        title="Tampilkan public key"
                    >
                        <i data-lucide="eye"></i>
                    </button>
                </div>
            </div>

            <div class="wallet-actions">
                <a href="{{ route('payment.form') }}" class="btn btn--primary">
                    <i data-lucide="send"></i> Kirim
                </a>
                <a href="{{ route('payment.scan') }}" class="btn btn--ghost">
                    <i data-lucide="qr-code"></i> Scan
                </a>
                <a href="{{ route('dashboard') }}" class="btn btn--ghost">
                    <i data-lucide="house"></i> Dashboard
                </a>
            </div>
        </section>

        {{-- Faucet card (TESTNET UTILITY) --}}
        <section class="faucet-card">
            <h2><i data-lucide="droplets"></i> Test MATIC Faucet</h2>
            <p class="faucet-subtitle">Dapatkan test MATIC gratis untuk percobaan transaksi di Polygon Amoy.</p>

            <div class="faucet-info-box">
                <div class="faucet-stat">
                    <i data-lucide="coins"></i>
                    <div>
                        <strong>5 MATIC</strong>
                        <small>Per Request</small>
                    </div>
                </div>
                <div class="faucet-stat">
                    <i data-lucide="clock"></i>
                    <div>
                        <strong id="faucetCooldown">Checking...</strong>
                        <small>Cooldown</small>
                    </div>
                </div>
            </div>

            <button id="requestTestMaticBtn" class="btn btn--primary btn--block" onclick="requestTestMatic()">
                <i data-lucide="droplet"></i> Request Test MATIC
            </button>

            <div id="faucetStatus" class="faucet-status" style="display: none;">
                <div id="faucetMessage"></div>
            </div>

            <div class="faucet-note">
                <i data-lucide="info"></i> <strong>Catatan:</strong>
                <ul>
                    <li>Test MATIC tidak memiliki nilai uang asli</li>
                    <li>Hanya untuk testing di Polygon Amoy Testnet (chain 80002)</li>
                    <li>Request dibatasi 1× per 24 jam</li>
                </ul>
            </div>
        </section>

        {{-- QR code card --}}
        {{-- QR terima (client-side xevouzk:) --}}
        <section class="qr-code-card">
            <h2><i data-lucide="qr-code"></i> QR Code — Terima Pembayaran</h2>
            <p class="qr-subtitle">Tunjukkan QR ini ke pengirim. <strong>Plain</strong> = transfer publik ke alamat Polygon Anda; <strong>Privat</strong> = transfer pool (anonim) ke viewing key Anda.</p>

            <div class="payment-tabs">
                <button class="tab-btn active" type="button" onclick="switchReceiveTab('plain')">QR Plain</button>
                <button class="tab-btn" type="button" onclick="switchReceiveTab('private')">QR Privat</button>
            </div>

            <div id="receive-plain" class="tab-content active">
                <div class="form-group">
                    <label for="plainAmount">Jumlah (opsional, MATIC)</label>
                    <input type="number" id="plainAmount" step="0.000001" min="0.000001" placeholder="kosongkan = pengirim isi sendiri">
                </div>
                <div class="qr-display-area"><canvas id="plainQRCanvas"></canvas></div>
                <p class="qr-uri text-mono" id="plainQRUri"></p>
                <button type="button" class="btn btn--ghost" onclick="downloadReceiveQR('plainQRCanvas','xevouzk-plain')"><i data-lucide="download"></i> Download</button>
            </div>

            <div id="receive-private" class="tab-content">
                <div class="form-group">
                    <label for="privateAmount">Jumlah (opsional, MATIC)</label>
                    <input type="number" id="privateAmount" step="0.000001" min="0.000001" placeholder="kosongkan = pengirim isi sendiri">
                </div>
                <button type="button" id="genPrivateQRBtn" class="btn btn--primary"><i data-lucide="lock"></i> Tampilkan QR Privat</button>
                <div class="qr-display-area"><canvas id="privateQRCanvas"></canvas></div>
                <p class="qr-uri text-mono" id="privateQRUri"></p>
                <button type="button" class="btn btn--ghost" id="copyPrivateQRBtn" style="display:none;" onclick="copyToClipboard(document.getElementById('privateQRUri').textContent, 'Viewing key (kode privat)')"><i data-lucide="copy"></i> Copy Kode</button>
                <button type="button" class="btn btn--ghost" id="downloadPrivateQRBtn" style="display:none;" onclick="downloadReceiveQR('privateQRCanvas','xevouzk-private')"><i data-lucide="download"></i> Download</button>
                <small class="form-hint"><i data-lucide="shield"></i> QR ini aman dibagikan — hanya berisi <em>viewing key</em> publik, bukan alamat 0x atau secret.</small>
            </div>
        </section>

        {{-- Deposit ke Pool Privat --}}
        @if(env('POLYGON_CONTRACT_ADDRESS'))
        <section class="wallet-info-card pool-card pool-card--deposit">
            <h2><i data-lucide="lock"></i> Pool Privat (ZKPayment)</h2>
            <p class="muted">
                Deposit MATIC ke commitment pool untuk transaksi privat. Saldo di pool tidak terlihat di explorer publik —
                hanya commitment Poseidon. Note (secret) di-encrypt + simpan di browser; <strong>lupa password = note hilang</strong>.
            </p>
            <div class="form-group" style="margin-top: var(--s-3);">
                <label for="depositAmount">Jumlah Deposit (MATIC)</label>
                <input type="number" id="depositAmount" step="0.000001" min="0.000001" placeholder="0.01" style="max-width: 240px;">
                <small class="form-hint">Sisakan ~0.001 MATIC untuk gas deposit (~50-80k gas).</small>
            </div>
            <button id="depositBtn" type="button" class="btn btn--primary">
                <i data-lucide="lock"></i> Sign &amp; Deposit ke Pool
            </button>
            <div id="depositResult" style="margin-top: var(--s-3); display: none;"></div>
        </section>
        @endif

        {{-- Withdraw dari Pool Privat (dipindah dari payment form) --}}
        @if(env('POLYGON_CONTRACT_ADDRESS'))
        <section class="wallet-info-card pool-card pool-card--withdraw">
            <h2><i data-lucide="unlock"></i> Withdraw dari Pool Privat</h2>
            <p class="muted">
                Redeem note di pool ke alamat EOA mana pun. ZK proof membuktikan kepemilikan
                commitment tanpa reveal depositor. Withdraw event di explorer publik (recipient + amount terlihat),
                tapi <strong>tidak ter-link ke deposit asli</strong>.
            </p>
            <div class="form-group">
                <label for="withdrawNoteSelect">Pilih Note</label>
                <select id="withdrawNoteSelect">
                    <option value="">— Loading notes dari browser... —</option>
                </select>
                <small class="form-hint">Note di-decrypt pakai password Anda. Tidak ada note? Deposit dulu di atas.</small>
            </div>
            <div class="form-group">
                <label for="withdrawRecipient">Alamat Penerima Withdraw (0x...)</label>
                <input type="text" id="withdrawRecipient"
                       pattern="^0x[a-fA-F0-9]{40}$"
                       placeholder="0x..."
                       title="Format: 0x diikuti 40 karakter hex">
            </div>
            <button id="withdrawBtn" type="button" class="btn btn--ghost btn--block">
                <i data-lucide="unlock"></i> Generate Proof &amp; Withdraw
            </button>
            <div id="withdrawResult" style="margin-top: var(--s-3); display: none;"></div>
        </section>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<input type="hidden" id="faucetCheckUrl" value="{{ route('wallet.faucet.can-request') }}">
<input type="hidden" id="faucetRequestUrl" value="{{ route('wallet.faucet.request') }}">
<input type="hidden" id="qrDownloadUrl" value="{{ route('wallet.download-qr') }}">
<meta name="user-email" content="{{ Auth::user()->email }}">
<meta name="wallet-polygon-address" content="{{ $wallet->polygon_address }}">

{{-- deposit ke commitment pool + withdraw dari pool + terima QR (xevouzk:) --}}
@vite([
    'resources/js/polygon-key.js',
    'resources/js/polygon-deposit.js',
    'resources/js/payment-relay.js',
    'resources/js/polygon-withdraw.js',
    'resources/js/receive-qr.js',
])

<script>
function switchReceiveTab(t){
    document.querySelectorAll('.qr-code-card .tab-btn').forEach(b=>b.classList.remove('active'));
    document.querySelectorAll('.qr-code-card .tab-content').forEach(c=>c.classList.remove('active'));
    document.querySelector(`.qr-code-card [onclick="switchReceiveTab('${t}')"]`).classList.add('active');
    document.getElementById(t==='plain'?'receive-plain':'receive-private').classList.add('active');
}
function downloadReceiveQR(canvasId, name){
    const c=document.getElementById(canvasId); if(!c||!c.width){alert('Generate QR dulu.');return;}
    const a=document.createElement('a'); a.download=name+'.png'; a.href=c.toDataURL('image/png'); a.click();
}
document.addEventListener('DOMContentLoaded', async function(){
    const addr = document.querySelector('meta[name="wallet-polygon-address"]').content;
    const email = document.querySelector('meta[name="user-email"]').content;
    await (window.ReceiveQR || new Promise(r=>window.addEventListener('receive-qr-ready',r,{once:true})));
    const plainCanvas=document.getElementById('plainQRCanvas');
    const plainAmount=document.getElementById('plainAmount');
    async function plain(){ try{ const uri=await window.ReceiveQR.renderPlainQR(plainCanvas, addr, plainAmount.value||undefined); document.getElementById('plainQRUri').textContent=uri; }catch(e){ document.getElementById('plainQRUri').textContent='Error: '+e.message; } }
    if(addr) plain(); else document.getElementById('plainQRUri').textContent='Polygon address belum ada.';
    plainAmount.addEventListener('change', plain);
    document.getElementById('genPrivateQRBtn').addEventListener('click', async function(){
        const pw = prompt('Masukkan password untuk derive viewing key (zkpub) di browser. Tidak dikirim ke server.');
        if(!pw) return;
        if (!window.AccountGuard || !window.AccountGuard.assertPassword(pw)) return;
        try{
            const uri = await window.ReceiveQR.renderPrivateQR(document.getElementById('privateQRCanvas'), { email, password: pw, amount: document.getElementById('privateAmount').value||undefined });
            document.getElementById('privateQRUri').textContent=uri;
            document.getElementById('copyPrivateQRBtn').style.display='';
            document.getElementById('downloadPrivateQRBtn').style.display='';
        }catch(e){ document.getElementById('privateQRUri').textContent='Error: '+e.message; }
    });
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const qrForm = document.getElementById('generateCustomQR');
    if (qrForm) {
        qrForm.dataset.generateUrl = '{{ route("wallet.generate-receive-qr") }}';
    }

    // deposit handler
    const depositBtn = document.getElementById('depositBtn');
    const depositResult = document.getElementById('depositResult');
    if (depositBtn) {
        depositBtn.addEventListener('click', async function() {
            const amount = document.getElementById('depositAmount').value;
            if (!amount || parseFloat(amount) <= 0) {
                alert('Masukkan jumlah deposit valid.');
                return;
            }
            const contractAddr = document.querySelector('meta[name="zk-payment-contract"]').content;
            if (!contractAddr || !/^0x[a-fA-F0-9]{40}$/.test(contractAddr)) {
                alert('POLYGON_CONTRACT_ADDRESS belum di-set di .env.');
                return;
            }
            const email = document.querySelector('meta[name="user-email"]').content;
            const password = prompt('Masukkan password untuk derive Polygon key + encrypt note di browser ini. Password tidak dikirim ke server.');
            if (!password) return;
            if (!window.AccountGuard || !window.AccountGuard.assertPassword(password)) return;
            const csrf = document.querySelector('meta[name="csrf-token"]').content;

            depositBtn.disabled = true;
            depositResult.style.display = 'block';
            depositResult.innerHTML = '<em>Sign + relay deposit ke pool...</em>';

            // Wait modules — poll sampai siap (modul kripto vendored cukup besar,
            // bisa >5 dtk pada load pertama). Maksimum 30 dtk.
            const waitFor = (predicate, eventName) => new Promise(r => {
                if (predicate()) return r();
                let done = false;
                const finish = () => { if (!done) { done = true; r(); } };
                window.addEventListener(eventName, finish, { once: true });
                const started = Date.now();
                const iv = setInterval(() => {
                    if (predicate() || Date.now() - started > 30000) { clearInterval(iv); finish(); }
                }, 200);
            });
            await waitFor(() => !!window.PolygonKey, 'polygon-key-ready');
            await waitFor(() => !!window.PolygonDeposit, 'polygon-deposit-ready');
            if (!window.PolygonDeposit) {
                console.error('[deposit] window.PolygonDeposit belum termuat. Cek tab Console untuk error import modul (mis. /js/shield-key.js atau /vendor/...).');
                depositResult.innerHTML = '<span class="text-error">Modul deposit belum siap (modul kripto besar). Tunggu beberapa detik lalu coba lagi, atau hard-refresh (Ctrl+Shift+R). Jika tetap gagal, buka Console (F12) dan kirim errornya.</span>';
                depositBtn.disabled = false;
                return;
            }

            try {
                const r = await window.PolygonDeposit.depositToPool({
                    email,
                    password,
                    amountMatic: amount,
                    contractAddress: contractAddr,
                    csrfToken: csrf,
                });
                if (r.success) {
                    depositResult.innerHTML = `
                        <div class="alert alert-success">
                            <div class="alert__body">
                                ✓ Deposit terkonfirmasi on-chain. Tx: <code>${r.tx_hash}</code><br>
                                Commitment: <code>${r.commitment.slice(0, 24)}...</code><br>
                                Note tersimpan di browser (<code>${r.storage_key}</code>). Saldo pool di Dashboard akan langsung terlihat. Jangan kehilangan password Anda.
                            </div>
                        </div>`;
                    // Saldo on-chain (EOA) berkurang → refresh instan tanpa nunggu poller.
                    window.dispatchEvent(new Event('xevou:refresh'));
                } else if (r.tx_hash) {
                    // Tx sudah ter-broadcast + note tersimpan, tapi konfirmasi lambat/timeout.
                    depositResult.innerHTML = `
                        <div class="alert alert-warning">
                            <div class="alert__body">
                                ⏳ ${r.error}<br>
                                Tx: <code>${r.tx_hash}</code>${r.storage_key ? `<br>Note tersimpan (<code>${r.storage_key}</code>).` : ''}
                            </div>
                        </div>`;
                    window.dispatchEvent(new Event('xevou:refresh'));
                } else {
                    depositResult.innerHTML = `<div class="alert alert-error"><div class="alert__body">Deposit gagal: ${r.error}</div></div>`;
                }
            } catch (e) {
                depositResult.innerHTML = `<div class="alert alert-error"><div class="alert__body">Error: ${e.message}</div></div>`;
            } finally {
                depositBtn.disabled = false;
            }
        });
    }

    // Withdraw flow (dipindah dari payment form)
    const withdrawNoteSelect = document.getElementById('withdrawNoteSelect');
    const withdrawBtn = document.getElementById('withdrawBtn');
    const withdrawResult = document.getElementById('withdrawResult');

    async function refreshWithdrawNotes() {
        if (!withdrawNoteSelect) return;
        if (withdrawNoteSelect.dataset.passwordPrompted) return;

        const email = document.querySelector('meta[name="user-email"]').content;
        const password = prompt('Masukkan password untuk decrypt notes di browser. Tidak dikirim ke server.');
        if (!password) {
            withdrawNoteSelect.innerHTML = '<option value="">— Password dibatalkan —</option>';
            return;
        }
        if (!window.AccountGuard || !window.AccountGuard.assertPassword(password)) {
            withdrawNoteSelect.innerHTML = '<option value="">— Password dibatalkan —</option>';
            return;
        }
        withdrawNoteSelect.dataset.passwordPrompted = '1';
        withdrawNoteSelect.dataset.cachedPassword = password;

        await new Promise(r => {
            if (window.PolygonWithdraw) return r();
            let done = false;
            const finish = () => { if (!done) { done = true; r(); } };
            window.addEventListener('polygon-withdraw-ready', finish, { once: true });
            const started = Date.now();
            const iv = setInterval(() => {
                if (window.PolygonWithdraw || Date.now() - started > 30000) { clearInterval(iv); finish(); }
            }, 200);
        });
        if (!window.PolygonWithdraw) {
            withdrawNoteSelect.innerHTML = '<option value="">— Modul withdraw belum siap, hard-refresh —</option>';
            return;
        }

        try {
            const notes = await window.PolygonWithdraw.listNotes(email, password);
            if (notes.length === 0) {
                withdrawNoteSelect.innerHTML = '<option value="">— Belum ada note (deposit dulu di atas) —</option>';
                return;
            }
            withdrawNoteSelect.innerHTML = '<option value="">— Pilih note —</option>' +
                notes.map(n => `<option value="${n.storage_key}">${n.amount_matic} MATIC · ${new Date(n.created_at).toLocaleString('id-ID')}</option>`).join('');
        } catch (e) {
            withdrawNoteSelect.innerHTML = `<option value="">— Decrypt gagal: ${e.message} —</option>`;
        }
    }

    if (withdrawNoteSelect) {
        withdrawNoteSelect.addEventListener('focus', refreshWithdrawNotes, { once: true });
    }

    if (withdrawBtn) {
        withdrawBtn.addEventListener('click', async function() {
            const storageKey = withdrawNoteSelect.value;
            const recipient = document.getElementById('withdrawRecipient').value.trim();
            if (!storageKey) { alert('Pilih note dulu.'); return; }
            if (!/^0x[a-fA-F0-9]{40}$/.test(recipient)) { alert('Recipient harus 0x...'); return; }
            const contractAddr = document.querySelector('meta[name="zk-payment-contract"]').content;
            if (!contractAddr) { alert('POLYGON_CONTRACT_ADDRESS belum di-set.'); return; }

            const email = document.querySelector('meta[name="user-email"]').content;
            const password = withdrawNoteSelect.dataset.cachedPassword
                || prompt('Masukkan password untuk decrypt note + derive Polygon key.');
            if (!password) return;
            if (!window.AccountGuard || !window.AccountGuard.assertPassword(password)) return;
            const csrf = document.querySelector('meta[name="csrf-token"]').content;

            withdrawBtn.disabled = true;
            withdrawResult.style.display = 'block';
            withdrawResult.innerHTML = '<em>Generate Groth16 proof (5-30 detik di browser)...</em>';

            try {
                const r = await window.PolygonWithdraw.withdrawFromPool({
                    email,
                    password,
                    storageKey,
                    recipientAddress: recipient,
                    contractAddress: contractAddr,
                    csrfToken: csrf,
                });
                if (r.success) {
                    withdrawResult.innerHTML = `
                        <div class="alert alert-success">
                            <div class="alert__body">
                                ✓ Withdraw sukses. Tx: <code>${r.tx_hash}</code><br>
                                Nullifier: <code>${r.nullifier.slice(0, 24)}...</code><br>
                                MATIC telah ditransfer ke <code>${recipient}</code>.
                            </div>
                        </div>`;
                } else {
                    withdrawResult.innerHTML = `<div class="alert alert-error"><div class="alert__body">Withdraw gagal: ${r.error}</div></div>`;
                }
            } catch (e) {
                withdrawResult.innerHTML = `<div class="alert alert-error"><div class="alert__body">Error: ${e.message}</div></div>`;
            } finally {
                withdrawBtn.disabled = false;
            }
        });
    }
});
</script>
<script src="{{ asset('js/wallet.js') }}"></script>
@endpush
