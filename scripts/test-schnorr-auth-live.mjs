// Pengujian autentikasi Schnorr end-to-end (live) — XevouZK.
//
// Satu-satunya skrip uji Schnorr (lihat docs/PENGUJIAN.md §7). Menabrak
// endpoint /login yang SUNGGUHAN (server harus jalan) memakai modul KLIEN
// asli (schnorr-auth.js + polygon-key.js) untuk men-derive keypair &
// menandatangani, persis seperti browser. Mendaftarkan satu user uji segar
// tiap run (email unik) → tidak menyentuh akun nyata.
//
//   A. Login sah        -> diterima      => completeness + interop klien≡server:
//                                           signature JS DITERIMA verify() PHP.
//   B. Replay signature -> ditolak       => single-use nonce + window 300 dtk.
//   C. Signature palsu  -> ditolak       => soundness (verifikasi gagal).
//   D. Timestamp basi   -> ditolak       => di luar window 300 dtk.
//   E. Brute-force      -> diblokir 5x+  => rate-limit per (email+IP).
//
// Catatan: scenario A membuktikan klien (JS) & server (PHP) interoperabel di
// level yang mengikat — signature yang dibuat JS diverifikasi PHP. Sistem ini
// signing selalu di klien; server HANYA verify (CLAUDE.md §3.2).
//
// Jalankan (server harus hidup lebih dulu — Herd / php artisan serve):
//   node scripts/test-schnorr-auth-live.mjs
//   $env:BASE_URL="http://127.0.0.1:8000"; node scripts/test-schnorr-auth-live.mjs
//
// Default BASE_URL = https://xevouzk.test (link Herd lokal; perlu `herd link
// xevouzk` agar resolve). Sertifikat lokal → verifikasi TLS dimatikan otomatis
// utk host https lokal. Exit 0 = semua lulus.

import * as Schnorr from '../resources/js/schnorr-auth.js';
import * as Polygon from '../resources/js/polygon-key.js';

const BASE = (process.env.BASE_URL || 'https://xevouzk.test').replace(/\/$/, '');
if (BASE.startsWith('https')) {
    // Herd memakai CA lokal yang tidak dipercaya Node — ini uji lokal, aman.
    process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';
}

let pass = 0;
let fail = 0;
function assert(label, cond, detail = '') {
    if (cond) {
        pass++;
        console.log(`  [PASS] ${label}`);
    } else {
        fail++;
        console.log(`  [FAIL] ${label}${detail ? '  — ' + detail : ''}`);
    }
}

// ---- cookie jar minimal + http helper --------------------------------------
function jarHeader(jar) {
    return Object.entries(jar).map(([k, v]) => `${k}=${v}`).join('; ');
}
function updateJar(jar, res) {
    for (const sc of res.headers.getSetCookie?.() ?? []) {
        const [pair] = sc.split(';');
        const eq = pair.indexOf('=');
        if (eq > 0) jar[pair.slice(0, eq).trim()] = pair.slice(eq + 1).trim();
    }
}
async function req(method, path, { jar = {}, form, cookieHeader } = {}) {
    const headers = {};
    const ch = cookieHeader ?? jarHeader(jar);
    if (ch) headers['Cookie'] = ch;
    let body;
    if (form) {
        headers['Content-Type'] = 'application/x-www-form-urlencoded';
        body = new URLSearchParams(form).toString();
    }
    const res = await fetch(BASE + path, { method, headers, body, redirect: 'manual' });
    updateJar(jar, res);
    const text = await res.text();
    return { status: res.status, location: res.headers.get('location') || '', body: text };
}
const get = (path, jar) => req('GET', path, { jar });
const post = (path, form, jar, cookieHeader) => req('POST', path, { jar, form, cookieHeader });

function csrfOf(html) {
    const m = html.match(/name="csrf-token" content="([^"]+)"/)
        || html.match(/name="_token"\s+value="([^"]+)"/);
    return m ? m[1] : null;
}
function isAuthenticated(res) {
    return res.status >= 300 && res.status < 400 && /\/dashboard/.test(res.location);
}

// ---- main ------------------------------------------------------------------
console.log('== Schnorr auth live test (endpoint /login) ==\n');
console.log(`  target: ${BASE}\n`);

// preflight
let probe;
try {
    probe = await get('/login', {});
} catch (e) {
    console.error(`[ERROR] Tidak bisa menghubungi ${BASE} (${e.code || e.message}).`);
    console.error('        Pastikan Herd aktif & domain ter-link: `herd link xevouzk`,');
    console.error('        atau override: $env:BASE_URL="http://127.0.0.1:8000" (php artisan serve)');
    console.error('        atau $env:BASE_URL="https://xevouzk.test" (link default folder).');
    process.exit(1);
}
if (!csrfOf(probe.body)) {
    console.error('[ERROR] Halaman /login tak memuat csrf-token — apakah ini app XevouZK?');
    process.exit(1);
}

const password = 'Schnorr-Test-Passw0rd!';
const email = `schnorr-test-${Date.now()}@xevou.test`;

