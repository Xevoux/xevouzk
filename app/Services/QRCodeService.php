<?php

namespace App\Services;

use App\Models\QRCode;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use SimpleSoftwareIO\QrCode\Facades\QrCode as QrCodeFacade;

/**
 * QR Code Service.
 *
 * Mode static: alamat wallet saja, no expiration, no DB persist.
 * Mode dynamic: payment request dengan amount, HMAC signature, expiration 15 menit,
 * persist ke qr_codes table.
 */
class QRCodeService
{
    private ZKSNARKService $zkService;
    private int $expirationMinutes = 15;

    public function __construct(ZKSNARKService $zkService)
    {
        $this->zkService = $zkService;
    }

    /**
     * Generate static QR — hanya alamat wallet, tanpa state.
     */
    public function generateStaticQR(string $walletAddress, string $receiverName = ''): array
    {
        try {
            $paymentData = [
                'type' => 'wallet_address',
                'address' => $walletAddress,
                'name' => $receiverName,
                'timestamp' => now()->timestamp,
            ];

            $qrCode = QrCodeFacade::format('svg')
                ->size(300)
                ->errorCorrection('M')
                ->margin(2)
                ->generate(json_encode($paymentData));

            return [
                'success' => true,
                'qr_code' => base64_encode($qrCode),
                'payment_data' => $paymentData,
                'format' => 'svg',
            ];
        } catch (\Exception $e) {
            Log::error('[QRCodeService] generateStaticQR error: '.$e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Generate dynamic payment QR — amount + signature + expiration, persist DB.
     */
    public function generatePaymentQR(string $walletAddress, $amount, array $metadata = []): array
    {
        Log::info('[QRCodeService] Generating payment QR code');

        try {
            $codeId = $this->generateUniqueCode();

            $paymentData = [
                'code_id' => $codeId,
                'wallet_address' => $walletAddress,
                'amount' => $amount,
                'currency' => $metadata['currency'] ?? 'MATIC',
                'description' => $metadata['description'] ?? '',
                'created_at' => now()->timestamp,
                'expires_at' => now()->addMinutes($this->expirationMinutes)->timestamp,
            ];
            $paymentData['signature'] = $this->signPaymentData($paymentData);

            $encrypted = Crypt::encryptString(json_encode($paymentData));

            $qrCode = QrCodeFacade::format('svg')
                ->size(300)
                ->errorCorrection('M')
                ->margin(2)
                ->generate($encrypted);

            $this->storeQRCodeData($codeId, $paymentData, $walletAddress, $amount);

            Log::info('[QRCodeService] Dynamic QR generated', [
                'code_id' => $codeId,
                'amount' => $amount,
            ]);

            return [
                'success' => true,
                'code_id' => $codeId,
                'qr_code' => base64_encode($qrCode),
                'data' => $paymentData,
                'expires_at' => $paymentData['expires_at'],
                'format' => 'svg',
            ];
        } catch (\Exception $e) {
            Log::error('[QRCodeService] generatePaymentQR error: '.$e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Scan dynamic QR — decrypt + verify signature + expiration + reuse.
     */
    public function scanQRCode(string $encryptedData): array
    {
        Log::info('[QRCodeService] Scanning QR code');

        try {
            $decrypted = Crypt::decryptString($encryptedData);
            $paymentData = json_decode($decrypted, true);

            if (!$paymentData || !is_array($paymentData)) {
                throw new \Exception('Invalid QR code data');
            }

            if (!$this->verifySignature($paymentData)) {
                throw new \Exception('QR code signature verification failed');
            }

            if (now()->timestamp > ($paymentData['expires_at'] ?? 0)) {
                throw new \Exception('QR code has expired');
            }

            if ($this->isQRCodeUsed($paymentData['code_id'] ?? '')) {
                throw new \Exception('QR code has already been used');
            }

            Log::info('[QRCodeService] QR scanned', [
                'code_id' => $paymentData['code_id'] ?? null,
            ]);

            return [
                'success' => true,
                'data' => $paymentData,
            ];
        } catch (\Exception $e) {
            Log::error('[QRCodeService] scan error: '.$e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process payment dari QR code dengan ZK proof balance check.
     */
    public function processQRPayment(string $codeId, string $senderAddress, string $zkProof): array
    {
        Log::info('[QRCodeService] Processing QR payment', ['code_id' => $codeId]);

        try {
            $qrRow = QRCode::where('code_id', $codeId)->active()->first();
            if (!$qrRow) {
                throw new \Exception('QR code not found or expired');
            }

            if (!$this->zkService->verifyBalanceProof($zkProof, (float) $qrRow->amount)) {
                throw new \Exception('ZK proof verification failed');
            }

            $this->markQRCodeAsUsed($codeId);

            return [
                'success' => true,
                'transaction_data' => [
                    'from' => $senderAddress,
                    'to' => $qrRow->wallet_address,
                    'amount' => $qrRow->amount,
                ],
            ];
        } catch (\Exception $e) {
            Log::error('[QRCodeService] processQRPayment error: '.$e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function validateQRStructure(array $data): bool
    {
        $required = ['code_id', 'wallet_address', 'amount', 'created_at', 'expires_at', 'signature'];
        foreach ($required as $f) {
            if (!isset($data[$f])) {
                return false;
            }
        }
        return true;
    }

    private function generateUniqueCode(): string
    {
        return 'QR'.strtoupper(bin2hex(random_bytes(16)));
    }

    private function signPaymentData(array $data): string
    {
        unset($data['signature']);
        return hash_hmac('sha256', json_encode($data), config('app.key'));
    }

    private function verifySignature(array $data): bool
    {
        $signature = $data['signature'] ?? null;
        if (!$signature) {
            return false;
        }
        $expected = $this->signPaymentData($data);
        return hash_equals($expected, $signature);
    }

    /**
     * persist QR ke DB (qr_codes table) instead of cache.
     */
    private function storeQRCodeData(string $codeId, array $data, string $walletAddress, $amount): void
    {
        QRCode::create([
            'code_id' => $codeId,
            'user_id' => Auth::id() ?? null,
            'wallet_address' => $walletAddress,
            'amount' => $amount,
            'currency' => $data['currency'] ?? 'MATIC',
            'description' => $data['description'] ?? null,
            'qr_data' => $data,
            'signature' => $data['signature'],
            'expires_at' => now()->addMinutes($this->expirationMinutes),
            'status' => 'active',
        ]);
    }

    private function getQRCodeData(string $codeId): ?array
    {
        $row = QRCode::where('code_id', $codeId)->first();
        return $row ? $row->qr_data : null;
    }

    private function isQRCodeUsed(string $codeId): bool
    {
        if (!$codeId) {
            return false;
        }
        return QRCode::where('code_id', $codeId)->where('status', 'used')->exists();
    }

    private function markQRCodeAsUsed(string $codeId, ?int $transactionId = null): void
    {
        $row = QRCode::where('code_id', $codeId)->first();
        if ($row) {
            $row->markAsUsed($transactionId);
        }
    }
}
