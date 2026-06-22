<?php

namespace App\Services;

use App\Models\Wallet;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * Faucet Service
 * Service untuk distribute test MATIC ke user wallets
 */
class FaucetService
{
    private $polygonService;
    private $initialTestAmount = 5.0; // 5 MATIC per request (cukup untuk ~350 privateTransfer @ ~0.014 MATIC)
    private $minMasterWalletBalance = 5.5; // Min master balance = amount + headroom gas. Di bawah ini request ditolak.

    public function __construct(PolygonService $polygonService)
    {
        $this->polygonService = $polygonService;
    }

    /**
     * Distribute test MATIC ke wallet baru
     */
    public function distributeTestMatic($walletAddress)
    {
        try {
            Log::info('[FaucetService] Distributing test MATIC', [
                'address' => $walletAddress,
                'amount' => $this->initialTestAmount,
            ]);

            // Check master wallet balance (hanya untuk real mode, skip di simulation)
            $masterWallet = env('POLYGON_MASTER_WALLET');
            if ($masterWallet) {
                $balanceCheck = $this->checkMasterWalletBalance();
                if (!$balanceCheck['success']) {
                    return $balanceCheck;
                }
            }

            // Transfer dari master wallet
            $result = $this->polygonService->transferMatic(
                $walletAddress, 
                $this->initialTestAmount
            );

            if ($result['success']) {
                Log::info('[FaucetService] Test MATIC distributed successfully', [
                    'tx_hash' => $result['tx_hash'],
                    'simulation' => $result['simulation'] ?? false,
                ]);

                // Sync wallet balance from blockchain after distribution
                $this->polygonService->syncWalletBalance($walletAddress);

                return [
                    'success' => true,
                    'amount' => $this->initialTestAmount,
                    'tx_hash' => $result['tx_hash'],
                    'simulation' => $result['simulation'] ?? false,
                    'message' => 'Test MATIC berhasil dikirim ke wallet Anda!',
                ];
            }

            return [
                'success' => false,
                'error' => 'Gagal mengirim test MATIC',
            ];

        } catch (\Exception $e) {
            Log::error('[FaucetService] Distribution error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check apakah user eligible untuk request test MATIC lagi
     */
    public function canRequestTestMatic($userId)
    {
        // Limit: 1 request per 24 jam
        $lastRequest = DB::table('faucet_requests')
            ->where('user_id', $userId)
            ->where('created_at', '>', now()->subDay())
            ->first();

        return $lastRequest === null;
    }

    /**
     * Get waktu tersisa sebelum bisa request lagi
     */
    public function getTimeUntilNextRequest($userId)
    {
        $lastRequest = DB::table('faucet_requests')
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$lastRequest) {
            return 0;
        }

        $nextAllowedTime = now()->parse($lastRequest->created_at)->addDay();
        $remainingSeconds = max(0, now()->diffInSeconds($nextAllowedTime, false));

        return $remainingSeconds;
    }

    /**
     * Manual request test MATIC (untuk top-up testing)
     */
    public function requestTestMatic($userId, $walletAddress)
    {
        try {
            if (!$this->canRequestTestMatic($userId)) {
                $remainingSeconds = $this->getTimeUntilNextRequest($userId);
                $hours = floor($remainingSeconds / 3600);
                $minutes = floor(($remainingSeconds % 3600) / 60);
                
                return [
                    'success' => false,
                    'error' => sprintf(
                        'Anda hanya bisa request test MATIC setiap 24 jam. Coba lagi dalam %d jam %d menit.',
                        $hours,
                        $minutes
                    ),
                    'retry_after_seconds' => $remainingSeconds,
                ];
            }

            // Distribute
            $result = $this->distributeTestMatic($walletAddress);

            if ($result['success']) {
                // Record request
                DB::table('faucet_requests')->insert([
                    'user_id' => $userId,
                    'wallet_address' => $walletAddress,
                    'amount' => $this->initialTestAmount,
                    'tx_hash' => $result['tx_hash'],
                    'is_simulation' => $result['simulation'] ?? false,
                    'created_at' => now(),
                ]);

                Log::info('[FaucetService] Faucet request recorded', [
                    'user_id' => $userId,
                    'wallet_address' => $walletAddress,
                ]);
            }

            return $result;

        } catch (\Exception $e) {
            Log::error('[FaucetService] Request error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get faucet request history untuk user
     */
    public function getFaucetHistory($userId, $limit = 10)
    {
        try {
            $requests = DB::table('faucet_requests')
                ->where('user_id', $userId)
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get();

            return [
                'success' => true,
                'requests' => $requests,
            ];
        } catch (\Exception $e) {
            Log::error('[FaucetService] Get history error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'requests' => [],
            ];
        }
    }

    /**
     * Get total distributed MATIC
     */
    public function getTotalDistributed()
    {
        try {
            $total = DB::table('faucet_requests')
                ->where('is_simulation', false)
                ->sum('amount');

            return [
                'success' => true,
                'total' => $total ?? 0,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'total' => 0,
            ];
        }
    }

    /**
     * Check master wallet balance
     */
    public function checkMasterWalletBalance()
    {
        try {
            $masterWallet = env('POLYGON_MASTER_WALLET');

            if (!$masterWallet) {
                Log::info('[FaucetService] Master wallet not configured - simulation mode');
                return [
                    'success' => true,
                    'message' => 'Simulation mode - master wallet not configured',
                    'simulation' => true,
                ];
            }

            // Validate master wallet address format
            if (!preg_match('/^0x[a-fA-F0-9]{40}$/', $masterWallet)) {
                Log::error('[FaucetService] Invalid master wallet address format', [
                    'master_wallet' => $masterWallet,
                ]);
                return [
                    'success' => false,
                    'error' => 'Format alamat master wallet tidak valid',
                ];
            }

            Log::info('[FaucetService] Checking master wallet balance', [
                'master_wallet' => $masterWallet,
                'minimum_required' => $this->minMasterWalletBalance,
            ]);

            // Get master wallet balance with timeout handling
            $balanceData = $this->polygonService->getRealTimeBalance($masterWallet);

            if (!$balanceData['success']) {
                Log::error('[FaucetService] Failed to get master wallet balance', [
                    'master_wallet' => $masterWallet,
                    'error' => $balanceData['error'] ?? 'Unknown error',
                ]);
                return [
                    'success' => false,
                    'error' => 'Gagal mengecek balance master wallet: ' . ($balanceData['error'] ?? 'Unknown error'),
                ];
            }

            $balance = $balanceData['balance'];

            // Validate balance is numeric
            if (!is_numeric($balance)) {
                Log::error('[FaucetService] Invalid balance received', [
                    'balance' => $balance,
                    'type' => gettype($balance),
                ]);
                return [
                    'success' => false,
                    'error' => 'Balance master wallet tidak valid',
                ];
            }

            $balance = floatval($balance);

            Log::info('[FaucetService] Master wallet balance checked', [
                'balance' => $balance,
                'minimum_required' => $this->minMasterWalletBalance,
                'sufficient' => $balance >= $this->minMasterWalletBalance,
            ]);

            // Check if balance meets minimum requirement
            if ($balance < $this->minMasterWalletBalance) {
                Log::warning('[FaucetService] Master wallet balance insufficient', [
                    'current_balance' => $balance,
                    'minimum_required' => $this->minMasterWalletBalance,
                    'shortfall' => $this->minMasterWalletBalance - $balance,
                ]);

                return [
                    'success' => false,
                    'error' => sprintf(
                        'Master wallet balance terlalu rendah (%.4f MATIC). Minimum required: %.2f MATIC. Shortfall: %.4f MATIC. Silakan isi master wallet dari faucet official.',
                        $balance,
                        $this->minMasterWalletBalance,
                        $this->minMasterWalletBalance - $balance
                    ),
                    'current_balance' => $balance,
                    'minimum_required' => $this->minMasterWalletBalance,
                    'shortfall' => $this->minMasterWalletBalance - $balance,
                ];
            }

            return [
                'success' => true,
                'balance' => $balance,
                'message' => 'Master wallet balance sufficient',
                'simulation' => false,
            ];

        } catch (\Exception $e) {
            Log::error('[FaucetService] Balance check exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => 'Terjadi kesalahan saat mengecek balance master wallet: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Get faucet configuration
     */
    public function getConfig()
    {
        return [
            'amount_per_request' => $this->initialTestAmount,
            'min_master_balance' => $this->minMasterWalletBalance,
            'cooldown_hours' => 24,
        ];
    }
}

