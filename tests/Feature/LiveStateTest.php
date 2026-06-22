<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\PolygonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class LiveStateTest extends TestCase
{
    use RefreshDatabase;

    private function userWithWallet(float $balance = 0.1, ?string $lastSync = null): User
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
            'balance' => $balance,
            'last_sync_at' => $lastSync,
            'is_active' => true,
        ]);

        return $user;
    }

    public function test_requires_authentication(): void
    {
        $this->get('/live/state')->assertRedirect(route('login'));
    }

    public function test_chain0_returns_db_state_without_hitting_rpc(): void
    {
        $user = $this->userWithWallet(0.1, now()->toDateTimeString());

        // RPC tidak boleh dipanggil saat chain=0.
        $mock = Mockery::mock(PolygonService::class)->makePartial();
        $mock->shouldNotReceive('syncWalletBalance');
        $mock->shouldNotReceive('getRealTimeBalance');
        $this->app->instance(PolygonService::class, $mock);

        $response = $this->actingAs($user)->getJson('/live/state?chain=0');

        $response->assertOk();
        $response->assertJsonStructure([
            'balance',
            'balance_stale',
            'staleness',
            'last_sync',
            'network' => ['online', 'chainId'],
            'faucet' => ['can_request', 'retry_after'],
            'transactions',
        ]);
        $response->assertJsonPath('faucet.can_request', true);
        $response->assertJsonPath('faucet.retry_after', 0);
    }

    public function test_chain1_triggers_onchain_sync_and_reports_online(): void
    {
        $user = $this->userWithWallet(0.0);

        $mock = Mockery::mock(PolygonService::class)->makePartial();
        $mock->shouldReceive('syncWalletBalance')
            ->once()
            ->with('0x5368c2B3F57C5ff286E3964C94a189EF11E28D17')
            ->andReturnUsing(function ($addr) use ($user) {
                // Simulasikan efek samping sync: tulis cache + last_sync.
                $w = $user->wallet;
                $w->balance = '0.100000000000000000';
                $w->last_sync_at = now();
                $w->save();
                return ['success' => true, 'balance' => '0.100000000000000000'];
            });
        $this->app->instance(PolygonService::class, $mock);

        $response = $this->actingAs($user)->getJson('/live/state?chain=1');

        $response->assertOk();
        $response->assertJsonPath('network.online', true);
        $response->assertJsonPath('network.chainId', 80002);
        $this->assertEquals('0.100000000000000000', $response->json('balance'));
    }

    public function test_chain1_reports_offline_when_rpc_fails_but_keeps_cached_balance(): void
    {
        $user = $this->userWithWallet(0.42, now()->toDateTimeString());

        $mock = Mockery::mock(PolygonService::class)->makePartial();
        $mock->shouldReceive('syncWalletBalance')
            ->once()
            ->andReturn(['success' => false, 'error' => 'RPC timeout']);
        $this->app->instance(PolygonService::class, $mock);

        $response = $this->actingAs($user)->getJson('/live/state?chain=1');

        $response->assertOk();
        $response->assertJsonPath('network.online', false);
        // Saldo cache tetap dikembalikan, tidak hilang.
        $this->assertEquals('0.420000000000000000', $response->json('balance'));
    }

    public function test_includes_recent_transactions(): void
    {
        $user = $this->userWithWallet(1.0, now()->toDateTimeString());
        $wallet = $user->wallet;

        // Penerima: wallet kedua (schema mewajibkan receiver_wallet_id).
        $recipient = User::create([
            'name' => 'Carol',
            'email' => 'carol@example.com',
            'password' => bcrypt('password123'),
            'zk_enabled' => true,
            'zk_public_key' => '03f8a3e5d2c7b1908642315c89efa7d2c40b6e8f9d3a72c5e0815bf4a2d6e9c731',
        ]);
        $recipientWallet = Wallet::create([
            'user_id' => $recipient->id,
            'wallet_address' => 'ZKWALLET'.strtoupper(bin2hex(random_bytes(16))),
            'polygon_address' => '0x16a747E428a954328bd3cb67963fa85f4175e6a4',
            'public_key' => '04'.str_repeat('c', 64).str_repeat('d', 64),
            'balance' => 0,
            'is_active' => true,
        ]);

        Transaction::create([
            'sender_wallet_id' => $wallet->id,
            'receiver_wallet_id' => $recipientWallet->id,
            'amount' => 0.05,
            'transaction_hash' => 'tx_'.bin2hex(random_bytes(8)),
            'polygon_tx_hash' => '0x'.str_repeat('a', 64),
            'status' => 'completed',
        ]);

        $mock = Mockery::mock(PolygonService::class)->makePartial();
        $mock->shouldNotReceive('syncWalletBalance');
        $this->app->instance(PolygonService::class, $mock);

        $response = $this->actingAs($user)->getJson('/live/state?chain=0');

        $response->assertOk();
        $response->assertJsonCount(1, 'transactions');
        $response->assertJsonPath('transactions.0.direction', 'sent');
        $response->assertJsonPath('transactions.0.status', 'completed');
        $response->assertJsonPath('transactions.0.is_private', false);
        $response->assertJsonPath('transactions.0.counterparty', $recipientWallet->wallet_address);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
