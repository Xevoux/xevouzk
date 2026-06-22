// QR Scanner — mode-aware. Mode ditentukan oleh QR (xevouzk:), bukan checkbox.
import { Html5Qrcode, Html5QrcodeScannerState } from 'html5-qrcode';
import { parseUri } from './xevou-uri.js';
import { unpackZkpub } from './shield-key.js';
import { sendPrivate } from './private-send.js';

let html5QrCode, scanned = null;

function log(msg, type = 'info') {
    const c = document.getElementById('scanLogs'); if (!c) return;
    const el = document.createElement('div'); el.className = `log-entry log-${type}`;
    el.innerHTML = `<span class="log-time">[${new Date().toTimeString().split(' ')[0]}]</span><span class="log-message">${msg}</span>`;
    c.appendChild(el); c.scrollTop = c.scrollHeight;
}
const meta = n => document.querySelector(`meta[name="${n}"]`)?.content;
const el = id => document.getElementById(id);

function initScanner() {
    if (typeof Html5Qrcode === 'undefined') { log('✗ QR lib not loaded', 'error'); return; }
    html5QrCode = new Html5Qrcode('reader');
    html5QrCode.start({ facingMode: 'environment' }, { fps: 10, qrbox: { width: 250, height: 250 } }, onScan, () => {})
        .then(() => log('✓ Kamera aktif — arahkan ke QR XevouZK', 'success'))
        .catch(err => { log('✗ Kamera error: ' + err.message, 'error'); const r = el('reader'); if (r) r.innerHTML = '<p class="error-message">Kamera tidak tersedia. Gunakan input manual.</p>'; });
}
function onScan(text) { log('=== QR TERDETEKSI ===', 'success'); stopScanner(); processQR(text); }

function processQR(text) {
    const parsed = parseUri(text);
    if (!parsed) { log('✗ QR tidak dikenali (butuh QR XevouZK)', 'error'); alert('QR tidak dikenali. Minta QR XevouZK (plain/privat).'); return cancelPayment(); }

    if (parsed.mode === 'plain') {
        scanned = { mode: 'plain', to: parsed.to, amount: parsed.amount ? parseFloat(parsed.amount) : null };
        if (scanned.amount == null) { const a = prompt('Jumlah pembayaran (MATIC):'); if (!a || parseFloat(a) <= 0) return cancelPayment(); scanned.amount = parseFloat(a); }
        showConfirm('plain', { recipient: scanned.to, amount: scanned.amount });
        log(`Mode PLAIN → ${scanned.to.slice(0, 12)}… ${scanned.amount} MATIC`);
    } else {
        let keys; try { keys = unpackZkpub(parsed.zkpub); } catch (e) { log('✗ zkpub invalid: ' + e.message, 'error'); alert('zkpub tidak valid.'); return cancelPayment(); }
        scanned = { mode: 'private', shieldPub: keys.shieldPub, encPub: keys.encPub, amount: parsed.amount ? parseFloat(parsed.amount) : null };
        if (scanned.amount == null) { const a = prompt('Jumlah transfer privat (MATIC):'); if (!a || parseFloat(a) <= 0) return cancelPayment(); scanned.amount = parseFloat(a); }
        showConfirm('private', { amount: scanned.amount });
        log(`Mode PRIVAT → penerima tersembunyi, ${scanned.amount} MATIC`);
    }
}

function showConfirm(mode, info) {
    document.querySelector('.scanner-card').style.display = 'none';
    el('paymentConfirmation').style.display = 'block';
    const badge = el('modeBadge');
    if (mode === 'plain') {
        badge.className = 'badge badge--info'; badge.innerHTML = '<i data-lucide="globe"></i> PLAIN · PUBLIK';
        el('confirmReceiverRow').style.display = '';
        el('confirmReceiverAddress').textContent = info.recipient;
        el('privateNoteRow').style.display = 'none';
    } else {
        badge.className = 'badge badge--proof'; badge.innerHTML = '<i data-lucide="lock"></i> PRIVAT';
        el('confirmReceiverRow').style.display = 'none';
        el('privateNoteRow').style.display = '';
    }
    el('confirmAmount').textContent = parseFloat(info.amount).toFixed(6) + ' MATIC';
    if (window.lucide) window.lucide.createIcons();
}

async function confirmPayment() {
    if (!scanned) { alert('Tidak ada data pembayaran'); return; }
    const email = meta('user-email');
    const csrf = meta('csrf-token');
    const amount = scanned.amount;
    if (scanned.mode === 'plain') return confirmPlain(email, csrf, amount);
    return confirmPrivate(email, csrf, amount);
}

