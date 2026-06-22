<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use App\Services\SchnorrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchnorrLoginTest extends TestCase
{
    use RefreshDatabase;

    private string $email = 'bob@example.com';
    private string $password = 'super-secret-123';
    private SchnorrService $schnorr;
    private string $privateKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schnorr = app(SchnorrService::class);
        $this->privateKey = $this->schnorr->derivePrivateKey($this->email, $this->password);
    }

    /**
     * Buat user non-custodial dengan zk_public_key yang konsisten dengan
     * (email, password) di atas, plus wallet ber-last_sync_at baru agar
     * syncWalletOnLogin tidak menyentuh RPC.
     */
    private function makeUser(): User
    {
        $user = User::factory()->create([
            'email' => $this->email,
            'zk_enabled' => true,
            'zk_public_key' => $this->schnorr->derivePublicKey($this->privateKey),
        ]);

        Wallet::create([
            'user_id' => $user->id,
            'wallet_address' => 'ZKWALLETTEST'.$user->id,
            'polygon_address' => '0x'.str_repeat('1', 40),
            'public_key' => '04'.str_repeat('a', 128),
            'balance' => 0,
            'is_active' => true,
            'last_sync_at' => now(),
        ]);

        return $user;
    }

    /**
     * Bangun signature Schnorr valid untuk message lc(email)|ts|csrf, dengan
     * csrf token yang kita seed ke session lewat withSession.
     */
    private function signedPayload(string $csrfToken, ?int $ts = null): array
    {
        $ts ??= time();
        $message = strtolower($this->email).'|'.$ts.'|'.$csrfToken;

        return [
            'email' => $this->email,
            'schnorr_signature' => $this->schnorr->sign($this->privateKey, $message),
            'schnorr_timestamp' => (string) $ts,
        ];
    }

    public function test_login_requires_schnorr_signature(): void
    {
        $this->makeUser();

        $response = $this->post('/login', [
            'email' => $this->email,
            'schnorr_timestamp' => (string) time(),
        ]);

        $response->assertSessionHasErrors('schnorr_signature');
        $this->assertGuest();
    }

    public function test_login_rejects_invalid_signature(): void
    {
        $this->makeUser();

        $response = $this->post('/login', [
            'email' => $this->email,
            'schnorr_signature' => str_repeat('a', 130), // format valid, signature salah
            'schnorr_timestamp' => (string) time(),
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_does_not_send_or_check_password(): void
    {
        $this->makeUser();
        $token = 'csrf-token-fixed-value';

        // Tidak ada field password sama sekali — login murni Schnorr.
        $response = $this->withSession(['_token' => $token])
            ->post('/login', $this->signedPayload($token));

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
    }

    public function test_valid_signature_cannot_be_replayed(): void
    {
        $user = $this->makeUser();
        $token = 'csrf-token-fixed-value';
        $payload = $this->signedPayload($token);

        // Pemakaian pertama: sukses.
        $this->withSession(['_token' => $token])->post('/login', $payload);
        $this->assertAuthenticatedAs($user->fresh());

        // Logout, lalu replay signature yang sama (timestamp masih dalam window).
        $this->post('/logout');
        $this->assertGuest();

        $response = $this->withSession(['_token' => $token])->post('/login', $payload);
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_is_rate_limited_after_five_failures(): void
    {
        $this->makeUser();
        $token = 'csrf-token-fixed-value';

        // 5 percobaan gagal (signature salah) menguras limiter.
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', [
                'email' => $this->email,
                'schnorr_signature' => str_repeat('a', 130),
                'schnorr_timestamp' => (string) time(),
            ]);
        }

        // Percobaan ke-6 dengan signature VALID pun harus tetap diblokir.
        $response = $this->withSession(['_token' => $token])
            ->post('/login', $this->signedPayload($token));

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}
