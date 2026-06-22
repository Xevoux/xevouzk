<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Login — XevouZK</title>

    <link rel="icon" type="image/png" href="{{ asset('LogoXevouZK.png') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;700&display=swap">

    @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/js/vendor-lucide.js'])
</head>
<body class="auth-body">
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <span class="auth-eyebrow">
                    <img src="{{ asset('LogoXevouZK.png') }}" alt="XevouZK" class="brand-logo brand-logo--sm">
                    XEVOUZK · LOGIN
                </span>
                <h1><i data-lucide="shield"></i> Akses Akun</h1>
                <p>Masuk untuk mengakses wallet dan riwayat transaksi Anda. Login memakai Schnorr — signature dibuat di browser, password tidak pernah dikirim ke server.</p>
            </div>

            @if(session('success'))
                <div class="alert alert-success">
                    <i data-lucide="check-circle"></i>
                    <span>{{ session('success') }}</span>
                </div>
            @endif
            @if(session('error'))
                <div class="alert alert-error">
                    <i data-lucide="alert-circle"></i>
                    <span>{{ session('error') }}</span>
                </div>
            @endif
            @if($errors->any())
                <div class="alert alert-error">
                    <i data-lucide="alert-circle"></i>
                    <span>{{ $errors->first() }}</span>
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}" class="auth-form" id="loginForm">
                @csrf

                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus placeholder="anda@example.com">
                </div>

                <div class="form-group">
                    {{-- Password TIDAK punya atribut name: dipakai hanya di browser untuk
                         men-derive keypair Schnorr; tidak pernah ikut dikirim ke server. --}}
                    <label for="password">Password</label>
                    <input type="password" id="password" autocomplete="current-password" required placeholder="••••••••">
                </div>

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="remember">
                        <span>Ingat saya di perangkat ini</span>
                    </label>
                </div>

                <div class="form-group" style="background: var(--bg-elevated); border: 1px dashed var(--purple-700); border-radius: var(--radius-md); padding: var(--s-3) var(--s-4); gap: var(--s-1);">
                    <strong>Schnorr Authentication</strong>
                    <small class="form-hint">Signature di-generate di browser dari email + password Anda. Password tidak pernah meninggalkan perangkat — server hanya menerima signature.</small>
                </div>

                <input type="hidden" name="schnorr_signature" id="schnorrSignature">
                <input type="hidden" name="schnorr_timestamp" id="schnorrTimestamp">

                <button type="submit" class="btn btn--primary btn--block btn--lg">
                    <i data-lucide="log-in"></i>
                    Login
                </button>

                <div class="auth-footer">
                    Belum punya akun? <a href="{{ route('register') }}">Daftar di sini →</a>
                </div>
            </form>
        </div>

        <aside class="zk-info-panel">
            <div class="zk-info-panel__intro">
                <div class="zk-info-panel__eyebrow">SCHNORR · SECP256K1 · CLIENT-SIDE</div>
                <h2 class="zk-info-panel__title">Login tanpa kirim password</h2>
                <p class="zk-info-panel__lede">
                    Browser men-derive keypair dari email+password Anda,
                    menandatangani timestamp anti-replay, dan hanya mengirim <em>signature</em> ke server.
                    Password tidak pernah meninggalkan perangkat.
                </p>

                <ol class="schnorr-steps" id="schnorrSteps">
                    <li class="schnorr-step" data-step="1">
                        <span class="schnorr-step__num"><span class="schnorr-step__num__digit">1</span></span>
                        <span class="schnorr-step__body">
                            <span class="schnorr-step__title">Derive keypair</span>
                            <span class="schnorr-step__hint">hash(email + password) → private key</span>
                        </span>
                    </li>
                    <li class="schnorr-step" data-step="2">
                        <span class="schnorr-step__num"><span class="schnorr-step__num__digit">2</span></span>
                        <span class="schnorr-step__body">
                            <span class="schnorr-step__title">Sign message</span>
                            <span class="schnorr-step__hint">sign(priv, email|timestamp|csrf)</span>
                        </span>
                    </li>
                    <li class="schnorr-step" data-step="3">
                        <span class="schnorr-step__num"><span class="schnorr-step__num__digit">3</span></span>
                        <span class="schnorr-step__body">
                            <span class="schnorr-step__title">Server verify</span>
                            <span class="schnorr-step__hint">verify(pub, sig, msg) → session</span>
                        </span>
                    </li>
                </ol>
            </div>

            <div class="process-logs">
                <h3><i data-lucide="terminal"></i> Process Logs</h3>
                <div id="loginLogs" class="logs-container">
                    <div class="log-entry log-info">
                        <span class="log-time">[00:00:00]</span>
                        <span class="log-message">System ready — waiting for login...</span>
                    </div>
                </div>
            </div>
        </aside>
    </div>

    {{-- Lucide icons dimuat via Vite (vendor-lucide.js di <head>). --}}

    @vite(['resources/js/schnorr-auth.js', 'resources/js/shield-key.js'])
    <script>
        function addLog(message, type = 'info') {
            const logsContainer = document.getElementById('loginLogs');
            const logEntry = document.createElement('div');
            logEntry.className = `log-entry log-${type}`;
            const now = new Date();
            const timeStr = now.toTimeString().split(' ')[0];
            logEntry.innerHTML = `
                <span class="log-time">[${timeStr}]</span>
                <span class="log-message">${message}</span>
            `;
            logsContainer.appendChild(logEntry);
            logsContainer.scrollTop = logsContainer.scrollHeight;
        }

        function setStep(stepNum, state) {
            const steps = document.querySelectorAll('#schnorrSteps .schnorr-step');
            steps.forEach((el) => {
                const n = parseInt(el.dataset.step, 10);
                el.classList.remove('is-active', 'is-done');
                if (n < stepNum) el.classList.add('is-done');
                else if (n === stepNum && state === 'active') el.classList.add('is-active');
                else if (n === stepNum && state === 'done') el.classList.add('is-done');
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            addLog('Login page loaded', 'success');
            addLog('Initializing Schnorr module...');
            window.addEventListener('schnorr-ready', () => {
                addLog('Schnorr module ready (secp256k1)', 'success');
            });

            @if(session('success'))
                addLog('{{ session('success') }}', 'success');
            @endif
            @if($errors->any())
                addLog('{{ $errors->first() }}', 'error');
            @endif
        });

        async function waitForSchnorr() {
            if (window.Schnorr) return;
            await new Promise(resolve => {
                window.addEventListener('schnorr-ready', resolve, { once: true });
                setTimeout(resolve, 5000);
            });
        }

        document.getElementById('loginForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            addLog('Schnorr authentication', 'warning');

            const email = document.getElementById('email').value.toLowerCase();
            const password = document.getElementById('password').value;
            if (!email || !password) {
                addLog('Error: Email and password required', 'error');
                alert('Email dan password harus diisi!');
                return;
            }

            await waitForSchnorr();
            if (!window.Schnorr) {
                addLog('Error: Schnorr module not loaded', 'error');
                alert('Schnorr module tidak tersedia. Silakan refresh halaman.');
                return;
            }

            try {
                setStep(1, 'active');
                const csrf = document.querySelector('meta[name="csrf-token"]').content;
                const ts = Math.floor(Date.now() / 1000);
                const message = `${email}|${ts}|${csrf}`;

                addLog('Deriving keypair from credentials...');
                const priv = window.Schnorr.derivePrivateKey(email, password);

                setStep(2, 'active');
                addLog('Signing message (anti-replay timestamp included)...');
                const sig = window.Schnorr.sign(priv, message);

                document.getElementById('schnorrSignature').value = sig;
                document.getElementById('schnorrTimestamp').value = String(ts);

                setStep(3, 'active');
                addLog('Signature generated (130 hex chars)', 'success');
                addLog('Password NOT transmitted — signature only', 'success');

                // derive shieldPub (publik) & titipkan ke sessionStorage
                // dipublish setelah login sukses (lihat dashboard). shieldPriv tak pernah disimpan.
                try {
                    if (!window.ShieldKey) {
                        await new Promise(r => {
                            window.addEventListener('shield-key-ready', r, { once: true });
                            setTimeout(r, 3000);
                        });
                    }
                    if (window.ShieldKey) {
                        const { shieldPub } = await window.ShieldKey.deriveShieldKeypair(email, password);
                        sessionStorage.setItem('xevou_pending_shield_pub', shieldPub.toString());
                    }
                } catch (e) { /* non-blocking */ }

                addLog('Submitting to server...');
                this.submit();
            } catch (err) {
                addLog('Error: ' + err.message, 'error');
                alert('Gagal generate Schnorr signature: ' + err.message);
            }
        });
    </script>
</body>
</html>