async function confirmPlain(email, csrf, amount) {
    const balance = parseFloat(el('userBalance')?.dataset.balance || '0');
    log('--- CEK SALDO ON-CHAIN ---');
    if (amount > balance) { log('✗ Saldo on-chain tidak cukup', 'error'); alert('Saldo on-chain tidak mencukupi!'); return; }
    log('✓ Saldo cukup', 'success');
    const pw = prompt('Password untuk sign tx (browser-only, tidak dikirim ke server):'); if (!pw) return;
    if (!window.AccountGuard || !window.AccountGuard.assertPassword(pw)) return;
    await waitFor('PaymentRelay', 'payment-relay-ready');
    if (!window.PaymentRelay) { alert('Modul relay belum siap.'); return; }
    try {
        log('Sign + relay tx publik…', 'warning');
        const r = await window.PaymentRelay.signAndRelay({ email, password: pw, recipientAddress: scanned.to, amountMatic: String(amount), csrfToken: csrf });
        if (!r.success) { log('✗ Relay gagal: ' + (r.error || 'unknown'), 'error'); alert('Gagal: ' + (r.error || 'unknown')); return; }
        log('✓ Tx: ' + r.tx_hash, 'success');
        try { await fetch(meta('record-relay-url'), { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify({ polygon_tx_hash: r.tx_hash, receiver_address: scanned.to, amount: String(amount), notes: '' }) }); } catch {}
        finishOk(r.tx_hash);
    } catch (e) { log('✗ ' + e.message, 'error'); alert('Gagal sign tx: ' + e.message); }
}

async function confirmPrivate(email, csrf, amount) {
    const contractAddr = meta('zk-payment-contract');
    if (!contractAddr) { alert('POLYGON_CONTRACT_ADDRESS belum di-set.'); return; }
    const pw = prompt('Password untuk pilih note pool + sign transfer (browser-only):'); if (!pw) return;
    if (!window.AccountGuard || !window.AccountGuard.assertPassword(pw)) return;
    log('--- CEK SALDO POOL ---', 'warning');
    await waitFor('PolygonTransfer', 'polygon-transfer-ready');
    if (!window.PolygonTransfer) { alert('Modul transfer belum siap, hard-refresh.'); return; }
    try {
        const r = await sendPrivate({
            email, password: pw,
            recipientShieldPub: scanned.shieldPub, recipientEncPub: scanned.encPub,
            amountMatic: amount, contractAddress: contractAddr, csrfToken: csrf, log,
        });
        if (r.note_matic) el('selectedNoteInfo').textContent = `Note ${r.note_matic} MATIC`;
        if (!r.success) { log('✗ Transfer gagal: ' + (r.error || 'unknown'), 'error'); alert('Transfer privat gagal: ' + (r.error || 'unknown')); return; }
        log('✓ Tx: ' + r.tx_hash, 'success'); log('Note kembalian tersimpan di browser', 'success');
        window.dispatchEvent(new Event('xevou:refresh'));
        finishOk(r.tx_hash);
    } catch (e) { log('✗ ' + e.message, 'error'); alert('Gagal transfer privat: ' + e.message); }
}

function finishOk(txHash) { log('=== SELESAI ===', 'success'); setTimeout(() => { alert('Pembayaran berhasil! Tx: ' + txHash); window.location.href = meta('dashboard-url'); }, 800); }
function waitFor(globalName, evt) { return new Promise(r => { if (window[globalName]) return r(); window.addEventListener(evt, r, { once: true }); setTimeout(r, 8000); }); }

function cancelPayment() { scanned = null; el('paymentConfirmation').style.display = 'none'; document.querySelector('.scanner-card').style.display = 'block'; initScanner(); }
function stopScanner() {
    if (!html5QrCode) return;
    // html5-qrcode melempar SINKRON ("Cannot stop, scanner is not running or
    // paused") bila state bukan SCANNING/PAUSED — mis. saat kamera gagal start
    // (initScanner catch) lalu beforeunload/cancel tetap memanggil stop. Karena
    // lemparannya sinkron, .catch() saja tidak cukup; cek state dulu + try/catch.
    try {
        const state = html5QrCode.getState();
        if (state === Html5QrcodeScannerState.SCANNING || state === Html5QrcodeScannerState.PAUSED) {
            html5QrCode.stop().catch(() => {});
        }
    } catch { /* scanner belum berjalan — abaikan */ }
}
function toggleManualInput() { const r = el('reader'), m = el('manualInput'); if (m.style.display === 'none') { stopScanner(); r.style.display = 'none'; m.style.display = 'block'; } else { r.style.display = 'block'; m.style.display = 'none'; initScanner(); } }
function processManualInput() { const d = el('qrDataInput').value; if (d) processQR(d); else alert('Masukkan data QR.'); }

window.confirmPayment = confirmPayment; window.cancelPayment = cancelPayment; window.stopScanner = stopScanner;
window.toggleManualInput = toggleManualInput; window.processManualInput = processManualInput;
document.addEventListener('DOMContentLoaded', () => log('Scanner siap', 'success'));
window.addEventListener('load', initScanner);
window.addEventListener('beforeunload', stopScanner);
