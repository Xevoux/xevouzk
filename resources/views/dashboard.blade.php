@extends('layouts.app')

@section('title', 'Dashboard — XevouZK')

@section('content')
<div class="dashboard-container">
    <header class="dashboard-header">
        <span class="page-header__eyebrow">DASHBOARD · {{ now()->format('d M Y · H:i') }}</span>
        <h1><i data-lucide="shield"></i> Selamat datang, {{ Auth::user()->name }}</h1>
        <p>Ringkasan wallet dan aktivitas terbaru Anda di Polygon Amoy.</p>
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
                Saldo yang ditampilkan adalah cache database, bukan saldo real-time.
            </span>
        </div>
    @endif

    <section class="wallet-card">
        <div class="wallet-header">
            <h2><i data-lucide="wallet"></i> Wallet</h2>
            <span class="status-pill status-pill--connected">
                <span class="status-pill__dot"></span>
                <span class="status-pill__label">ACTIVE</span>
            </span>
        </div>

        <div class="wallet-balance">
            <p class="balance-label">SALDO ON-CHAIN <span class="balance-tag">PUBLIK</span></p>
            <h1 class="balance-amount">{{ number_format($wallet->balance, 8) }}</h1>
        </div>

        @if(env('POLYGON_CONTRACT_ADDRESS'))
        <div class="wallet-balance pool-balance" id="poolBalanceCard" data-state="locked">
            <p class="balance-label">
                SALDO POOL <span class="balance-tag balance-tag--private">PRIVAT</span>
                <button type="button" id="poolEyeBtn" class="btn-reveal" title="Tampilkan saldo pool" aria-label="Tampilkan atau sembunyikan saldo pool">
                    <i data-lucide="eye"></i>
                </button>
                <button type="button" id="poolRefreshBtn" class="btn-reveal" title="Segarkan saldo pool" style="display: none;" aria-label="Segarkan saldo pool">
                    <i data-lucide="refresh-cw"></i>
                </button>
                <button type="button" id="poolScanBtn" class="btn-reveal" title="Cek transfer masuk ke pool" aria-label="Cek transfer masuk">
                    <i data-lucide="inbox"></i>
                </button>
            </p>
            <h1 class="balance-amount" id="poolBalanceAmount" data-live-ignore>••••••••</h1>
            <p class="pool-balance-meta" id="poolBalanceMeta">tersembunyi · tekan ikon mata untuk lihat</p>
        </div>
        @endif

        <div class="wallet-info">
            <div class="info-item">
                <label><i data-lucide="fingerprint" style="color: var(--purple-400); margin-right: 4px;"></i> Internal Wallet ID</label>
                <div class="wallet-address">
                    <code>{{ $wallet->wallet_address }}</code>
                    <button class="btn-copy" onclick="copyToClipboard('{{ $wallet->wallet_address }}')" title="Copy">
                        <i data-lucide="copy"></i>
                    </button>
                </div>
            </div>

            @if($wallet->polygon_address)
            <div class="info-item">
                <label><i data-lucide="link" style="color: var(--purple-400); margin-right: 4px;"></i> Polygon Address</label>
                <div class="wallet-address">
                    <code>{{ $wallet->polygon_address }}</code>
                    <button class="btn-copy" onclick="copyToClipboard('{{ $wallet->polygon_address }}')" title="Copy">
                        <i data-lucide="copy"></i>
                    </button>
                </div>
            </div>
            @endif
        </div>

        <div class="wallet-actions">
            <a href="{{ route('wallet.index') }}" class="btn btn--primary">
                <i data-lucide="wallet"></i>
                <span>My Wallet</span>
            </a>
            <a href="{{ route('payment.form') }}" class="btn btn--ghost">
                <i data-lucide="send"></i>
                <span>Kirim Pembayaran</span>
            </a>
            <a href="{{ route('payment.scan') }}" class="btn btn--ghost">
                <i data-lucide="qr-code"></i>
                <span>Scan QR</span>
            </a>
        </div>
    </section>

    <section class="transactions-card">
        <div class="card-header">
            <h2><i data-lucide="line-chart"></i> Transaksi Terbaru</h2>
            <a href="{{ route('payment.history') }}" class="btn-link">Lihat Semua</a>
        </div>

        <div class="transactions-list" data-live="transactions">
            @forelse($recentTransactions as $transaction)
            @php
                // Selaraskan dengan halaman history + live-updates.js (txRow):
                // privasi ditentukan oleh zk_proof ATAU type privat. Baris
                // private_transfer/private_receive TIDAK menyimpan zk_proof,
                // nominal, maupun counterparty (lihat PaymentController@recordPoolEvent),
                // jadi ketiganya disembunyikan agar render statis = render live.
                $sent = $transaction->sender_wallet_id == $wallet->id;
                $isPrivate = $transaction->zk_proof
                    || in_array($transaction->type, ['private_transfer', 'private_receive'], true);
                $counterparty = $sent
                    ? ($transaction->receiverWallet->wallet_address ?? $transaction->receiver_address ?? '—')
                    : ($transaction->senderWallet->wallet_address ?? '—');
                $label = $isPrivate
                    ? ($sent ? 'Kirim privat' : 'Terima privat')
                    : ($sent ? 'Kirim ke' : 'Terima dari');
            @endphp
            <div class="transaction-item {{ $sent ? 'sent' : 'received' }}">
                <div class="transaction-icon">
                    <i data-lucide="{{ $sent ? 'arrow-up' : 'arrow-down' }}" style="color: {{ $sent ? 'var(--signal-error)' : 'var(--signal-ok)' }};"></i>
                </div>
                <div class="transaction-details">
                    <p class="transaction-type">
                        {{ $label }}
                        <span class="text-mono" style="color: var(--text-mono); font-size: 0.85em;">{{ $isPrivate ? 'Tersembunyi' : Str::limit($counterparty, 18) }}</span>
                    </p>
                    <p class="transaction-date">{{ $transaction->created_at->format('d M Y · H:i') }}</p>
                </div>
                @if($isPrivate)
                    <div class="transaction-amount" title="Nominal disembunyikan demi privasi (hanya commitment on-chain)">••• MATIC</div>
                @else
                    <div class="transaction-amount {{ $sent ? 'negative' : 'positive' }}">
                        {{ $sent ? '−' : '+' }} {{ number_format($transaction->amount, 6, '.', '') }} MATIC
                    </div>
                @endif
                <div class="transaction-status">
                    <span class="badge badge--{{ $transaction->status == 'completed' ? 'ok' : ($transaction->status == 'pending' ? 'warn' : 'error') }}">
                        {{ strtoupper($transaction->status) }}
                    </span>
                    @if($isPrivate)
                        <span class="badge badge--proof" title="Transaksi Privat dengan ZK-SNARK">
                            <i data-lucide="lock"></i> PRIVATE
                        </span>
                    @else
                        <span class="badge badge--info">
                            <i data-lucide="globe"></i> PUBLIC
                        </span>
                    @endif
                </div>
            </div>
            @empty
            <div class="empty-state">
                <p>Belum ada transaksi. Mulai dengan menerima atau mengirim pembayaran.</p>
            </div>
            @endforelse
        </div>
    </section>

    <section class="info-cards">
        <article class="info-card">
            <div class="info-icon"><i data-lucide="shield"></i></div>
            <h3>Zero-Knowledge Proof</h3>
            <p>Transaksi privat dilindungi zk-SNARK Groth16 — penerima &amp; nominal tetap rahasia, validitas tetap dibuktikan on-chain.</p>
        </article>
        <article class="info-card">
            <div class="info-icon"><i data-lucide="link"></i></div>
            <h3>Polygon Amoy</h3>
            <p>Settlement on-chain di testnet 80002 untuk transparansi tanpa membongkar data sensitif pengguna.</p>
        </article>
        <article class="info-card">
            <div class="info-icon"><i data-lucide="qr-code"></i></div>
            <h3>QR Code P2P</h3>
            <p>Mode static (alamat wallet) dan dynamic (payment request berdurasi) untuk transaksi langsung antar-pengguna.</p>
        </article>
    </section>
