<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PoolBalancePrivacyTest extends TestCase
{
    use RefreshDatabase;

    private function userWithWallet(): User
    {
        $user = User::create([
            'name' => 'Demo',
            'email' => 'demo@example.com',
            'password' => bcrypt('password123'),
            'zk_enabled' => true,
            'zk_public_key' => '02f8a3e5d2c7b1908642315c89efa7d2c40b6e8f9d3a72c5e0815bf4a2d6e9c731',
        ]);
        Wallet::create([
            'user_id' => $user->id,
            'wallet_address' => 'ZKWALLET'.strtoupper(bin2hex(random_bytes(16))),
            'polygon_address' => '0x5368c2B3F57C5ff286E3964C94a189EF11E28D17',
            'public_key' => '04'.str_repeat('a', 64).str_repeat('b', 64),
            'balance' => 0.1,
            'is_active' => true,
        ]);

        return $user;
    }

    /** /live/state tidak boleh mengandung field saldo pool — server tetap buta. */
    public function test_live_state_has_no_pool_balance_field(): void
    {
        $user = $this->userWithWallet();

        $json = $this->actingAs($user)
            ->getJson('/live/state?chain=0')
            ->assertOk()
            ->json();

        $flat = json_encode($json);
        $this->assertStringNotContainsString('pool_balance', $flat);
        $this->assertStringNotContainsString('poolBalance', $flat);
        $this->assertArrayNotHasKey('pool', $json);
    }
}
