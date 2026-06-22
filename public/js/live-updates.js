/*
 * live-updates.js — refresh dinamis saldo/jaringan/riwayat tanpa reload.
 *
 * Satu poller men-fetch GET /live/state lalu meng-update elemen DOM yang ada di
 * halaman aktif (di-guard). Cadence "Seimbang"
 * - chain=0 (DB-only) tiap 10 dtk
 * - chain=1 (sync on-chain) tiap 20 dtk
 * Plus refresh instan via event 'xevou:refresh' atau window.LiveUpdates.refresh.
 *
 * Plain script (bukan module), dimuat global di layouts/app.blade.php.
 */
(function () {
    'use strict';

    var ENDPOINT = '/live/state';
    var DB_INTERVAL = 10000;    // 10 dtk — baca DB murah
    var CHAIN_INTERVAL = 20000; // 20 dtk — sync RPC

    var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                  'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    var busy = false;
    var lastTxSignature = null;

    function qsa(sel) { return Array.prototype.slice.call(document.querySelectorAll(sel)); }
    function qs(sel) { return document.querySelector(sel); }

    function rerenderIcons() {
        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }
    }

    // --- formatters

    function fmtBalance(v) {
        var n = Number(v);
        return isNaN(n) ? '0.00000000' : n.toFixed(8);
    }

    function fmtAmount(v) {
        var n = Number(v);
        if (isNaN(n)) n = 0;
        return n.toFixed(6);
    }

    function fmtDateTime(iso) {
        if (!iso) return '';
        var d = new Date(iso);
        if (isNaN(d.getTime())) return '';
        var pad = function (x) { return x < 10 ? '0' + x : '' + x; };
        return pad(d.getDate()) + ' ' + MONTHS[d.getMonth()] + ' ' + d.getFullYear() +
               ' · ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    function relativeTime(iso) {
        if (!iso) return null;
        var d = new Date(iso);
        if (isNaN(d.getTime())) return null;
        var sec = Math.max(0, Math.floor((Date.now() - d.getTime()) / 1000));
        if (sec < 60) return 'beberapa detik lalu';
        var min = Math.floor(sec / 60);
        if (min < 60) return min + ' menit lalu';
        var hr = Math.floor(min / 60);
        if (hr < 24) return hr + ' jam lalu';
        return Math.floor(hr / 24) + ' hari lalu';
    }

    function truncate(s, n) {
        if (!s) return '';
        return s.length > n ? s.slice(0, n) + '...' : s;
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    // --- DOM updates

    function updateBalance(state) {
        // Kecualikan elemen yang dikelola modul lain (mis. saldo pool privat
        // yang dihitung client-side oleh pool-balance.js) agar tidak ketimpa.
        qsa('.balance-amount:not([data-live-ignore])').forEach(function (el) {
            el.textContent = fmtBalance(state.balance);
        });
    }

    function updateNetwork(online) {
        qsa('[data-live="network"]').forEach(function (el) {
            el.classList.remove('status-pill--connected', 'status-pill--stale', 'status-pill--offline');
            el.classList.add(online ? 'status-pill--connected' : 'status-pill--offline');
            el.setAttribute('title', online ? 'Polygon Amoy Testnet — terhubung' : 'Polygon RPC tidak terhubung');
        });
    }

    function ensureBanner() {
        var banner = qs('#balanceStaleBanner');
        if (banner) return banner;
        var main = qs('.main-content');
        if (!main) return null;
        banner = document.createElement('div');
        banner.id = 'balanceStaleBanner';
        main.insertBefore(banner, main.firstChild);
        return banner;
    }

    function updateBanner(state) {
        var staleness = state.staleness;
        var existing = qs('#balanceStaleBanner');

        if (staleness === 'fresh') {
            if (existing) existing.style.display = 'none';
            return;
        }

        var banner = existing || ensureBanner();
        if (!banner) return;

        var offline = staleness === 'offline';
        banner.className = 'alert ' + (offline ? 'alert-error' : 'alert-warning');
        banner.style.display = '';

        var rel = relativeTime(state.last_sync);
        var syncTxt = rel
            ? 'Last sync: <span class="text-mono">' + escapeHtml(rel) + '</span>.'
            : 'Belum pernah sync dengan Polygon RPC.';

        banner.innerHTML =
            '<i data-lucide="' + (offline ? 'wifi-off' : 'triangle-alert') + '"></i>' +
            '<span><strong>Saldo on-chain ' +
            (offline ? 'belum tersinkron' : 'mungkin tidak terkini') + '.</strong> ' +
            syncTxt +
            ' Saldo yang ditampilkan adalah cache database, bukan saldo real-time.</span>';
        rerenderIcons();
    }

    function updateFaucet(state) {
        var btn = qs('#requestTestMaticBtn');
        var cooldown = qs('#faucetCooldown');
        if (!btn && !cooldown) return;

        var f = state.faucet || {};
        if (f.can_request) {
            if (btn) btn.disabled = false;
            if (cooldown) { cooldown.textContent = 'Ready'; cooldown.style.color = '#10b981'; }
        } else {
            if (btn) btn.disabled = true;
            if (cooldown) {
                var s = Number(f.retry_after) || 0;
                var h = Math.floor(s / 3600);
                var m = Math.floor((s % 3600) / 60);
                cooldown.textContent = h + 'h ' + m + 'm';
                cooldown.style.color = '#fbbf24';
            }
        }
    }

    function txRow(tx) {
        var sent = tx.direction === 'sent';
        var priv = !!tx.is_private;
        var icon = sent ? 'arrow-up' : 'arrow-down';
        var iconColor = sent ? 'var(--signal-error)' : 'var(--signal-ok)';
        // Privat: counterparty disembunyikan; label tetap menunjukkan arah.
        var label = priv ? (sent ? 'Kirim privat' : 'Terima privat') : (sent ? 'Kirim ke' : 'Terima dari');
        var counter = priv ? 'Tersembunyi' : truncate(tx.counterparty || '—', 18);
        var amountClass = sent ? 'negative' : 'positive';
        var sign = sent ? '−' : '+';
        var statusBadge = tx.status === 'completed' ? 'ok' : (tx.status === 'pending' ? 'warn' : 'error');
        var privacy = priv
            ? '<span class="badge badge--proof" title="Transaksi Privat dengan ZK-SNARK"><i data-lucide="lock"></i> PRIVATE</span>'
            : '<span class="badge badge--info"><i data-lucide="globe"></i> PUBLIC</span>';
        // Nominal disembunyikan untuk transaksi privat (hanya commitment on-chain).
        var amountHtml = priv
            ? '<div class="transaction-amount" title="Nominal disembunyikan demi privasi">••• MATIC</div>'
            : '<div class="transaction-amount ' + amountClass + '">' + sign + ' ' + fmtAmount(tx.amount) + ' MATIC</div>';

        return '' +
            '<div class="transaction-item ' + (sent ? 'sent' : 'received') + '">' +
                '<div class="transaction-icon"><i data-lucide="' + icon + '" style="color: ' + iconColor + ';"></i></div>' +
                '<div class="transaction-details">' +
                    '<p class="transaction-type">' + label +
                        ' <span class="text-mono" style="color: var(--text-mono); font-size: 0.85em;">' +
                        escapeHtml(counter) + '</span></p>' +
                    '<p class="transaction-date">' + escapeHtml(fmtDateTime(tx.created_at)) + '</p>' +
                '</div>' +
                amountHtml +
                '<div class="transaction-status">' +
                    '<span class="badge badge--' + statusBadge + '">' + escapeHtml(String(tx.status).toUpperCase()) + '</span>' +
                    privacy +
                '</div>' +
            '</div>';
    }

    function updateTransactions(state) {
        var container = qs('[data-live="transactions"]');
        if (!container) return;

        var txs = state.transactions || [];
        var sig = JSON.stringify(txs.map(function (t) { return [t.id, t.status]; }));
        if (sig === lastTxSignature) return; // tidak ada perubahan → jangan re-render
        lastTxSignature = sig;

        if (txs.length === 0) {
            container.innerHTML = '<div class="empty-state"><p>Belum ada transaksi. ' +
                'Mulai dengan menerima atau mengirim pembayaran.</p></div>';
            return;
        }

        container.innerHTML = txs.map(txRow).join('');
        rerenderIcons();
    }

    function apply(state) {
        if (!state || typeof state !== 'object') return;
        updateBalance(state);
        if (typeof state.staleness !== 'undefined') updateBanner(state);
        if (state.network) updateNetwork(!!state.network.online);
        updateFaucet(state);
        updateTransactions(state);
    }

    // --- polling

    function poll(chain) {
        if (busy) return;
        if (document.hidden) return; // hemat RPC saat tab tidak aktif
        busy = true;

        fetch(ENDPOINT + '?chain=' + (chain ? '1' : '0'), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
            .then(function (state) { apply(state); })
            .catch(function () { updateNetwork(false); }) // fetch gagal → badge offline, nilai lama dipertahankan
            .then(function () { busy = false; });
    }

    function hasLiveTargets() {
        return !!(qs('.balance-amount') || qs('[data-live]') || qs('#requestTestMaticBtn'));
    }

    function start() {
        if (!hasLiveTargets()) return; // skip halaman tanpa target (login/register)

        poll(true); // sync awal langsung
        setInterval(function () { poll(false); }, DB_INTERVAL);
        setInterval(function () { poll(true); }, CHAIN_INTERVAL);

        // refresh saat tab kembali aktif
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) poll(true);
        });

        // refresh instan setelah aksi (deposit / kirim / faucet)
        window.addEventListener('xevou:refresh', function () { poll(true); });
    }

    window.LiveUpdates = { refresh: function () { poll(true); } };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
