<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'wallet_address',      // Internal app reference (ZKWALLET...)
        'polygon_address',     // Real blockchain address (0x...)
        'public_key',
        'shield_pub',          // Shielded keypair public (Poseidon)
        'balance',             // Single balance from blockchain
        'zk_proof_commitment',
        'last_sync_at',
        'is_active',
    ];

    protected $hidden = [];

    protected $casts = [
        'balance' => 'decimal:18',
        'last_sync_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * Get the user that owns the wallet
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get sent transactions
     */
    public function sentTransactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'sender_wallet_id');
    }

    /**
     * Get received transactions
     */
    public function receivedTransactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'receiver_wallet_id');
    }

    /**
     * Get all transactions (sent and received)
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'sender_wallet_id')
            ->union($this->hasMany(Transaction::class, 'receiver_wallet_id'));
    }

    /**
     * Check if wallet has valid blockchain address
     */
    public function hasBlockchainAddress(): bool
    {
        return !empty($this->polygon_address) && 
               preg_match('/^0x[a-fA-F0-9]{40}$/', $this->polygon_address);
    }

    /**
     * Get formatted balance (human readable)
     */
    public function getFormattedBalanceAttribute(): string
    {
        return number_format((float)$this->balance, 6) . ' MATIC';
    }

    /**
     * Check if balance needs sync (older than 5 minutes)
     */
    public function needsSync(): bool
    {
        if (!$this->last_sync_at) {
            return true;
        }
        return $this->last_sync_at->diffInMinutes(now()) >= 5;
    }

    /**
     * Status saldo cache vs realtime RPC. Dipakai untuk UI
     * - 'fresh' : disinkron dalam 5 menit terakhir
     * - 'stale' : 5–30 menit — mungkin tidak terkini, warning
     * - 'offline' : > 30 menit atau belum pernah sync — alert keras
     */
    public function balanceStaleness(): string
    {
        if (!$this->last_sync_at) {
            return 'offline';
        }
        $minutes = $this->last_sync_at->diffInMinutes(now());
        if ($minutes < 5)  return 'fresh';
        if ($minutes < 30) return 'stale';
        return 'offline';
    }

    public function isBalanceStale(): bool
    {
        return $this->balanceStaleness() !== 'fresh';
    }
}
