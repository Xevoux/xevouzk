<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PubkeysTest extends TestCase
{
    use RefreshDatabase;

    private function userWithWallet(): User
    {
        $user = User::create([
            'name' => 'Demo', 'email' => 'demo@example.com',
            'password' => bcrypt('password123'), 'zk_enabled' => true,
            'zk_public_key' => '02'.str_repeat('a', 64),
        ]);
        Wallet::create([
            'user_id' => $user->id,
            'wallet_address' => 'ZKWALLET'.strtoupper(bin2hex(random_bytes(16))),
            'polygon_address' => '0x5368c2B3F57C5ff286E3964C94a189EF11E28D17',
            'public_key' => '04'.str_repeat('a', 128),
            'balance' => 0.1, 'is_active' => true,
        ]);
        return $user;
    }

    public function test_publishes_shield_pub_when_empty(): void
    {
        $user = $this->userWithWallet();
        $shieldPub = '12345678901234567890123456789012345678901234567890';

        $this->actingAs($user)
            ->postJson('/pubkeys', ['shield_pub' => $shieldPub])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame($shieldPub, $user->wallet->fresh()->shield_pub);
    }

    public function test_does_not_overwrite_existing_shield_pub(): void
    {
        $user = $this->userWithWallet();
        $user->wallet->update(['shield_pub' => '111']);

        $this->actingAs($user)
            ->postJson('/pubkeys', ['shield_pub' => '222'])
            ->assertOk();

        $this->assertSame('111', $user->wallet->fresh()->shield_pub);
    }

    public function test_rejects_non_numeric_shield_pub(): void
    {
        $user = $this->userWithWallet();
        $this->actingAs($user)
            ->postJson('/pubkeys', ['shield_pub' => 'xyz'])
            ->assertStatus(422);
    }
}
