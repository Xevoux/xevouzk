<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use App\Services\ZKSNARKService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Feature test untuk endpoint POST /payment/withdraw/verify.
 *
 * Endpoint preview/sanity-check: browser kirim proof + public inputs sebelum
 * sign tx ke `ZKPayment.withdraw(...)`. Server verify struct + Groth16 partial
 * lalu return ok/fail supaya user tidak bayar gas untuk tx yang akan revert.
 *
 * Endpoint TIDAK relay tx — relay tetap lewat /payment/relay.
 */
class PaymentWithdrawPreviewTest extends TestCase
{
    use RefreshDatabase;

    private function authenticatedUserWithWallet(): User
    {
        $user = User::create([
            'name' => 'Carol',
            'email' => 'carol@example.com',
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

    public function test_endpoint_requires_authentication(): void
    {
        $response = $this->post('/payment/withdraw/verify', [
            'proof' => 'somebase64',
        ]);

        $response->assertRedirect(route('login'));
    }

    public function test_endpoint_requires_proof_field(): void
    {
        $user = $this->authenticatedUserWithWallet();

        $response = $this->actingAs($user)->postJson('/payment/withdraw/verify', []);

        $response->assertStatus(422);
    }

    public function test_endpoint_returns_400_for_invalid_proof_struct(): void
    {
        $user = $this->authenticatedUserWithWallet();

        // Mock service to return false (proof invalid)
        $mock = Mockery::mock(ZKSNARKService::class)->makePartial();
        $mock->shouldReceive('verifyWithdrawProof')
            ->once()
            ->andReturn(false);
        $this->app->instance(ZKSNARKService::class, $mock);

        $response = $this->actingAs($user)->postJson('/payment/withdraw/verify', [
            'proof' => base64_encode('{}'),
        ]);

        $response->assertStatus(400);
        $response->assertJson(['success' => false]);
    }

    public function test_endpoint_returns_ok_for_valid_proof(): void
    {
        $user = $this->authenticatedUserWithWallet();

        $publicInputs = [
            'commitment' => '12345',
            'nullifier' => '67890',
            'recipient' => '111',
            'amount' => '1000000000000000000',
        ];

        $mock = Mockery::mock(ZKSNARKService::class)->makePartial();
        $mock->shouldReceive('verifyWithdrawProof')
            ->once()
            ->andReturn(true);
        $mock->shouldReceive('extractWithdrawPublicInputs')
            ->once()
            ->andReturn($publicInputs);
        $this->app->instance(ZKSNARKService::class, $mock);

        $response = $this->actingAs($user)->postJson('/payment/withdraw/verify', [
            'proof' => base64_encode('{"valid":true}'),
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'public_inputs' => $publicInputs,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
