<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use App\Services\PolygonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class NonCustodialPaymentRelayTest extends TestCase
{
    use RefreshDatabase;

    private function authenticatedUserWithWallet(): User
    {
        $user = User::create([
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'password' => bcrypt('password123'),
            'zk_enabled' => true,
            'zk_public_key' => '02f8a3e5d2c7b1908642315c89efa7d2c40b6e8f9d3a72c5e0815bf4a2d6e9c731',
        ]);

        Wallet::create([
            'user_id' => $user->id,
            'wallet_address' => 'ZKWALLET'.strtoupper(bin2hex(random_bytes(16))),
            'polygon_address' => '0x16a747E428a954328bd3cb67963fa85f4175e6a4',
            'public_key' => '04'.str_repeat('a', 64).str_repeat('b', 64),
            'balance' => 5.0,
            'is_active' => true,
        ]);

        return $user;
    }

    public function test_relay_endpoint_requires_authentication(): void
    {
        $response = $this->post('/payment/relay', [
            'raw_tx' => '0xf86c808504a817c80082520894...',
        ]);

        $response->assertRedirect(route('login'));
    }

    public function test_relay_endpoint_requires_raw_tx_field(): void
    {
        $user = $this->authenticatedUserWithWallet();

        $response = $this->actingAs($user)->postJson('/payment/relay', [
            // raw_tx hilang
        ]);

        $response->assertStatus(422);
    }

    public function test_relay_endpoint_rejects_malformed_raw_tx(): void
    {
        $user = $this->authenticatedUserWithWallet();

        $response = $this->actingAs($user)->postJson('/payment/relay', [
            'raw_tx' => 'not-a-hex-string',
        ]);

        $response->assertStatus(422);
    }

    public function test_relay_endpoint_forwards_raw_tx_to_polygon_service_and_returns_hash(): void
    {
        $user = $this->authenticatedUserWithWallet();

        $rawTx = '0xf86c808504a817c80082520894abcdef1234567890abcdef1234567890abcdef12880de0b6b3a7640000801ca0';
        $expectedHash = '0x'.str_repeat('1', 64);

        $mock = Mockery::mock(PolygonService::class)->makePartial();
        $mock->shouldReceive('sendRawTransaction')
            ->once()
            ->with($rawTx)
            ->andReturn([
                'success' => true,
                'tx_hash' => $expectedHash,
            ]);
        $this->app->instance(PolygonService::class, $mock);

        $response = $this->actingAs($user)->postJson('/payment/relay', [
            'raw_tx' => $rawTx,
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'tx_hash' => $expectedHash,
        ]);
    }

    public function test_relay_endpoint_returns_502_when_rpc_fails(): void
    {
        $user = $this->authenticatedUserWithWallet();

        $rawTx = '0xf86c808504a817c80082520894abcdef1234567890abcdef1234567890abcdef12880de0b6b3a7640000801ca0';

        $mock = Mockery::mock(PolygonService::class)->makePartial();
        $mock->shouldReceive('sendRawTransaction')
            ->once()
            ->andReturn([
                'success' => false,
                'error' => 'insufficient funds for gas',
            ]);
        $this->app->instance(PolygonService::class, $mock);

        $response = $this->actingAs($user)->postJson('/payment/relay', [
            'raw_tx' => $rawTx,
        ]);

        $response->assertStatus(502);
        $response->assertJson([
            'success' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
