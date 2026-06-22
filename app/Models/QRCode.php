<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class QRCode extends Model
{
    use HasFactory;

    protected $table = 'qr_codes';

    protected $fillable = [
        'code_id',
        'user_id',
        'wallet_address',
        'amount',
        'currency',
        'description',
        'qr_data',
        'signature',
        'expires_at',
        'used_at',
        'status',
        'transaction_id',
    ];

    protected $casts = [
        'qr_data' => 'array',
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    /**
     * Get the user that owns the QR code
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the transaction associated with the QR code
     */
    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * Scope untuk active QR codes
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active')
                    ->where('expires_at', '>', now());
    }

    /**
     * Scope untuk unused QR codes
     */
    public function scopeUnused($query)
    {
        return $query->whereNull('used_at');
    }

    /**
     * Check if QR code is expired
     */
    public function isExpired()
    {
        return $this->expires_at < now();
    }

    /**
     * Check if QR code is used
     */
    public function isUsed()
    {
        return !is_null($this->used_at);
    }

    /**
     * Mark QR code as used
     */
    public function markAsUsed($transactionId = null)
    {
        $this->update([
            'used_at' => now(),
            'status' => 'used',
            'transaction_id' => $transactionId,
        ]);
    }
}
