<?php

namespace App\Services;

use App\Models\Wallet;
use App\Models\Transaction;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * Wallet Service
 * Service untuk manage wallet operations
 * Balance is always synced from blockchain (no local manipulation)
 */
class WalletService
{
    private $polygonService;
    private $zkService;

    public function __construct(PolygonService $polygonService, ZKSNARKService $zkService)
    {
        $this->polygonService = $polygonService;
        $this->zkService = $zkService;
    }

    /**
     * Get wallet balance from blockchain (real-time)
     */
    public function getBalance($polygonAddress, $withZKProof = false)
    {
        Log::info('[WalletService] Getting balance', ['address' => $polygonAddress]);
        
        try {
            // Get balance directly from blockchain
            $balanceData = $this->polygonService->getRealTimeBalance($polygonAddress);
            
            if (!$balanceData['success']) {
                throw new \Exception($balanceData['error'] ?? 'Failed to get balance');
            }
            
            $balance = $balanceData['balance'];
            
            // Update wallet in database
            $wallet = Wallet::where('polygon_address', $polygonAddress)->first();
            if ($wallet) {
                $wallet->balance = $balance;
                $wallet->last_sync_at = now();
                $wallet->save();
            }
            
            if ($withZKProof) {
                // Generate commitment untuk hide actual balance
                $randomness = bin2hex(random_bytes(32));
                $commitment = $this->zkService->generateCommitment($balance, $randomness);
                
                return [
                    'success' => true,
                    'balance' => $balance,
                    'commitment' => $commitment,
                    'timestamp' => $balanceData['timestamp'],
                ];
            }
            
            return [
                'success' => true,
                'balance' => $balance,
                'timestamp' => $balanceData['timestamp'],
            ];
            
        } catch (\Exception $e) {
            Log::error('[WalletService] Error getting balance: ' . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send payment with optional ZK proof
     */
    public function sendPayment($fromPolygonAddress, $toPolygonAddress, $amount, $zkProof = null)
    {
        Log::info('[WalletService] Sending payment', [
            'from' => $fromPolygonAddress,
            'to' => $toPolygonAddress,
            'amount' => $amount
        ]);
        
        try {
            DB::beginTransaction();
            
            // Get sender wallet
            $senderWallet = Wallet::where('polygon_address', $fromPolygonAddress)->first();
            if (!$senderWallet) {
                throw new \Exception('Sender wallet not found');
            }
            
            // Sync balance before transaction
            $balanceData = $this->getBalance($fromPolygonAddress);
            if (!$balanceData['success'] || $balanceData['balance'] < $amount) {
                throw new \Exception('Insufficient balance');
            }
            
            // Verify ZK proof if provided
            if ($zkProof) {
                $proofValid = $this->zkService->verifyBalanceProof($zkProof, $amount);
                
                if (!$proofValid) {
                    throw new \Exception('ZK proof verification failed');
                }
            }
            
            // WalletService::sendPayment legacy — pakai master wallet.
            // Untuk non-custodial flow yang sebenarnya, gunakan PaymentController::relayRawTransaction
            // dengan signed tx dari browser.
            $txHash = $this->polygonService->sendTransaction($toPolygonAddress, $amount);
            
            if (!$txHash) {
                throw new \Exception('Failed to send transaction to blockchain');
            }
            
            // Create transaction record
            $transaction = Transaction::create([
                'from_address' => $fromPolygonAddress,
                'to_address' => $toPolygonAddress,
                'amount' => $amount,
                'tx_hash' => $txHash,
                'status' => 'pending',
                'type' => 'transfer',
                'zk_proof_hash' => $zkProof ? hash('sha256', $zkProof) : null,
            ]);
            
            // Sync balances after transaction
            $this->syncBalance($fromPolygonAddress);
            $this->syncBalance($toPolygonAddress);
            
            DB::commit();
            
            Log::info('[WalletService] Payment sent successfully', [
                'tx_hash' => $txHash,
                'transaction_id' => $transaction->id
            ]);
            
            return [
                'success' => true,
                'tx_hash' => $txHash,
                'transaction' => $transaction,
            ];
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[WalletService] Error sending payment: ' . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get transaction history
     */
    public function getTransactionHistory($polygonAddress, $limit = 50)
    {
        Log::info('[WalletService] Getting transaction history', ['address' => $polygonAddress]);
        
        try {
            $transactions = Transaction::where('from_address', $polygonAddress)
                ->orWhere('to_address', $polygonAddress)
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get();
            
            return [
                'success' => true,
                'transactions' => $transactions,
            ];
            
        } catch (\Exception $e) {
            Log::error('[WalletService] Error getting transaction history: ' . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Verify transaction on blockchain
     */
    public function verifyTransaction($txHash)
    {
        Log::info('[WalletService] Verifying transaction', ['tx_hash' => $txHash]);
        
        try {
            $verification = $this->polygonService->verifyTransaction($txHash);
            
            if ($verification['verified']) {
                // Update transaction status
                $transaction = Transaction::where('tx_hash', $txHash)->first();
                
                if ($transaction) {
                    $transaction->update([
                        'status' => $verification['status'],
                        'confirmed_at' => now(),
                    ]);
                    
                    // Sync balances after confirmation
                    $this->syncBalance($transaction->from_address);
                    $this->syncBalance($transaction->to_address);
                }
            }
            
            return $verification;
            
        } catch (\Exception $e) {
            Log::error('[WalletService] Error verifying transaction: ' . $e->getMessage());
            
            return [
                'verified' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Generate internal wallet ID for app reference
     */
    private function generateInternalWalletId(): string
    {
        return 'ZKWALLET' . strtoupper(bin2hex(random_bytes(16)));
    }

    /**
     * Sync wallet balance from blockchain
     */
    public function syncBalance($polygonAddress)
    {
        try {
            return $this->polygonService->syncWalletBalance($polygonAddress);
        } catch (\Exception $e) {
            Log::error('[WalletService] Error syncing balance: ' . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get wallet by polygon address
     */
    public function getWalletByPolygonAddress($polygonAddress)
    {
        return Wallet::where('polygon_address', $polygonAddress)->first();
    }

    /**
     * Get wallet by internal wallet address
     */
    public function getWalletByInternalAddress($walletAddress)
    {
        return Wallet::where('wallet_address', $walletAddress)->first();
    }
}
