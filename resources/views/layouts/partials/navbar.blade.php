@auth
<nav class="navbar">
    <div class="navbar-brand">
        <img src="{{ asset('LogoXevouZK.png') }}" alt="XevouZK" class="brand-logo">
        <h2>XevouZK</h2>
    </div>

    <div class="navbar-menu">
        <a href="{{ route('dashboard') }}" class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}">
            <i data-lucide="house"></i>
            <span>Dashboard</span>
        </a>
        <a href="{{ route('wallet.index') }}" class="nav-link {{ request()->routeIs('wallet.*') ? 'active' : '' }}">
            <i data-lucide="wallet"></i>
            <span>Wallet</span>
        </a>
        <a href="{{ route('payment.form') }}" class="nav-link {{ request()->routeIs('payment.form') ? 'active' : '' }}">
            <i data-lucide="send"></i>
            <span>Transfer</span>
        </a>
        <a href="{{ route('payment.scan') }}" class="nav-link {{ request()->routeIs('payment.scan') ? 'active' : '' }}">
            <i data-lucide="qr-code"></i>
            <span>Scan</span>
        </a>
        <a href="{{ route('payment.history') }}" class="nav-link {{ request()->routeIs('payment.history') ? 'active' : '' }}">
            <i data-lucide="history"></i>
            <span>History</span>
        </a>
    </div>

    <div class="navbar-user">
        @php($ns = $networkStatus ?? ['state' => 'connected', 'label' => 'AMOY', 'chain_id' => 80002, 'tooltip' => 'Polygon Amoy Testnet'])
        <span class="status-pill status-pill--{{ $ns['state'] }}" title="{{ $ns['tooltip'] }}" data-live="network">
            <span class="status-pill__dot"></span>
            <span class="status-pill__label">{{ $ns['label'] }}</span>
            <span class="status-pill__sep">·</span>
            <span>{{ $ns['chain_id'] }}</span>
        </span>

        <div class="profile-dropdown">
            <button class="profile-trigger" id="profileTrigger" type="button" aria-haspopup="true" aria-expanded="false" onclick="toggleProfileDropdown()">
                <span class="profile-avatar">
                    <i data-lucide="circle-user"></i>
                </span>
                <span class="profile-info">
                    <span class="profile-name">{{ Auth::user()->name }}</span>
                    <span class="profile-email">{{ Auth::user()->email }}</span>
                </span>
                <i data-lucide="chevron-down" class="profile-arrow"></i>
            </button>

            <div class="profile-menu" id="profileDropdown" role="menu">
                <div class="profile-menu-header">
                    <span class="profile-menu-avatar">
                        <i data-lucide="circle-user"></i>
                    </span>
                    <div class="profile-menu-info">
                        <strong>{{ Auth::user()->name }}</strong>
                        <span>{{ Auth::user()->email }}</span>
                    </div>
                </div>

                <a href="{{ route('dashboard') }}" class="profile-menu-item">
                    <i data-lucide="house"></i>
                    <span>Dashboard</span>
                </a>
                <a href="{{ route('wallet.index') }}" class="profile-menu-item">
                    <i data-lucide="wallet"></i>
                    <span>My Wallet</span>
                </a>
                <a href="{{ route('payment.history') }}" class="profile-menu-item">
                    <i data-lucide="history"></i>
                    <span>Riwayat Transaksi</span>
                </a>

                <div class="profile-menu-divider"></div>

                <a href="#" class="profile-menu-item" onclick="event.preventDefault(); document.getElementById('profile-settings-modal').classList.add('active');">
                    <i data-lucide="settings"></i>
                    <span>Pengaturan</span>
                </a>
                <a href="#" class="profile-menu-item" onclick="event.preventDefault(); document.getElementById('about-modal').classList.add('active');">
                    <i data-lucide="info"></i>
                    <span>Tentang XevouZK</span>
                </a>

                <div class="profile-menu-divider"></div>

                <form action="{{ route('logout') }}" method="POST" class="profile-menu-form">
                    @csrf
                    <button type="submit" class="profile-menu-item logout">
                        <i data-lucide="log-out"></i>
                        <span>Keluar</span>
                    </button>
                </form>
            </div>
        </div>
    </div>
</nav>

