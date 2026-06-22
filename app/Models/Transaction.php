<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'sender_wallet_id',
        'receiver_wallet_id',
        'receiver_address',
        'amount',
        'transaction_hash',
        'polygon_tx_hash',
        'zk_proof',
        'zk_public_inputs',
        'status',
        'qr_code',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:8',
    ];

    public function senderWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'sender_wallet_id');
    }

    public function receiverWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'receiver_wallet_id');
    }
}

