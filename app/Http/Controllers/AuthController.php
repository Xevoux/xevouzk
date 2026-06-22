<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Wallet;
use App\Services\PolygonService;
use App\Services\SchnorrService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    private const SCHNORR_TIMESTAMP_WINDOW = 300;
    private const LOGIN_MAX_ATTEMPTS = 5;

    public function __construct(private SchnorrService $schnorr)
    {
    }

    public function showLogin()
    {
        return view('auth.login');
    }

    public function showRegister()
    {
        return view('auth.register');
    }

    public function register(Request $request)
    {
        // non-custodial wallet. Client wajib derive Schnorr keypair
        // + Polygon keypair di browser dari (email, password), lalu kirim
        // public artifacts (schnorr_public_key, polygon_address, polygon_public_key).
        // Password TIDAK dikirim ke server — hanya dipakai di browser untuk
        // men-derive key. Server tidak generate maupun menerima rahasia apa pun.
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'schnorr_public_key' => ['required', 'regex:/^(02|03)[0-9a-f]{64}$/i'],
            'polygon_address' => ['required', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'polygon_public_key' => ['required', 'regex:/^04[0-9a-f]{128}$/i'],
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            // Akun non-custodial: autentikasi lewat Schnorr, bukan password.
            // Kolom password (NOT NULL) diisi hash acak yang tak akan pernah
            // dicocokkan — server tidak pernah menyimpan password pengguna.
            'password' => Hash::make(Str::random(40)),
            'zk_enabled' => true,
            'zk_public_key' => $request->input('schnorr_public_key'),
        ]);

        Wallet::create([
            'user_id' => $user->id,
            'wallet_address' => $this->generateInternalWalletId(),
            'polygon_address' => $request->input('polygon_address'),
            'public_key' => $request->input('polygon_public_key'),
            'balance' => 0,
            'is_active' => true,
        ]);

        Log::info('[AuthController] Non-custodial user registered', [
            'user_id' => $user->id,
            'email' => $user->email,
            'polygon_address' => $request->input('polygon_address'),
        ]);

        return redirect()->route('login')->with(
            'success',
            'Akun berhasil dibuat! Wallet non-custodial Anda telah didaftarkan. Silakan login.'
        );
    }

    public function login(Request $request)
    {
        // Autentikasi murni Schnorr (non-custodial). Client men-derive private
        // key dari (email, password) di browser, menandatangani pesan
        // lc(email)|timestamp|csrf, lalu mengirim HANYA signature. Password
        // tidak pernah meninggalkan perangkat; server memverifikasi signature
        // terhadap zk_public_key tersimpan dan tidak pernah melihat password.
        $request->validate([
            'email' => 'required|email',
            'schnorr_signature' => ['required', 'regex:/^[0-9a-f]{130}$/i'],
            'schnorr_timestamp' => 'required',
        ]);

        // Rate limiting per (email + IP): cegah brute-force/credential-stuffing.
        $throttleKey = $this->loginThrottleKey($request);
        if (RateLimiter::tooManyAttempts($throttleKey, self::LOGIN_MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return back()->withErrors([
                'email' => "Terlalu banyak percobaan login. Coba lagi dalam {$seconds} detik.",
            ])->withInput();
        }

        // Pesan generik untuk semua kegagalan → cegah user enumeration & jangan
        // bocorkan faktor mana yang gagal.
        $genericError = back()->withErrors([
            'email' => 'Kredensial yang diberikan tidak sesuai dengan catatan kami.',
        ])->withInput();

        $user = User::where('email', $request->email)->first();
        if (!$user || !$user->zk_enabled || !$user->zk_public_key) {
            RateLimiter::hit($throttleKey);

            return $genericError;
        }

        if (!$this->verifySchnorrLogin($request, $user)) {
            RateLimiter::hit($throttleKey);

            return $genericError;
        }

        RateLimiter::clear($throttleKey);
        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        $this->syncWalletOnLogin(Auth::user());

        Log::info('[AuthController] Schnorr login successful', [
            'user_id' => Auth::id(),
            'email' => Auth::user()->email,
        ]);

        return redirect()->intended('dashboard');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    /**
     * Verifikasi Schnorr signature dari client.
     * Message format: lc(email)|timestamp|csrf_token (lihat §4).
     */
    private function verifySchnorrLogin(Request $request, User $user): bool
    {
        $signature = $request->input('schnorr_signature');
        $timestamp = $request->input('schnorr_timestamp');

        if (!$signature || !$timestamp || !$user->zk_public_key) {
            Log::warning('[AuthController] Schnorr login missing fields', [
                'email' => $user->email,
                'has_signature' => (bool) $signature,
                'has_timestamp' => (bool) $timestamp,
                'has_public_key' => (bool) $user->zk_public_key,
            ]);
            return false;
        }

        if (!ctype_digit((string) $timestamp)) {
            return false;
        }

        $tsInt = (int) $timestamp;
        if (abs(time() - $tsInt) > self::SCHNORR_TIMESTAMP_WINDOW) {
            Log::warning('[AuthController] Schnorr timestamp out of window', [
                'email' => $user->email,
                'timestamp' => $tsInt,
                'now' => time(),
            ]);
            return false;
        }

        $message = strtolower($user->email).'|'.$tsInt.'|'.csrf_token();
        $verified = $this->schnorr->verify($user->zk_public_key, $message, $signature);

        Log::info('[AuthController] Schnorr verify result', [
            'email' => $user->email,
            'verified' => $verified,
        ]);

        if (!$verified) {
            return false;
        }

        // Anti-replay: signature hanya boleh dipakai sekali dalam jendela waktu.
        // Cache::add = put-if-absent atomik; kalau key sudah ada, signature ini
        // sudah pernah dipakai (double-submit/replay) → tolak. Timestamp window
        // mengikat TTL-nya, jadi setelah kedaluwarsa cek timestamp yang menolak.
        $nonceKey = 'schnorr_nonce:'.$user->id.':'.hash('sha256', $signature);
        if (!Cache::add($nonceKey, true, self::SCHNORR_TIMESTAMP_WINDOW)) {
            Log::warning('[AuthController] Schnorr signature replay rejected', [
                'email' => $user->email,
            ]);
            return false;
        }

        return true;
    }

    /**
     * Throttle key untuk login: per (email + IP). Transliterate agar key cache
     * aman (mis. email unicode).
     */
    private function loginThrottleKey(Request $request): string
    {
        return Str::transliterate(Str::lower((string) $request->input('email')).'|'.$request->ip());
    }

    private function generateInternalWalletId(): string
    {
        return 'ZKWALLET'.strtoupper(bin2hex(random_bytes(16)));
    }

    private function syncWalletOnLogin(User $user): void
    {
        // Sync saldo on-chain best-effort: login TIDAK boleh crash karena RPC
        // issue. Tapi user harus tahu kalau sync gagal — flash warning ke
        // session supaya layout menampilkan alert di halaman berikutnya.
        // CLAUDE.md §3 (privacy claim) bergantung pada saldo on-chain yang
        // benar; user wajib sadar kalau yang ditampilkan adalah cache stale.
        try {
            $wallet = $user->wallet;

            if (!$wallet || !$wallet->polygon_address) {
                return;
            }

            if (!$wallet->needsSync()) {
                return;
            }

            $polygonService = app(PolygonService::class);
            $result = $polygonService->syncWalletBalance($wallet->polygon_address);

            if (empty($result['success'])) {
                $reason = $result['error'] ?? 'unknown';
                Log::warning('[AuthController] Balance sync returned failure', [
                    'user_id' => $user->id,
                    'reason' => $reason,
                ]);
                session()->flash(
                    'warning',
                    'Sync saldo on-chain gagal: '.$reason.'. Saldo yang ditampilkan adalah cache terakhir ('
                        .($wallet->last_sync_at?->diffForHumans() ?? 'belum pernah sync').').'
                );
                return;
            }

            Log::info('[AuthController] Wallet balance synced on login', [
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('[AuthController] Balance sync on login failed', [
                'user_id' => $user->id,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            session()->flash(
                'warning',
                'Sync saldo on-chain gagal — Polygon RPC tidak responsif. '
                    .'Saldo yang ditampilkan mungkin tidak terkini. '
                    .'Coba refresh halaman atau periksa koneksi.'
            );
        }
    }
}
