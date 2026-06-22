<?php

namespace App\Http\Controllers;

use App\Models\Wallet;
use App\Models\Transaction;
use App\Services\PolygonService;
use App\Services\QRCodeService;
use App\Services\FaucetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class WalletController extends Controller
{
    public function __construct(
        protected PolygonService $polygonService,
        protected QRCodeService $qrCodeService,
    ) {
    }
    /**
     * Menampilkan detail wallet dengan QR code
     */
    public function index()
    {
        $wallet = Auth::user()->wallet;
        
        if (!$wallet) {
            return redirect()->route('dashboard')->with('error', 'Wallet tidak ditemukan.');
        }

        // Generate QR Code untuk wallet address (untuk menerima pembayaran)
        $qrCodeData = json_encode([
            'type' => 'wallet_address',
            'address' => $wallet->wallet_address,
            'name' => Auth::user()->name,
            'timestamp' => time(),
        ]);

        // Use SVG format (doesn't require imagick/GD)
        $qrCode = base64_encode(QrCode::format('svg')->size(300)->margin(2)->generate($qrCodeData));

        return view('payment.wallet', compact('wallet', 'qrCode'));
    }

    /**
     * Generate QR untuk menerima pembayaran.
     * Tanpa amount → static QR (address only). Dengan amount → dynamic payment QR.
     */
    public function generateReceiveQR(Request $request)
    {
        $request->validate([
            'amount' => 'nullable|numeric|min:0.01',
            'description' => 'nullable|string|max:255',
        ]);

        $wallet = Auth::user()->wallet;
        $userName = Auth::user()->name;

        if ($request->filled('amount')) {
            $result = $this->qrCodeService->generatePaymentQR(
                $wallet->wallet_address,
                (float) $request->amount,
                [
                    'description' => $request->description,
                    'currency' => 'MATIC',
                ]
            );
        } else {
            $result = $this->qrCodeService->generateStaticQR($wallet->wallet_address, $userName);
        }

        if (!$result['success']) {
            return response()->json($result, 500);
        }

        return response()->json($result);
    }

    /**
     * Decode QR code data
     */
    public function decodeQR(Request $request)
    {
        $request->validate([
            'qr_data' => 'required|string',
        ]);

        try {
            $data = json_decode($request->qr_data, true);
            
            if (!$data || !isset($data['wallet_address'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'QR Code tidak valid',
                ], 400);
            }

            // Verify wallet exists with user relationship
            $wallet = Wallet::with('user')->where('wallet_address', $data['wallet_address'])->first();
            
            if (!$wallet) {
                return response()->json([
                    'success' => false,
                    'message' => 'Wallet tidak ditemukan',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $data,
                'wallet_exists' => true,
                'receiver_name' => $wallet->user->name ?? 'Unknown',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal decode QR Code: ' . $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Download QR code sebagai image
     */
    public function downloadQR(Request $request)
    {
        $wallet = Auth::user()->wallet;
        
        $qrCodeData = json_encode([
            'type' => 'wallet_address',
            'address' => $wallet->wallet_address,
            'name' => Auth::user()->name,
            'timestamp' => time(),
        ]);

        // Use SVG format
        $qrCode = QrCode::format('svg')
            ->size(400)
            ->margin(2)
            ->errorCorrection('H')
            ->generate($qrCodeData);

        return response($qrCode, 200)
            ->header('Content-Type', 'image/svg+xml')
            ->header('Content-Disposition', 'attachment; filename="wallet-qr-' . substr($wallet->wallet_address, 0, 15) . '.svg"');
    }

    /**
     * Get wallet info
     */
    public function getInfo()
    {
        $wallet = Auth::user()->wallet;
        
        return response()->json([
            'success' => true,
            'wallet' => [
                'address' => $wallet->wallet_address,
                'balance' => number_format($wallet->balance, 2, '.', ''),
                'polygon_address' => $wallet->polygon_address,
                'public_key' => $wallet->public_key,
            ],
        ]);
    }

    /**
     * Sync wallet balance from blockchain
     */
    public function syncBalance(Wallet $wallet = null)
    {
        try {
            if (!$wallet) {
                $wallet = Auth::user()->wallet;
            }

            if (!$wallet || !$wallet->polygon_address) {
                return response()->json([
                    'success' => false,
                    'message' => 'Wallet atau alamat Polygon tidak ditemukan',
                ], 404);
            }

            $result = $this->polygonService->syncWalletBalance($wallet->polygon_address);

            return response()->json([
                'success' => $result['success'],
                'balance' => $result['balance'] ?? 0,
                'address' => $wallet->polygon_address,
            ]);

        } catch (\Exception $e) {
            Log::error('[WalletController] Sync balance error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Live state — satu endpoint untuk semua data dinamis.
     *
     * ?chain=1 → sync on-chain (baca RPC, tulis cache, lapor status RPC).
     * default → DB-only: saldo cache, faucet cooldown, transaksi terbaru.
     *
     * Selalu balas bentuk JSON yang sama sehingga frontend bisa update DOM
     * apa pun mode-nya.
     */
    public function liveState(Request $request)
    {
        $user = Auth::user();
        $wallet = $user->wallet;

        if (!$wallet) {
            return response()->json(['error' => 'Wallet tidak ditemukan'], 404);
        }

        $chain = $request->boolean('chain');

        if ($chain && $wallet->polygon_address) {
            $result = $this->polygonService->syncWalletBalance($wallet->polygon_address);
            $online = (bool) ($result['success'] ?? false);
            $wallet->refresh(); // ambil balance + last_sync_at terbaru
        } else {
            // Tanpa query RPC: anggap online selama sync terakhir belum 'offline'.
            $online = $wallet->balanceStaleness() !== 'offline';
        }

        $faucet = app(FaucetService::class);
        $canRequest = $faucet->canRequestTestMatic($user->id);
        $retryAfter = $canRequest ? 0 : $faucet->getTimeUntilNextRequest($user->id);

        $transactions = Transaction::with(['senderWallet:id,wallet_address', 'receiverWallet:id,wallet_address'])
            ->where('sender_wallet_id', $wallet->id)
            ->orWhere('receiver_wallet_id', $wallet->id)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(function (Transaction $tx) use ($wallet) {
                $sent = (int) $tx->sender_wallet_id === (int) $wallet->id;
                $counterparty = $sent
                    ? optional($tx->receiverWallet)->wallet_address
                    : optional($tx->senderWallet)->wallet_address;

                $isPrivate = !empty($tx->zk_proof)
                    || in_array($tx->type, ['private_transfer', 'private_receive'], true);

                return [
                    'id' => $tx->id,
                    'direction' => $sent ? 'sent' : 'received',
                    // Nominal & counterparty disembunyikan untuk transaksi privat.
                    'amount' => $isPrivate ? null : $tx->amount,
                    'status' => $tx->status,
                    'counterparty' => $isPrivate ? null : $counterparty,
                    'is_private' => $isPrivate,
                    'hash' => $tx->polygon_tx_hash ?? $tx->transaction_hash,
                    'created_at' => optional($tx->created_at)->toIso8601String(),
                ];
            });

        return response()->json([
            'balance' => $wallet->balance,
            'balance_stale' => $wallet->isBalanceStale(),
            'staleness' => $wallet->balanceStaleness(),
            'last_sync' => optional($wallet->last_sync_at)->toIso8601String(),
            'network' => [
                'online' => $online,
                'chainId' => (int) config('services.polygon.chain_id', env('POLYGON_CHAIN_ID', 80002)),
            ],
            'faucet' => [
                'can_request' => $canRequest,
                'retry_after' => (int) $retryAfter,
            ],
            'transactions' => $transactions,
        ]);
    }

    /**
     * Get real-time balance from blockchain
     */
    public function getRealTimeBalance()
    {
        try {
            $wallet = Auth::user()->wallet;

            if (!$wallet || !$wallet->polygon_address) {
                return response()->json([
                    'success' => false,
                    'message' => 'Wallet tidak ditemukan',
                ], 404);
            }

            $balanceData = $this->polygonService->getRealTimeBalance($wallet->polygon_address);

            return response()->json($balanceData);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Request test MATIC from faucet
     */
    public function requestTestMatic(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                Log::warning('[WalletController] User not authenticated for faucet request');
                return response()->json([
                    'success' => false,
                    'error' => 'User tidak terautentikasi',
                ], 401);
            }

            $wallet = $user->wallet;
            if (!$wallet) {
                Log::error('[WalletController] Wallet not found for user', ['user_id' => $user->id]);
                return response()->json([
                    'success' => false,
                    'error' => 'Wallet tidak ditemukan',
                ], 404);
            }

            Log::info('[WalletController] Processing faucet request', [
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
                'has_polygon_address' => !empty($wallet->polygon_address),
            ]);

            // wallet selalu sudah punya polygon_address sejak register
            // (di-derive di browser). Kalau kosong, ini state ilegal → 500.
            if (!$wallet->polygon_address) {
                Log::error('[WalletController] Wallet tanpa polygon_address (state ilegal)', [
                    'user_id' => $user->id,
                    'wallet_id' => $wallet->id,
                ]);
                return response()->json([
                    'success' => false,
                    'error' => 'Wallet tidak memiliki alamat Polygon. Silakan daftar ulang.',
                ], 500);
            }

            // Request test MATIC from faucet
            $faucetService = app(FaucetService::class);
            $result = $faucetService->requestTestMatic($user->id, $wallet->polygon_address);

            if ($result['success']) {
                // Sync balance from blockchain (no manual update)
                $this->polygonService->syncWalletBalance($wallet->polygon_address);
                
                // Refresh wallet data
                $wallet->refresh();

                Log::info('[WalletController] Test MATIC requested successfully', [
                    'user_id' => $user->id,
                    'amount' => $result['amount'],
                    'tx_hash' => $result['tx_hash'],
                    'simulation' => $result['simulation'] ?? false,
                    'synced_balance' => $wallet->balance,
                ]);

                // Catat ke riwayat sebagai RECV faucet (master wallet → user).
                // Faucet adalah distribusi test token publik — aman dicatat penuh.
                // Hanya bila tx_hash on-chain valid (bukan simulasi).
                $faucetTx = $result['tx_hash'] ?? null;
                if (is_string($faucetTx) && preg_match('/^0x[0-9a-fA-F]{64}$/', $faucetTx)
                    && !Transaction::where('polygon_tx_hash', $faucetTx)->exists()) {
                    try {
                        Transaction::create([
                            'type' => 'faucet',
                            'sender_wallet_id' => null, // master/faucet wallet (bukan wallet XevouZK)
                            'receiver_wallet_id' => $wallet->id,
                            'receiver_address' => $wallet->polygon_address,
                            'amount' => $result['amount'],
                            'transaction_hash' => hash('sha256', 'faucet|'.$wallet->id.'|'.$faucetTx),
                            'polygon_tx_hash' => $faucetTx,
                            'status' => 'completed',
                        ]);
                    } catch (\Throwable $e) {
                        Log::warning('[WalletController] Gagal catat faucet ke riwayat: '.$e->getMessage());
                    }
                }

                return response()->json($result);
            } else {
                Log::warning('[WalletController] Faucet request failed', [
                    'user_id' => $user->id,
                    'error' => $result['error'] ?? 'Unknown error',
                    'retry_after' => $result['retry_after_seconds'] ?? null,
                ]);

                return response()->json($result, 400);
            }

        } catch (\Throwable $e) {
            // \Throwable: tangkap juga Error/TypeError dari layer kripto/RPC supaya
            // endpoint faucet selalu balas JSON (klien fetch().json() tidak pecah).
            Log::error('[WalletController] Request test MATIC error', [
                'user_id' => Auth::id() ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Terjadi kesalahan internal. Silakan coba lagi.',
                'debug_error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get faucet request history
     */
    public function getFaucetHistory()
    {
        try {
            $user = Auth::user();
            $faucetService = app(FaucetService::class);
            
            $result = $faucetService->getFaucetHistory($user->id);

            return response()->json($result);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'requests' => [],
            ], 500);
        }
    }

    /**
     * Check if user can request test MATIC
     */
    public function canRequestTestMatic()
    {
        try {
            $user = Auth::user();
            $faucetService = app(FaucetService::class);
            
            $canRequest = $faucetService->canRequestTestMatic($user->id);
            $remainingTime = $faucetService->getTimeUntilNextRequest($user->id);

            return response()->json([
                'success' => true,
                'can_request' => $canRequest,
                'remaining_seconds' => $remainingTime,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * publish shielded keypair public (Poseidon) milik user.
     * Idempoten: hanya disimpan jika belum ada. Tidak ada rahasia di sini
     * shield_pub publik, dipakai pengirim untuk membuat note penerima.
     */
    public function publishPubkeys(\Illuminate\Http\Request $request)
    {
        $request->validate([
            'shield_pub' => ['required', 'string', 'regex:/^[0-9]+$/'],
        ]);

        $wallet = $request->user()->wallet;
        if ($wallet && empty($wallet->shield_pub)) {
            $wallet->shield_pub = $request->input('shield_pub');
            $wallet->save();
        }

        return response()->json(['success' => true]);
    }
}

