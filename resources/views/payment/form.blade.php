@extends('layouts.app')

@section('title', 'Kirim Pembayaran — XevouZK')

@section('content')
<div class="payment-container">
    <header class="payment-header">
        <span class="page-header__eyebrow">PAYMENT · KIRIM</span>
        <h1><i data-lucide="send"></i> Kirim Pembayaran</h1>
        <p>Transfer non-custodial di Polygon Amoy. <strong>Plain</strong> = publik ke alamat 0x; <strong>Privat</strong> = lewat commitment pool (penerima &amp; nominal tersembunyi), tempel <em>viewing key</em> penerima.</p>
    </header>

    <div class="payment-grid">
        {{-- Payment form: tab Plain (publik) | Privat (pool) --}}
        <section class="payment-card">
            <div class="payment-tabs">
                <button class="tab-btn active" type="button" onclick="switchSendTab('plain')"><i data-lucide="globe"></i> Plain (Publik)</button>
                @if(env('POLYGON_CONTRACT_ADDRESS'))
                <button class="tab-btn" type="button" onclick="switchSendTab('private')"><i data-lucide="lock"></i> Privat (Pool)</button>
                @endif
            </div>

            <div id="send-plain" class="tab-content active">
                <h2><i data-lucide="pencil"></i> Transfer Manual (Plain)</h2>

                <form id="manualPaymentForm" class="payment-form">
                    @csrf
                    <div class="form-group">
                        <label for="receiver_address">Alamat Wallet Penerima (Polygon)</label>
                        <input type="text" id="receiver_address" name="receiver_address" required
                               pattern="^0x[a-fA-F0-9]{40}$"
                               placeholder="0x1234...abcd"
                               title="Format: 0x diikuti 40 karakter hex (Polygon EIP-55 address)">
                        <small class="form-hint">Non-custodial: hanya address Polygon (0x...) yang valid. Tx ditandatangani di browser dengan key Anda.</small>
                    </div>

                    <div class="form-group">
                        <label for="manual_amount">Jumlah (MATIC)</label>
                        <input type="number" id="manual_amount" name="amount" step="0.000001" min="0.000001" required placeholder="0.01">
                        <small class="form-hint">Polygon Amoy testnet. Sisakan ~0.001 MATIC untuk gas.</small>
                    </div>

                    <div class="form-group">
                        <label for="notes">Catatan (Opsional)</label>
                        <textarea id="notes" name="notes" rows="3" placeholder="Catatan untuk diri sendiri (tidak terkirim ke penerima)"></textarea>
                    </div>

                    <div class="alert alert-warning">
                        <i data-lucide="triangle-alert"></i>
                        <span>
                            <strong>Mode Plain (publik):</strong> alamat penerima &amp; nominal terlihat di Polygon explorer.
                            Tx ditandatangani di browser dengan key Anda (derive dari password) lalu di-relay via <code>/payment/relay</code>.
                            Untuk menyembunyikan penerima &amp; nominal, gunakan tab <strong>Privat</strong>.
                        </span>
                    </div>

                    <div class="wallet-balance-info">
                        <p>Saldo on-chain: <strong>{{ number_format((float) $wallet->balance, 6, '.', '') }} MATIC</strong></p>
                    </div>

                    <button type="submit" class="btn btn--primary btn--block btn--lg">
                        <i data-lucide="send"></i> Kirim Pembayaran
                    </button>
                </form>
            </div>

            @if(env('POLYGON_CONTRACT_ADDRESS'))
            <div id="send-private" class="tab-content">
                <h2><i data-lucide="lock"></i> Transfer Manual (Privat)</h2>

                <form id="privatePaymentForm" class="payment-form">
                    @csrf
                    <div class="form-group">
                        <label for="recipient_code">Kode Penerima (viewing key)</label>
                        <textarea id="recipient_code" rows="3" placeholder="Tempel kode dari penerima: xevouzk:private-transfer?zkpub=... (atau zkpub mentah)"></textarea>
                        <small class="form-hint">Penerima mendapatkannya dari menu <strong>Wallet → QR Privat</strong> (teks URI di bawah QR). Ini <em>bukan</em> alamat 0x.</small>
                    </div>

                    <div class="form-group">
                        <label for="private_amount">Jumlah (MATIC)</label>
                        <input type="number" id="private_amount" step="0.000001" min="0.000001" placeholder="0.01">
                        <small class="form-hint">Dibelanjakan dari satu note pool ≥ jumlah ini; sisanya jadi note kembalian.</small>
                    </div>

                    <div class="alert alert-info">
                        <i data-lucide="shield"></i>
                        <span>
                            <strong>Mode Privat (pool):</strong> penerima &amp; nominal <strong>tidak</strong> terlihat di explorer — hanya commitment + nullifier.
                            Butuh saldo di <strong>Pool Privat</strong> (deposit dulu di menu <a href="{{ route('wallet.index') }}">Wallet</a>). Proof Groth16 dibuat di browser (5–30 dtk).
                        </span>
                    </div>

                    <button type="submit" class="btn btn--primary btn--block btn--lg">
                        <i data-lucide="lock"></i> Generate Proof &amp; Kirim Privat
                    </button>
                </form>
            </div>
            @endif
        </section>

        {{-- Right sidebar: ringkasan wallet --}}
        <aside class="payment-side-info">
            <div class="wallet-info-mini">
                <p><strong>Saldo</strong> {{ number_format((float) $wallet->balance, 6, '.', '') }} MATIC</p>
                <p><strong>Wallet</strong> <code>{{ Str::limit($wallet->wallet_address, 24) }}</code></p>
                <p><strong>Network</strong> <code>POLYGON AMOY · 80002</code></p>
            </div>
        </aside>
    </div>
