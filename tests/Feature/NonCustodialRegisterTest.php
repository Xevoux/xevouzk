<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NonCustodialRegisterTest extends TestCase
{
    use RefreshDatabase;

    private string $validAddress = '0x16a747E428a954328bd3cb67963fa85f4175e6a4';
    private string $validPubKey;
    private string $validSchnorrPubKey = '02f8a3e5d2c7b1908642315c89efa7d2c40b6e8f9d3a72c5e0815bf4a2d6e9c731';

    protected function setUp(): void
    {
        parent::setUp();
        // 04 + 64 hex (x) + 64 hex (y) = 130 chars uncompressed pub key
        $this->validPubKey = '04'.str_repeat('a', 64).str_repeat('b', 64);
    }

    public function test_wallets_table_does_not_have_encrypted_private_key_column(): void
    {
        $this->assertFalse(
            Schema::hasColumn('wallets', 'encrypted_private_key'),
            'Column encrypted_private_key should be dropped'
        );
    }

    public function test_register_requires_polygon_address(): void
    {
        $response = $this->post('/register', [
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'schnorr_public_key' => $this->validSchnorrPubKey,
            // polygon_address sengaja dihilangkan
        ]);

        $response->assertSessionHasErrors(['polygon_address']);
        $this->assertDatabaseMissing('users', ['email' => 'alice@example.com']);
    }

    public function test_register_requires_schnorr_public_key(): void
    {
        $response = $this->post('/register', [
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'polygon_address' => $this->validAddress,
            'polygon_public_key' => $this->validPubKey,
            // schnorr_public_key sengaja dihilangkan — sekarang mandatory
        ]);

        $response->assertSessionHasErrors(['schnorr_public_key']);
    }

    public function test_register_rejects_invalid_polygon_address_format(): void
    {
        $response = $this->post('/register', [
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'schnorr_public_key' => $this->validSchnorrPubKey,
            'polygon_address' => 'not-an-address',
            'polygon_public_key' => $this->validPubKey,
        ]);

        $response->assertSessionHasErrors(['polygon_address']);
    }

    public function test_register_with_valid_payload_creates_user_and_wallet_without_private_key(): void
    {
        // Sengaja TANPA password / password_confirmation: registrasi
        // non-custodial tidak menerima password — hanya public artifacts.
        $response = $this->post('/register', [
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'schnorr_public_key' => $this->validSchnorrPubKey,
            'polygon_address' => $this->validAddress,
            'polygon_public_key' => $this->validPubKey,
        ]);

        $response->assertRedirect(route('login'));

        $user = User::where('email', 'alice@example.com')->first();
        $this->assertNotNull($user, 'User harus dibuat tanpa password dikirim');
        $this->assertTrue((bool) $user->zk_enabled, 'Schnorr Mode mandatory — zk_enabled harus true');
        $this->assertSame($this->validSchnorrPubKey, $user->zk_public_key);

        $wallet = Wallet::where('user_id', $user->id)->first();
        $this->assertNotNull($wallet, 'Wallet harus dibuat saat register');
        $this->assertSame(
            strtolower($this->validAddress),
            strtolower($wallet->polygon_address),
            'Address harus sama dengan yang dikirim client (tidak di-generate server)'
        );
        $this->assertSame($this->validPubKey, $wallet->public_key);
    }

    public function test_polygon_service_no_longer_has_create_blockchain_wallet_method(): void
    {
        $this->assertFalse(
            method_exists(\App\Services\PolygonService::class, 'createBlockchainWallet'),
            'PolygonService::createBlockchainWallet harus dihapus'
        );
    }
}