{{-- Profile Settings Modal --}}
<div id="profile-settings-modal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i data-lucide="settings"></i> Pengaturan Profil</h2>
            <button class="modal-close" onclick="document.getElementById('profile-settings-modal').classList.remove('active')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="detail-section">
                <h3>Identitas</h3>
                <div class="confirmation-details" style="background: var(--bg-elevated); border: 1px solid var(--border-soft); border-radius: var(--radius-md); padding: var(--s-4);">
                    <div class="detail-row">
                        <label>Nama</label>
                        <span>{{ Auth::user()->name }}</span>
                    </div>
                    <div class="detail-row">
                        <label>Email</label>
                        <span>{{ Auth::user()->email }}</span>
                    </div>
                    <div class="detail-row">
                        <label>Status</label>
                        <span class="badge badge--ok"><i data-lucide="check-circle"></i> Terverifikasi</span>
                    </div>
                </div>
            </div>

            <div class="detail-section">
                <h3>Keamanan</h3>
                <div class="confirmation-details" style="background: var(--bg-elevated); border: 1px solid var(--border-soft); border-radius: var(--radius-md); padding: var(--s-4);">
                    <div class="detail-row">
                        <label>Password</label>
                        <span>{{ Auth::user()->updated_at->diffForHumans() }}</span>
                    </div>
                    <div class="detail-row">
                        <label>Two-Factor</label>
                        <span class="badge badge--info">Coming soon</span>
                    </div>
                </div>
            </div>

            <div class="alert alert-info">
                <i data-lucide="info"></i>
                <span>Pengaturan lanjutan akan tersedia setelah Schnorr keypair rotation diaktifkan.</span>
            </div>
        </div>
    </div>
</div>

{{-- About Modal --}}
<div id="about-modal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i data-lucide="info"></i> Tentang XevouZK</h2>
            <button class="modal-close" onclick="document.getElementById('about-modal').classList.remove('active')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="detail-section">
                <h3>Versi</h3>
                <p style="font-family: var(--font-mono); color: var(--text-primary);">v1.0.0 · POLYGON AMOY (80002)</p>
            </div>

            <div class="detail-section">
                <h3>Kapabilitas</h3>
                <ul style="list-style: none; padding: 0; display: grid; gap: var(--s-2);">
                    <li style="display: flex; align-items: center; gap: var(--s-2); color: var(--text-secondary);"><i data-lucide="shield" style="color: var(--purple-400);"></i> Autentikasi Schnorr (secp256k1)</li>
                    <li style="display: flex; align-items: center; gap: var(--s-2); color: var(--text-secondary);"><i data-lucide="lock" style="color: var(--purple-400);"></i> zk-SNARK Groth16 untuk transaksi privat</li>
                    <li style="display: flex; align-items: center; gap: var(--s-2); color: var(--text-secondary);"><i data-lucide="link" style="color: var(--purple-400);"></i> Settlement on-chain Polygon</li>
                    <li style="display: flex; align-items: center; gap: var(--s-2); color: var(--text-secondary);"><i data-lucide="qr-code" style="color: var(--purple-400);"></i> QR Code P2P (static + dynamic)</li>
                </ul>
            </div>

            <div class="detail-section">
                <h3>Stack</h3>
                <div style="display: flex; flex-wrap: wrap; gap: var(--s-2);">
                    <span class="badge badge--proof">Laravel 12</span>
                    <span class="badge badge--proof">Circom + snarkjs</span>
                    <span class="badge badge--proof">Hardhat</span>
                    <span class="badge badge--proof">Polygon Amoy</span>
                    <span class="badge badge--proof">Raw CSS</span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function toggleProfileDropdown() {
    const dropdown = document.getElementById('profileDropdown');
    const trigger = document.getElementById('profileTrigger');
    const isOpen = dropdown.classList.toggle('active');
    if (trigger) {
        trigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }
}

document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('profileDropdown');
    const trigger = document.getElementById('profileTrigger');
    if (dropdown && trigger && !trigger.contains(event.target) && !dropdown.contains(event.target)) {
        dropdown.classList.remove('active');
        trigger.setAttribute('aria-expanded', 'false');
    }
});

// Close modals when clicking outside
window.addEventListener('click', function(event) {
    ['profile-settings-modal', 'about-modal'].forEach(function(id) {
        const modal = document.getElementById(id);
        if (modal && event.target === modal) {
            modal.classList.remove('active');
        }
    });
});

// Escape key closes modals
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        document.querySelectorAll('.modal.active').forEach(function(m) {
            m.classList.remove('active');
        });
    }
});
</script>
@endauth