</div>
@endsection

@push('scripts')
<meta name="user-email" content="{{ Auth::user()->email }}">

@vite([
    'resources/js/polygon-key.js',
    'resources/js/payment-relay.js',
    'resources/js/xevou-uri.js',
    'resources/js/shield-key.js',
    'resources/js/note-store.js',
    'resources/js/note-crypto.js',
    'resources/js/polygon-transfer.js',
    'resources/js/private-send.js',
])
<script>
// Pindah antar tab Plain / Privat.
function switchSendTab(t) {
    document.querySelectorAll('.payment-card .tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.payment-card .tab-content').forEach(c => c.classList.remove('active'));
    const btn = document.querySelector(`.payment-card [onclick="switchSendTab('${t}')"]`);
    if (btn) btn.classList.add('active');
    const pane = document.getElementById('send-' + t);
    if (pane) pane.classList.add('active');
}

document.addEventListener('DOMContentLoaded', function() {
    const manualForm = document.getElementById('manualPaymentForm');
    if (!manualForm) return;

    const logsContainer = document.getElementById('paymentLogs');
    const log = (msg, type = 'info') => {
        if (!logsContainer) return;
        const el = document.createElement('div');
        el.className = `log-entry log-${type}`;
        el.innerHTML = `<span class="log-time">[${new Date().toTimeString().split(' ')[0]}]</span><span class="log-message">${msg}</span>`;
        logsContainer.appendChild(el);
        logsContainer.scrollTop = logsContainer.scrollHeight;
    };

    const waitMod = (name, evt) => new Promise(r => {
        if (window[name]) return r();
        window.addEventListener(evt, r, { once: true });
        setTimeout(r, 12000);
    });

    // --- Transfer privat (pool) ---
    const privateForm = document.getElementById('privatePaymentForm');
    if (privateForm) {
        // Auto-isi nominal dari viewing key: bila URI penerima menyertakan &amount=,
        // field "Jumlah" mengikuti otomatis. parseUri (xevou-uri.js) tersedia sebagai
        // window.XevouUri saat modul ter-bundle dimuat. Tidak menimpa angka yang
        // sudah diketik manual oleh user.
        const codeInput = document.getElementById('recipient_code');
        const amtInput = document.getElementById('private_amount');
        let amountAutoFilled = false;
        function syncAmountFromCode() {
            const parsed = window.XevouUri && window.XevouUri.parseUri(codeInput.value.trim());
            if (parsed && parsed.mode === 'private' && parsed.amount != null && String(parsed.amount) !== '') {
                if (amtInput.value === '' || amountAutoFilled) {
                    amtInput.value = parsed.amount;
                    amountAutoFilled = true;
                    amtInput.style.borderColor = 'var(--color-accent, #7c5cff)';
                    amtInput.setAttribute('title', 'Nominal mengikuti viewing key penerima — bisa diubah manual.');
                    log(`Nominal ${parsed.amount} MATIC diisi otomatis dari viewing key penerima.`, 'success');
                }
            }
        }
        // 'input' menangkap paste + ketik. Jalankan sekali untuk kasus value sudah terisi.
        codeInput.addEventListener('input', syncAmountFromCode);
        amtInput.addEventListener('input', () => {
            // User mengetik manual → lepaskan auto-follow + reset cue.
            amountAutoFilled = false;
            amtInput.style.borderColor = '';
            amtInput.removeAttribute('title');
        });
        syncAmountFromCode();

        privateForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            const code = document.getElementById('recipient_code').value.trim();
            const amount = document.getElementById('private_amount').value;
            if (!amount || parseFloat(amount) <= 0) { alert('Masukkan jumlah valid.'); return; }
            const contractAddr = document.querySelector('meta[name="zk-payment-contract"]').content;
            if (!contractAddr || !/^0x[a-fA-F0-9]{40}$/.test(contractAddr)) {
                alert('POLYGON_CONTRACT_ADDRESS belum di-set di .env.'); return;
            }

            const submitBtn = privateForm.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            log('Memuat modul transfer privat…', 'warning');
            await waitMod('PrivateSend', 'private-send-ready');
            await waitMod('PolygonTransfer', 'polygon-transfer-ready');
            if (!window.PrivateSend || !window.PolygonTransfer) {
                log('✗ Modul transfer privat belum siap', 'error');
                alert('Modul transfer privat belum siap (modul kripto besar). Tunggu beberapa detik / hard-refresh.');
                submitBtn.disabled = false; return;
            }

            // Parse kode penerima dulu — kasih error jelas sebelum minta password.
            let recipient;
            try {
                recipient = window.PrivateSend.parseRecipientCode(code);
            } catch (err) {
                log('✗ ' + err.message, 'error'); alert(err.message);
                submitBtn.disabled = false; return;
            }

            const email = document.querySelector('meta[name="user-email"]').content;
            const csrf = document.querySelector('meta[name="csrf-token"]').content;
            const password = prompt('Password untuk pilih note pool + sign transfer di browser ini.\nPassword tidak dikirim ke server.');
            if (!password) { submitBtn.disabled = false; return; }
            if (!window.AccountGuard || !window.AccountGuard.assertPassword(password)) { submitBtn.disabled = false; return; }

            log('Transfer privat dimulai (browser-only signing)…', 'warning');
            try {
                const r = await window.PrivateSend.sendPrivate({
                    email, password,
                    recipientShieldPub: recipient.shieldPub, recipientEncPub: recipient.encPub,
                    amountMatic: amount, contractAddress: contractAddr, csrfToken: csrf, log,
                });
                if (!r.success) {
                    log('✗ Transfer gagal: ' + (r.error || 'unknown'), 'error');
                    alert('Transfer privat gagal: ' + (r.error || 'unknown'));
                    return;
                }
                log('✓ Tx: ' + r.tx_hash, 'success');
                log('Note kembalian tersimpan di browser', 'success');
                window.dispatchEvent(new Event('xevou:refresh'));
                alert('Transfer privat berhasil! Tx: ' + r.tx_hash);
                window.location.href = '{{ route("dashboard") }}';
            } catch (err) {
                log('✗ ' + err.message, 'error');
                alert('Gagal transfer privat: ' + err.message);
            } finally {
                submitBtn.disabled = false;
            }
        });
    }

    manualForm.addEventListener('submit', async function(e) {
        e.preventDefault();

        const receiver = document.getElementById('receiver_address').value.trim();
        const amount = document.getElementById('manual_amount').value;
        if (!/^0x[a-fA-F0-9]{40}$/.test(receiver)) {
            alert('Alamat penerima harus format 0x... (Polygon address).');
            return;
        }

        const email = document.querySelector('meta[name="user-email"]').content;
        const csrf = document.querySelector('meta[name="csrf-token"]').content;
        const password = prompt('Masukkan password Anda untuk derive Polygon key di browser ini.\nPassword tidak dikirim ke server.');
        if (!password) return;
        if (!window.AccountGuard || !window.AccountGuard.assertPassword(password)) return;

        log('Non-custodial signing dimulai', 'warning');
        log('Derive Polygon key dari password (browser-only)...');

        if (!window.PaymentRelay) {
            await new Promise(r => { window.addEventListener('payment-relay-ready', r, { once: true }); setTimeout(r, 8000); });
        }
        if (!window.PaymentRelay) {
            log('Modul PaymentRelay gagal dimuat', 'error');
            alert('Modul signing gagal dimuat. Refresh halaman.');
            return;
        }

        try {
            log('Ambil nonce + fee Polygon Amoy...');
            const result = await window.PaymentRelay.signAndRelay({
                email, password, recipientAddress: receiver, amountMatic: amount, csrfToken: csrf,
            });
            if (!result.success) {
                log('✗ Relay gagal: ' + (result.error || 'unknown'), 'error');
                alert('Relay gagal: ' + (result.error || 'unknown'));
                return;
            }
            log('✓ Tx relayed: ' + result.tx_hash, 'success');

            // Catat ke riwayat (informatif; binding tetap on-chain). Best-effort.
            try {
                const notes = document.getElementById('notes')?.value || '';
                const recRes = await fetch('{{ route("payment.record-relay") }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ polygon_tx_hash: result.tx_hash, receiver_address: receiver, amount, notes }),
                });
                log(recRes.ok ? '✓ Tersimpan di riwayat' : '⚠ Gagal mencatat riwayat (tx tetap valid on-chain)', recRes.ok ? 'success' : 'warning');
            } catch (recErr) {
                log('⚠ Gagal mencatat riwayat: ' + recErr.message + ' (tx tetap valid on-chain)', 'warning');
            }

            alert('Tx terkirim! Hash: ' + result.tx_hash);
            window.location.href = '{{ route("dashboard") }}';
        } catch (err) {
            log('✗ Signing error: ' + err.message, 'error');
            alert('Gagal sign tx: ' + err.message);
        }
    });
});
</script>
@endpush
