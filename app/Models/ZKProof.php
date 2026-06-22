<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ZKProof extends Model
{
    use HasFactory;

    protected $table = 'zk_proofs';

    protected $fillable = [
        'user_id',
        'transaction_id',
        'proof_type',
        'proof_data',
        'public_inputs',
        'verification_status',
        'verified_at',
        'nullifier',
        'commitment',
    ];

    protected $casts = [
        'proof_data' => 'array',
        'public_inputs' => 'array',
        'verified_at' => 'datetime',
    ];

    /**
     * Get the user that owns the proof
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the transaction associated with the proof
     */
    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * Scope untuk verified proofs
     */
    public function scopeVerified($query)
    {
        return $query->where('verification_status', 'verified');
    }

    /**
     * Scope untuk pending proofs
     */
    public function scopePending($query)
    {
        return $query->where('verification_status', 'pending');
    }

    /**
     * Check if proof is verified
     */
    public function isVerified()
    {
        return $this->verification_status === 'verified';
    }
}
