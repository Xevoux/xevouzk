<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Daftar — XevouZK</title>

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
                    XEVOUZK · REGISTER
                </span>
                <h1><i data-lucide="user-plus"></i> Buat Akun</h1>
                <p>Buat wallet digital privat Anda. Keypair Schnorr di-derive lokal di browser dari email + password dan tidak pernah meninggalkan perangkat.</p>
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

            <form method="POST" action="{{ route('register') }}" class="auth-form" id="registerForm">
                @csrf

                <div class="form-group">
                    <label for="name">Nama Lengkap</label>
                    <input type="text" id="name" name="name" value="{{ old('name') }}" required autofocus placeholder="Nama Anda">
                    @error('name')
                        <span class="error-message">{{ $message }}</span>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}" required placeholder="anda@example.com">
                    @error('email')
                        <span class="error-message">{{ $message }}</span>
                    @enderror
                </div>

                <div class="form-group">
                    {{-- Password TIDAK punya atribut name: dipakai hanya di browser untuk
                         men-derive keypair; tidak pernah ikut dikirim ke server. --}}
                    <label for="password">Password</label>
                    <input type="password" id="password" autocomplete="new-password" minlength="8" required placeholder="Minimal 8 karakter">
                </div>

                <div class="form-group">
                    <label for="password_confirmation">Konfirmasi Password</label>
                    <input type="password" id="password_confirmation" autocomplete="new-password" minlength="8" required placeholder="Ulangi password">
                </div>

                <div class="form-group" style="background: var(--bg-elevated); border: 1px dashed var(--purple-700); border-radius: var(--radius-md); padding: var(--s-3) var(--s-4); gap: var(--s-1);">
                    <strong>Non-Custodial Wallet</strong>
                    <small class="form-hint">
                        Keypair Schnorr (auth) + keypair Polygon (wallet) di-derive deterministik dari email + password di browser ini.
                        Server hanya menerima public key dan alamat — tidak pernah private key. Password tidak bisa di-reset tanpa kehilangan akses wallet.
                    </small>
                </div>

                <input type="hidden" name="schnorr_public_key" id="schnorrPublicKey">
                <input type="hidden" name="polygon_address" id="polygonAddress">
                <input type="hidden" name="polygon_public_key" id="polygonPublicKey">

                <button type="submit" class="btn btn--primary btn--block btn--lg">
                    <i data-lucide="user-plus"></i>
                    Daftar
                </button>

                <div class="auth-footer">
                    Sudah punya akun? <a href="{{ route('login') }}">Login di sini →</a>
                </div>
            </form>
        </div>

        <aside class="zk-info-panel">
            <div class="zk-info-panel__intro">
                <div class="zk-info-panel__eyebrow">SCHNORR · SECP256K1 · CLIENT-SIDE</div>
                <h2 class="zk-info-panel__title">Keypair lahir di browser Anda</h2>
                <p class="zk-info-panel__lede">
                    Private key Anda di-derive deterministik dari email + password
                    di browser. Server hanya menerima public key — tidak pernah private key, tidak pernah password.
                </p>

                <ol class="schnorr-steps" id="schnorrSteps">
                    <li class="schnorr-step" data-step="1">
                        <span class="schnorr-step__num"><span class="schnorr-step__num__digit">1</span></span>
                        <span class="schnorr-step__body">
                            <span class="schnorr-step__title">Derive Schnorr key</span>
                            <span class="schnorr-step__hint">hash("schnorr_v1:" + email + ":" + pwd) mod n</span>
                        </span>
                    </li>
                    <li class="schnorr-step" data-step="2">
                        <span class="schnorr-step__num"><span class="schnorr-step__num__digit">2</span></span>
                        <span class="schnorr-step__body">
                            <span class="schnorr-step__title">Derive Polygon key + address</span>
                            <span class="schnorr-step__hint">addr = keccak256(pub)[-20:] → EIP-55</span>
                        </span>
                    </li>
                    <li class="schnorr-step" data-step="3">
                        <span class="schnorr-step__num"><span class="schnorr-step__num__digit">3</span></span>
                        <span class="schnorr-step__body">
                            <span class="schnorr-step__title">Register public artifacts</span>
                            <span class="schnorr-step__hint">POST { schnorr_pub, polygon_addr, polygon_pub }</span>
                        </span>
                    </li>
                </ol>
            </div>

            <div class="process-logs">
                <h3><i data-lucide="terminal"></i> Process Logs</h3>
                <div id="registerLogs" class="logs-container">
                    <div class="log-entry log-info">
                        <span class="log-time">[00:00:00]</span>
                        <span class="log-message">System ready — waiting for registration...</span>
                    </div>
                </div>
            </div>
        </aside>
    </div>

    {{-- Lucide icons dimuat via Vite (vendor-lucide.js di <head>). --}}

    @vite(['resources/js/schnorr-auth.js', 'resources/js/polygon-key.js'])
    <script>
        function addLog(message, type = 'info') {
            const logsContainer = document.getElementById('registerLogs');
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
            addLog('Registration page loaded', 'success');
            addLog('Initializing crypto modules (Schnorr + Polygon)...');
            window.addEventListener('schnorr-ready', () => {
                addLog('Schnorr module ready (secp256k1)', 'success');
            });
            window.addEventListener('polygon-key-ready', () => {
                addLog('Polygon key module ready (secp256k1 + keccak256)', 'success');
            });
        });

        async function waitForModules() {
            const wait = (predicate, eventName) => {
                if (predicate()) return;
                return new Promise(resolve => {
                    window.addEventListener(eventName, resolve, { once: true });
                    setTimeout(resolve, 5000);
                });
            };
            await wait(() => !!window.Schnorr, 'schnorr-ready');
            await wait(() => !!window.PolygonKey, 'polygon-key-ready');
        }

        document.getElementById('registerForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            addLog('Non-custodial registration mode', 'warning');

            const email = document.getElementById('email').value.toLowerCase();
            const password = document.getElementById('password').value;
            const passwordConfirm = document.getElementById('password_confirmation').value;

            if (password.length < 8) {
                addLog('Error: Password minimal 8 karakter', 'error');
                alert('Password minimal 8 karakter!');
                return;
            }

            if (password !== passwordConfirm) {
                addLog('Error: Passwords do not match', 'error');
                alert('Password dan konfirmasi password tidak sama!');
                return;
            }

            await waitForModules();
            if (!window.Schnorr || !window.PolygonKey) {
                addLog('Error: crypto module belum siap', 'error');
                alert('Modul kripto gagal dimuat. Silakan refresh halaman.');
                return;
            }

            try {
                setStep(1, 'active');
                addLog('Deriving Schnorr keypair...');
                const schnorrPriv = window.Schnorr.derivePrivateKey(email, password);
                const schnorrPub = window.Schnorr.derivePublicKey(schnorrPriv);
                document.getElementById('schnorrPublicKey').value = schnorrPub;
                addLog('Schnorr pub: ' + schnorrPub.substring(0, 16) + '...', 'success');

                setStep(2, 'active');
                addLog('Deriving Polygon keypair + EIP-55 address...');
                const polygon = window.PolygonKey.deriveWallet(email, password);
                document.getElementById('polygonAddress').value = polygon.address;
                document.getElementById('polygonPublicKey').value = polygon.publicKey;
                addLog('Polygon addr: ' + polygon.address, 'success');

                setStep(3, 'active');
                addLog('Submitting registration (public artifacts only)...');
                this.submit();
            } catch (err) {
                addLog('Error: ' + err.message, 'error');
                alert('Gagal derive keypair: ' + err.message);
            }
        });
    </script>
</body>
</html>
