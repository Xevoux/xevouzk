<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'zk_login_commitment',
        'zk_public_key',
        'zk_enabled',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'zk_login_commitment', // Hide ZK commitment from serialization
        'zk_public_key',       // Hide ZK public key from serialization
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'zk_enabled' => 'boolean',
        ];
    }

    /**
     * Get the wallet associated with the user
     */
    public function wallet()
    {
        return $this->hasOne(Wallet::class);
    }

    /**
     * Check if user has ZK authentication enabled
     */
    public function hasZKAuth(): bool
    {
        return $this->zk_enabled && !empty($this->zk_login_commitment);
    }

    /**
     * Get authentication mode string
     */
    public function getAuthModeAttribute(): string
    {
        return $this->zk_enabled ? 'ZK-SNARK' : 'Standard';
    }
}
