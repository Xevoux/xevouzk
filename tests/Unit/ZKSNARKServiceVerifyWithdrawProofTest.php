<?php

namespace Tests\Unit;

use App\Services\ZKSNARKService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit test untuk ZKSNARKService::verifyWithdrawProof
 * dan extractWithdrawPublicInputs.
 *
 * Pattern proof base64-encoded JSON dengan struct:
 *   { proofType: "withdraw", proof: { pi_a, pi_b, pi_c }, publicSignals: [...], publicInputs: {...} }
 *
 * RefreshDatabase dipakai karena verifyNullifier query DB untuk replay guard.
 */
class ZKSNARKServiceVerifyWithdrawProofTest extends TestCase
{
    use RefreshDatabase;

    private ZKSNARKService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ZKSNARKService();
    }

    private function makeValidWithdrawProofPayload(): string
    {
        // commitment, nullifier, recipient (uint160), amount — semua field bn128 (decimal string)
        $publicInputs = [
            'commitment' => '12345678901234567890',
            'nullifier' => '98765432109876543210',
            'recipient' => '128844628837569636028478876195854456319608263332',  // = 0x16a747E428a954328bd3cb67963fa85f4175e6a4 as uint160
            'amount' => '1000000000000000000', // 1 MATIC in wei
        ];

        $data = [
            'proofType' => 'withdraw',
            'proof' => [
                'pi_a' => ['1', '2', '1'],
                'pi_b' => [['3', '4'], ['5', '6'], ['1', '0']],
                'pi_c' => ['7', '8', '1'],
                'protocol' => 'groth16',
                'curve' => 'bn128',
            ],
            'publicSignals' => array_values($publicInputs),
            'publicInputs' => $publicInputs,
        ];

        return base64_encode(json_encode($data));
    }

    public function test_method_verify_withdraw_proof_exists(): void
    {
        $this->assertTrue(
            method_exists($this->service, 'verifyWithdrawProof'),
            'ZKSNARKService::verifyWithdrawProof harus ada'
        );
    }

    public function test_method_extract_withdraw_public_inputs_exists(): void
    {
        $this->assertTrue(
            method_exists($this->service, 'extractWithdrawPublicInputs'),
            'ZKSNARKService::extractWithdrawPublicInputs harus ada'
        );
    }

    public function test_verify_withdraw_returns_false_for_empty_proof(): void
    {
        $this->assertFalse($this->service->verifyWithdrawProof(''));
    }

    public function test_verify_withdraw_returns_false_for_malformed_base64(): void
    {
        $this->assertFalse($this->service->verifyWithdrawProof('!!!not-base64!!!'));
    }

    public function test_verify_withdraw_returns_false_for_wrong_proof_type(): void
    {
        $data = [
            'proofType' => 'balance_check', // bukan withdraw
            'proof' => ['pi_a' => ['1', '2'], 'pi_b' => [['3', '4'], ['5', '6']], 'pi_c' => ['7', '8']],
            'publicSignals' => ['1', '2', '3', '4'],
            'publicInputs' => ['commitment' => '1', 'nullifier' => '2', 'recipient' => '3', 'amount' => '4'],
        ];

        $this->assertFalse($this->service->verifyWithdrawProof(base64_encode(json_encode($data))));
    }

    public function test_verify_withdraw_returns_false_for_missing_public_input_field(): void
    {
        $data = [
            'proofType' => 'withdraw',
            'proof' => ['pi_a' => ['1', '2'], 'pi_b' => [['3', '4'], ['5', '6']], 'pi_c' => ['7', '8']],
            'publicSignals' => ['1', '2', '3'],
            'publicInputs' => [
                'commitment' => '1',
                'nullifier' => '2',
                // recipient + amount sengaja dihilangkan
            ],
        ];

        $this->assertFalse($this->service->verifyWithdrawProof(base64_encode(json_encode($data))));
    }

    public function test_verify_withdraw_returns_true_for_valid_struct(): void
    {
        // Note: pairing check tidak full di PHP — service melakukan struct + field check.
        // Real Groth16 pairing didelegasikan ke kontrak on-chain.
        $proof = $this->makeValidWithdrawProofPayload();
        $this->assertTrue($this->service->verifyWithdrawProof($proof));
    }

    public function test_verify_withdraw_returns_false_when_amount_exceeds_field_size(): void
    {
        $data = [
            'proofType' => 'withdraw',
            'proof' => [
                'pi_a' => ['1', '2', '1'],
                'pi_b' => [['3', '4'], ['5', '6'], ['1', '0']],
                'pi_c' => ['7', '8', '1'],
            ],
            'publicSignals' => [
                '1',
                '2',
                '3',
                // > SNARK_SCALAR_FIELD
                '99999999999999999999999999999999999999999999999999999999999999999999999999999',
            ],
            'publicInputs' => [
                'commitment' => '1',
                'nullifier' => '2',
                'recipient' => '3',
                'amount' => '99999999999999999999999999999999999999999999999999999999999999999999999999999',
            ],
        ];

        $this->assertFalse($this->service->verifyWithdrawProof(base64_encode(json_encode($data))));
    }

    public function test_extract_withdraw_public_inputs_returns_array_for_valid_proof(): void
    {
        $proof = $this->makeValidWithdrawProofPayload();
        $inputs = $this->service->extractWithdrawPublicInputs($proof);

        $this->assertIsArray($inputs);
        $this->assertArrayHasKey('commitment', $inputs);
        $this->assertArrayHasKey('nullifier', $inputs);
        $this->assertArrayHasKey('recipient', $inputs);
        $this->assertArrayHasKey('amount', $inputs);
    }

    public function test_extract_withdraw_public_inputs_returns_null_for_invalid(): void
    {
        $this->assertNull($this->service->extractWithdrawPublicInputs(''));
        $this->assertNull($this->service->extractWithdrawPublicInputs('not-base64'));
    }
}