</div>
@endsection

@push('scripts')
<meta name="user-email" content="{{ Auth::user()->email }}">
<script src="{{ asset('js/dashboard.js') }}"></script>
{{-- publish shieldPub yang dititip saat login (idempoten di server) --}}
<script>
document.addEventListener('DOMContentLoaded', function () {
    const pending = sessionStorage.getItem('xevou_pending_shield_pub');
    if (!pending) return;
    fetch('/pubkeys', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ shield_pub: pending }),
    }).then(function () {
        sessionStorage.removeItem('xevou_pending_shield_pub');
    }).catch(function () { /* coba lagi di load berikutnya */ });
});
</script>
@if(env('POLYGON_CONTRACT_ADDRESS'))
@vite(['resources/js/note-store.js', 'resources/js/pool-balance.js', 'resources/js/shield-key.js', 'resources/js/note-crypto.js', 'resources/js/polygon-transfer.js'])
<script>
document.addEventListener('DOMContentLoaded', function () {
    const card = document.getElementById('poolBalanceCard');
    const eyeBtn = document.getElementById('poolEyeBtn');
    const refreshBtn = document.getElementById('poolRefreshBtn');
    const amountEl = document.getElementById('poolBalanceAmount');
    const metaEl = document.getElementById('poolBalanceMeta');
    if (!card || !eyeBtn) return;

    // Antrian backup tertunda → coba kirim ulang (tak butuh password) + tandai UI.
    if (window.NoteBackup) window.NoteBackup.flushPending();
    window.addEventListener('backup-pending', function (e) {
        if (e.detail && e.detail.pending && metaEl) {
            metaEl.textContent = '⚠ ada backup note tertunda — akan dikirim ulang otomatis.';
        }
    });

    const MASK = '••••••••';
    const LIVE_INTERVAL = 15000; // 15 dtk — re-cek isCommitmentActive (tanpa password)
    // Nilai saldo hasil hitung disimpan di memori sesi ini saja. Reload halaman →
    // null lagi → tersembunyi & butuh password lagi (seperti saldo bank).
    let pool = null;     // { matic, activeCount, totalCount, rpcFailed }
    let cachedNotes = null; // [{commitment, amount_wei}] dari reveal pertama → re-tally live
    let visible = false;

    function setIcons() {
        eyeBtn.innerHTML = visible ? '<i data-lucide="eye-off"></i>' : '<i data-lucide="eye"></i>';
        eyeBtn.title = visible ? 'Sembunyikan saldo pool' : 'Tampilkan saldo pool';
        if (window.lucide) window.lucide.createIcons();
    }

    function render() {
        if (visible && pool) {
            amountEl.textContent = Number(pool.matic).toFixed(8);
            if (pool.totalCount === 0) {
                metaEl.textContent = 'Belum ada note. Lakukan deposit ke pool dulu.';
            } else if (pool.activeCount === 0 && !pool.rpcFailed) {
                metaEl.textContent = 'Tidak ada note aktif (semua sudah dibelanjakan/ditarik).';
            } else {
                metaEl.textContent = `dari ${pool.activeCount} note aktif`
                    + (pool.rpcFailed ? ' · ⚠ status on-chain tak terverifikasi penuh' : '');
            }
            card.dataset.state = 'revealed';
        } else {
            amountEl.textContent = MASK;
            metaEl.textContent = pool ? 'tersembunyi' : 'tersembunyi · tekan ikon mata untuk lihat';
            card.dataset.state = pool ? 'hidden' : 'locked';
        }
        setIcons();
    }

    async function ensureModule() {
        await new Promise(r => {
            if (window.PoolBalance) return r();
            let done = false;
            const finish = () => { if (!done) { done = true; r(); } };
            window.addEventListener('pool-balance-ready', finish, { once: true });
            const started = Date.now();
            const iv = setInterval(() => {
                if (window.PoolBalance || Date.now() - started > 30000) { clearInterval(iv); finish(); }
            }, 200);
        });
        return !!window.PoolBalance;
    }

    // Hitung saldo (butuh password — dekripsi note + cek on-chain). Return true jika sukses.
    async function compute() {
        const contractAddr = document.querySelector('meta[name="zk-payment-contract"]').content;
        if (!contractAddr || !/^0x[a-fA-F0-9]{40}$/.test(contractAddr)) {
            alert('POLYGON_CONTRACT_ADDRESS belum di-set di .env.');
            return false;
        }
        const email = document.querySelector('meta[name="user-email"]').content;
        const password = prompt('Masukkan password untuk dekripsi note di browser. Tidak dikirim ke server.');
        if (!password) return false;
        if (!window.AccountGuard || !window.AccountGuard.assertPassword(password)) return false;

        // Sinkron dua-arah (pull note dari server + sweep note lokal) — otomatis saat
        // password pertama kali dimasukkan. Best-effort: jangan blokir tampil saldo.
        try {
            if (window.NoteBackup) await window.NoteBackup.syncOnLogin(email, password);
        } catch (e) { console.warn('syncOnLogin gagal:', e.message); }

        card.dataset.state = 'loading';
        amountEl.textContent = MASK;
        metaEl.textContent = 'Mendekripsi note & cek on-chain…';

        if (!(await ensureModule())) {
            metaEl.textContent = 'Modul pool-balance belum siap. Tunggu beberapa detik / hard-refresh.';
            return false;
        }
        try {
            const res = await window.PoolBalance.computePoolBalance({ email, password, contractAddress: contractAddr });
            pool = {
                matic: res.total_matic,
                activeCount: res.active_count,
                totalCount: res.total_count,
                rpcFailed: res.rpc_failed,
            };
            cachedNotes = res.notes; // simpan utk re-tally live (tanpa salt/secret/password)
            return true;
        } catch (e) {
            metaEl.textContent = 'Gagal: ' + e.message;
            return false;
        }
    }

    // Re-tally berkala dari note yang sudah didekripsi — bikin saldo pool LIVE tanpa
    // minta password lagi. Hanya cek ulang isCommitmentActive (status on-chain).
    async function retally() {
        if (!cachedNotes || !visible || document.hidden) return;
        const contractAddr = document.querySelector('meta[name="zk-payment-contract"]').content;
        if (!contractAddr || !window.PoolBalance) return;
        try {
            const res = await window.PoolBalance.tallyActiveNotes({ notes: cachedNotes, contractAddress: contractAddr });
            pool = { matic: res.total_matic, activeCount: res.active_count, totalCount: res.total_count, rpcFailed: res.rpc_failed };
            cachedNotes = res.notes;
            render();
        } catch (e) {
            // Diam saja — refresh berikutnya coba lagi; nilai lama dipertahankan.
            console.warn('retally pool gagal:', e.message);
        }
    }

    setInterval(retally, LIVE_INTERVAL);
    window.addEventListener('xevou:refresh', retally);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) retally(); });

    // Tombol mata: pertama kali → hitung (password). Selanjutnya → toggle mask saja.
    eyeBtn.addEventListener('click', async function () {
        if (!pool) {
            const ok = await compute();
            if (!ok) { visible = false; render(); return; }
            visible = true;
            refreshBtn.style.display = '';
        } else {
            visible = !visible;
        }
        render();
    });

    // Tombol segarkan: hitung ulang (password lagi), lalu tampilkan.
    refreshBtn.addEventListener('click', async function () {
        const ok = await compute();
        if (ok) visible = true;
        render();
    });

    // Tombol cek transfer masuk: scan event EncryptedNote + trial-decrypt → simpan note baru.
    const scanBtn = document.getElementById('poolScanBtn');
    if (scanBtn) scanBtn.addEventListener('click', async function () {
        const contractAddr = document.querySelector('meta[name="zk-payment-contract"]').content;
        if (!contractAddr || !/^0x[a-fA-F0-9]{40}$/.test(contractAddr)) {
            alert('POLYGON_CONTRACT_ADDRESS belum di-set di .env.');
            return;
        }
        const email = document.querySelector('meta[name="user-email"]').content;
        const pw = prompt('Masukkan password untuk scan transfer masuk (decrypt di browser, tidak dikirim ke server).');
        if (!pw) return;
        if (!window.AccountGuard || !window.AccountGuard.assertPassword(pw)) return;
        card.dataset.state = 'loading';
        amountEl.textContent = MASK;
        metaEl.textContent = 'Memindai event transfer masuk…';
        await new Promise(r => { if (window.PolygonTransfer) return r(); window.addEventListener('polygon-transfer-ready', r, { once: true }); setTimeout(r, 8000); });
        if (!window.PolygonTransfer) { metaEl.textContent = 'Modul transfer belum siap, hard-refresh.'; return; }
        try {
            const deployBlock = Number(document.querySelector('meta[name="zk-payment-deploy-block"]')?.content || 0);
            // Meta berisi PATH relatif → tambah origin browser saat ini supaya scan
            // selalu menuju host yang sama dengan halaman (tahan-tunnel: Herd Share/HP
            // ikut domain publik, lokal ikut xevouzk.test).
            const rpcPath = document.querySelector('meta[name="zk-scan-rpc-url"]')?.content || '/payment/scan-rpc';
            const rpcUrl = rpcPath.startsWith('http') ? rpcPath : (window.location.origin + rpcPath);
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const res = await window.PolygonTransfer.scanIncomingNotes({
                email, password: pw, contractAddress: contractAddr,
                deployBlock, rpcUrl, csrfToken,
            });
            pool = null; visible = false; render();
            if (res.incomplete) {
                // Scan TIDAK selesai (RPC rate-limit/gagal di sebagian range). Jangan
                // klaim "0 note" palsu — beritahu user agar scan ulang (cursor sudah
                // aman: range yang gagal akan dipindai lagi, tidak hilang).
                metaEl.textContent = `${res.found} note ditemukan, tapi ${res.failedRanges} rentang gagal dipindai (RPC sibuk). Tekan "Cek Transfer Masuk" lagi untuk lanjut.`;
            } else {
                metaEl.textContent = `${res.found} note baru ditemukan (scan s/d blok ${res.scannedTo}). Tekan ikon mata untuk hitung ulang saldo.`;
            }
        } catch (e) {
            metaEl.textContent = 'Scan gagal: ' + e.message;
        }
    });
});
</script>
@endif
@endpush
