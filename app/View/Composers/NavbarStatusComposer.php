<?php

namespace App\View\Composers;

use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Inject status koneksi Polygon ke navbar.
 *
 * Status diturunkan dari `last_sync_at` wallet user yang login
 * - fresh (< 5 min) → status-pill--connected hijau berdenyut
 * - stale (5–30 min) → status-pill--pending kuning
 * - offline (> 30 min, atau belum pernah sync) → status-pill--offline merah
 *
 * Diregister di AppServiceProvider::boot pada partial `layouts.partials.navbar`.
 */
class NavbarStatusComposer
{
    public function compose(View $view): void
    {
        $user = Auth::user();
        $wallet = $user?->wallet;

        if (!$wallet) {
            $view->with('networkStatus', [
                'state' => 'connected',
                'label' => 'AMOY',
                'chain_id' => 80002,
                'tooltip' => 'Polygon Amoy Testnet',
                'last_sync' => null,
            ]);
            return;
        }

        $state = match ($wallet->balanceStaleness()) {
            'fresh'   => 'connected',
            'stale'   => 'pending',
            'offline' => 'offline',
            default   => 'connected',
        };

        $tooltip = match ($state) {
            'connected' => 'Polygon Amoy Testnet — saldo tersync ' . $wallet->last_sync_at?->diffForHumans(),
            'pending'   => 'Saldo cache (sync ' . $wallet->last_sync_at?->diffForHumans() . ') — refresh untuk data terkini',
            'offline'   => $wallet->last_sync_at
                ? 'Polygon RPC belum sync lebih dari 30 menit — saldo mungkin tidak akurat'
                : 'Belum pernah sync dengan Polygon RPC',
            default => 'Polygon Amoy Testnet',
        };

        $view->with('networkStatus', [
            'state' => $state,
            'label' => 'AMOY',
            'chain_id' => 80002,
            'tooltip' => $tooltip,
            'last_sync' => $wallet->last_sync_at,
        ]);
    }
}