// --- daftarkan user uji segar (pakai modul klien asli) ---
{
    const jar = {};
    const page = await get('/register', jar);
    const csrf = csrfOf(page.body);
    const schnorrPub = Schnorr.derivePublicKey(Schnorr.derivePrivateKey(email, password));
    const wallet = Polygon.deriveWallet(email, password);
    const res = await post('/register', {
        _token: csrf,
        name: 'Schnorr Test',
        email,
        schnorr_public_key: schnorrPub,
        polygon_address: wallet.address,
        polygon_public_key: wallet.publicKey,
    }, jar);
    const ok = res.status >= 300 && res.status < 400 && /\/login/.test(res.location);
    if (!ok) {
        console.error(`[ERROR] Registrasi user uji gagal (status ${res.status}, loc ${res.location}).`);
        process.exit(1);
    }
    console.log(`  user uji terdaftar: ${email}\n`);
}

// helper: bangun request login yang valid utk user uji pada sesi (jar) baru
async function buildLogin(jar, { ts } = {}) {
    const page = await get('/login', jar);
    const csrf = csrfOf(page.body);
    const t = ts ?? Math.floor(Date.now() / 1000);
    const message = `${email.toLowerCase()}|${t}|${csrf}`;
    const priv = Schnorr.derivePrivateKey(email, password);
    const sig = Schnorr.sign(priv, message);
    return { csrf, ts: t, sig };
}

// ---- A. login sah diterima + B. replay ditolak -----------------------------
console.log('[A] Login sah -> diterima');
const jarA = {};
const loginA = await buildLogin(jarA);
const cookieBeforeA = jarHeader(jarA); // snapshot sesi pra-login untuk replay
const formA = {
    _token: loginA.csrf,
    email,
    schnorr_signature: loginA.sig,
    schnorr_timestamp: String(loginA.ts),
};
const resA = await post('/login', formA, jarA);
assert('login dengan signature sah -> redirect ke dashboard', isAuthenticated(resA),
    `status ${resA.status}, loc ${resA.location}`);

console.log('\n[B] Replay signature -> ditolak');
const resB = await post('/login', formA, {}, cookieBeforeA); // request identik, sesi pra-login
assert('request login yang sama dikirim ulang -> TIDAK terautentikasi', !isAuthenticated(resB),
    `status ${resB.status}, loc ${resB.location}`);

// ---- C. signature palsu ditolak --------------------------------------------
console.log('\n[C] Signature palsu -> ditolak');
const jarC = {};
const loginC = await buildLogin(jarC);
const tampered = loginC.sig.slice(0, 20) + (loginC.sig[20] === '0' ? '1' : '0') + loginC.sig.slice(21);
const resC = await post('/login', {
    _token: loginC.csrf, email,
    schnorr_signature: tampered,
    schnorr_timestamp: String(loginC.ts),
}, jarC);
assert('signature di-tamper 1 karakter -> TIDAK terautentikasi', !isAuthenticated(resC),
    `status ${resC.status}, loc ${resC.location}`);

// ---- D. timestamp kedaluwarsa ditolak --------------------------------------
console.log('\n[D] Timestamp kedaluwarsa -> ditolak');
const jarD = {};
const staleTs = Math.floor(Date.now() / 1000) - 400; // > 300 dtk window
const loginD = await buildLogin(jarD, { ts: staleTs });
const resD = await post('/login', {
    _token: loginD.csrf, email,
    schnorr_signature: loginD.sig,
    schnorr_timestamp: String(loginD.ts),
}, jarD);
assert('timestamp 400 dtk lalu -> TIDAK terautentikasi', !isAuthenticated(resD),
    `status ${resD.status}, loc ${resD.location}`);

// ---- E. brute-force diblokir rate-limit ------------------------------------
console.log('\n[E] Brute-force -> diblokir rate-limit (per email+IP)');
const jarE = {};
const bruteEmail = `bruteforce-${Date.now()}@xevou.test`;
const garbageSig = '0'.repeat(130);
let lockedBy = null;
let lockedAt = 0;
for (let i = 1; i <= 7 && !lockedBy; i++) {
    const page = await get('/login', jarE);
    const csrf = csrfOf(page.body);
    const res = await post('/login', {
        _token: csrf, email: bruteEmail,
        schnorr_signature: garbageSig,
        schnorr_timestamp: String(Math.floor(Date.now() / 1000)),
    }, jarE);
    if (res.status === 429) { lockedBy = 'middleware (throttle 429)'; lockedAt = i; break; }
    const after = await get('/login', jarE);
    if (/Terlalu banyak percobaan/i.test(after.body)) { lockedBy = 'controller rate-limit'; lockedAt = i; break; }
}
assert('login gagal berulang akhirnya diblokir', lockedBy !== null,
    lockedBy ? '' : 'tidak ada lockout sampai percobaan ke-7');
if (lockedBy) console.log(`         -> diblokir pada percobaan ke-${lockedAt} via ${lockedBy}`);

// ---- ringkasan -------------------------------------------------------------
console.log('\n----------------------------------------------');
console.log(`Hasil: ${pass} passed, ${fail} failed.`);
if (fail === 0) {
    console.log('ALL SCHNORR AUTH LIVE TESTS PASSED');
    process.exit(0);
}
console.log('SCHNORR AUTH LIVE TESTS FAILED');
process.exit(1);
