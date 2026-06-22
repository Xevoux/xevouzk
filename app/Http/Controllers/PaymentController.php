<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\PolygonService;
use App\Services\QRCodeService;
use App\Services\ZKSNARKService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function __construct(
        private ZKSNARKService $zkSnark,
        private PolygonService $polygon,
        private QRCodeService $qrCode,
    ) {
    }

    public function showPaymentForm()
    {
        $wallet = Auth::user()->wallet;
        return view('payment.form', compact('wallet'));
    }

    /**
     * Generate dynamic payment QR. Delegasi ke QRCodeService.
     */
    public function generateQRCode(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:255',
        ]);

        $wallet = Auth::user()->wallet;
        $result = $this->qrCode->generatePaymentQR(
            $wallet->wallet_address,
            (float) $request->amount,
            [
                'description' => $request->description,
                'currency' => 'MATIC',
            ]
        );

        if (!$result['success']) {
            return response()->json($result, 500);
        }

        return response()->json($result);
    }

    public function scanQRCode()
    {
        return view('payment.scan');
    }

    /**
     * server-side QR scan validation.
     * Input: qr_data (raw string dari scanner).
     * Output: mode static atau dynamic + decoded data.
     */
    public function scanQrApi(Request $request)
    {
        $request->validate([
            'qr_data' => 'required|string',
        ]);

        $raw = $request->input('qr_data');

        // 1. Coba plain JSON dulu (static QR).
        $maybeJson = json_decode($raw, true);
        if (is_array($maybeJson) && ($maybeJson['type'] ?? '') === 'wallet_address') {
            return response()->json([
                'success' => true,
                'mode' => 'static',
                'wallet_address' => $maybeJson['address'] ?? null,
                'receiver_name' => $maybeJson['name'] ?? null,
                'timestamp' => $maybeJson['timestamp'] ?? null,
            ]);
        }

        // 2. Coba decrypt (dynamic QR).
        $result = $this->qrCode->scanQRCode($raw);
        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['error'] ?? 'QR code tidak valid',
            ], 400);
        }

        $data = $result['data'];
        return response()->json([
            'success' => true,
            'mode' => 'dynamic',
            'code_id' => $data['code_id'] ?? null,
            'wallet_address' => $data['wallet_address'] ?? null,
            'amount' => $data['amount'] ?? null,
            'currency' => $data['currency'] ?? 'MATIC',
            'description' => $data['description'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
        ]);
    }


    /**
     * non-custodial relay.
     * Browser sign tx pakai key user (derive dari password), kirim hex ke sini,
     * server relay ke RPC. Server tidak punya/butuh private key.
     */
    public function relayRawTransaction(Request $request)
    {
        $request->validate([
            'raw_tx' => ['required', 'string', 'regex:/^0x[0-9a-fA-F]+$/'],
        ]);

        $rawTx = $request->input('raw_tx');
        $result = $this->polygon->sendRawTransaction($rawTx);

        if (empty($result['success'])) {
            Log::warning('[PaymentController] Relay raw tx failed', [
                'user_id' => Auth::id(),
                'error' => $result['error'] ?? 'unknown',
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Relay tx gagal: '.($result['error'] ?? 'unknown'),
            ], 502);
        }

        Log::info('[PaymentController] Raw tx relayed', [
            'user_id' => Auth::id(),
            'tx_hash' => $result['tx_hash'],
        ]);

        return response()->json([
            'success' => true,
            'tx_hash' => $result['tx_hash'],
        ]);
    }

    /**
     * Proxy RPC read-only untuk SCAN transfer masuk (eth_getLogs) + cek status
     * (eth_call isCommitmentActive). RPC publik gratis kini membatasi getLogs
     * historis (publicnode minta token archive; resmi/drpc batasi ~100 blok),
     * jadi scan butuh RPC ber-API-key (mis. Alchemy). Kunci itu disimpan HANYA
     * di server (config services.polygon.scan_rpc_url ← POLYGON_SCAN_RPC_URL) dan
     * TIDAK pernah dikirim ke peramban; browser memanggil route same-origin ini,
     * dekripsi memo tetap 100% di sisi klien.
     *
     * Bukan relay umum: hanya method read-only di-whitelist. Menulis tx tetap
     * lewat /payment/relay yang punya validasi sendiri.
     */
    public function scanRpc(Request $request)
    {
        $upstream = config('services.polygon.scan_rpc_url');
        if (empty($upstream)) {
            return response()->json(['error' => 'scan RPC upstream not configured'], 503);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return response()->json(['error' => 'invalid JSON-RPC payload'], 422);
        }

        $allowed = [
            'eth_chainId', 'net_version', 'eth_blockNumber',
            'eth_getLogs', 'eth_call', 'eth_getTransactionReceipt',
            'eth_getBlockByNumber',
        ];

        // Payload bisa objek tunggal atau batch (array of objek) — ethers v6
        // kadang membatch. Validasi method tiap entri sebelum diteruskan.
        $calls = array_is_list($payload) ? $payload : [$payload];
        foreach ($calls as $call) {
            $method = is_array($call) ? ($call['method'] ?? null) : null;
            if (!in_array($method, $allowed, true)) {
                return response()->json([
                    'error' => 'method not allowed: '.(is_string($method) ? $method : 'unknown'),
                ], 422);
            }
        }

        try {
            $resp = Http::timeout(20)
                ->withBody(json_encode($payload), 'application/json')
                ->post($upstream);
        } catch (\Throwable $e) {
            Log::warning('[PaymentController] scan RPC proxy gagal', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'upstream RPC unreachable'], 502);
        }

        return response($resp->body(), $resp->status())
            ->header('Content-Type', 'application/json');
    }

    /**
     * Catat transfer non-custodial (mode non-privat) ke riwayat.
     *
     * Jalur relay (/payment/relay) hanya broadcast raw tx — tidak punya konteks
     * penerima/nominal. Setelah relay sukses, browser memanggil endpoint ini
     * dengan metadata supaya transfer muncul di riwayat.
     *
     * Sifatnya INFORMATIF: kebenaran nominal/penerima tetap on-chain (tx_hash).
     * Karena itu endpoint TIDAK memutasi saldo DB (saldo cache disinkron dari RPC),
     * hanya membuat baris riwayat. Idempoten terhadap polygon_tx_hash.
     */
    public function recordRelayTransfer(Request $request)
    {
        $request->validate([
            'polygon_tx_hash' => ['required', 'string', 'regex:/^0x[0-9a-fA-F]{64}$/'],
            'receiver_address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'amount' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
        ]);

        $senderWallet = Auth::user()->wallet;

        // Idempotensi: kalau tx hash sudah tercatat, jangan dobel.
        $existing = Transaction::where('polygon_tx_hash', $request->polygon_tx_hash)->first();
        if ($existing) {
            return response()->json([
                'success' => true,
                'transaction' => $existing,
                'duplicate' => true,
            ]);
        }

        // Penerima boleh wallet XevouZK terdaftar (di-link) atau alamat eksternal.
        $receiverWallet = Wallet::where('wallet_address', $request->receiver_address)
            ->orWhere('polygon_address', $request->receiver_address)
            ->first();

        $transaction = Transaction::create([
            'sender_wallet_id' => $senderWallet->id,
            'receiver_wallet_id' => $receiverWallet?->id,
            'receiver_address' => $request->receiver_address,
            'amount' => (float) $request->amount,
            'transaction_hash' => hash('sha256', $senderWallet->wallet_address.$request->receiver_address.$request->amount.$request->polygon_tx_hash),
            'polygon_tx_hash' => $request->polygon_tx_hash,
            'status' => 'completed',
            'notes' => $request->notes,
        ]);

        Log::info('[PaymentController] Relay transfer recorded', [
            'user_id' => Auth::id(),
            'tx_hash' => $request->polygon_tx_hash,
        ]);

        return response()->json([
            'success' => true,
            'transaction' => $transaction,
        ]);
    }

    /**
     * Catat event pool ke riwayat: deposit, withdraw, transfer PRIVAT (kirim),
     * atau terima PRIVAT.
     *
     * Privasi (CLAUDE.md §3.2): untuk `private_transfer`/`private_receive`, server
     * TIDAK menyimpan nominal maupun counterparty — keduanya disembunyikan demi
     * klaim privasi TA. Server hanya mencatat bahwa tx terjadi (tx_hash on-chain).
     * Untuk deposit/withdraw, nominal & alamat memang publik di explorer sehingga
     * aman dicatat penuh.
     *
     * Arah:
     *  - deposit/withdraw/private_transfer → pelaku = PENGIRIM (sender_wallet_id).
     *  - private_receive → pelaku = PENERIMA (receiver_wallet_id); sender dibiarkan
     *    null (penerima tidak tahu wallet pengirim, hanya menemukan note via scan).
     *
     * PRIVASI penerima (gap §3.I, mitigasi M1): baris `private_receive` **TIDAK**
     * menyimpan `polygon_tx_hash` — kalau disimpan, pihak ber-akses DB bisa JOIN
     * ke baris `private_transfer` pengirim (tx_hash sama) → menautkan pengirim↔
     * penerima. Sebagai gantinya idempotensi pakai `receipt_ref` opaque dari client
     * (turunan salt rahasia note: sha256(commitment‖salt)). Salt tak pernah ada
     * on-chain (terenkripsi di memo ECIES), jadi receipt_ref tak bisa direkomputasi
     * / ditautkan oleh siapa pun yang hanya punya DB + blockchain.
     *
     * Informatif: kebenaran tetap on-chain.
     */
    public function recordPoolEvent(Request $request)
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:deposit,withdraw,private_transfer,private_receive'],
            'polygon_tx_hash' => ['nullable', 'string', 'regex:/^0x[0-9a-fA-F]{64}$/'],
            'receipt_ref' => ['nullable', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'receiver_address' => ['nullable', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
        ]);

        $type = $validated['type'];
        $wallet = Auth::user()->wallet;

        // PRIVASI: transfer privat (kirim/terima) → nominal & counterparty TIDAK
        // disimpan (diabaikan walau dikirim). Deposit/withdraw → data publik, penuh.
        $isPrivate = in_array($type, ['private_transfer', 'private_receive'], true);
        $isReceive = $type === 'private_receive';
        $amount = $isPrivate ? null : ($validated['amount'] ?? null);
        $receiver = $isPrivate ? null : ($validated['receiver_address'] ?? null);

        // Kunci idempotensi (= transaction_hash, unik):
        //  - PENERIMA privat → receipt_ref opaque dari client (TIDAK menyimpan tx_hash).
        //  - lainnya → hash(type|wallet|polygon_tx_hash); per (pelaku,type,tx) unik.
        if ($isReceive) {
            if (empty($validated['receipt_ref'])) {
                return response()->json(['success' => false, 'message' => 'receipt_ref wajib untuk private_receive'], 422);
            }
            $txHash = $validated['receipt_ref'];
        } else {
            if (empty($validated['polygon_tx_hash'])) {
                return response()->json(['success' => false, 'message' => 'polygon_tx_hash wajib'], 422);
            }
            $txHash = hash('sha256', $type.'|'.$wallet->id.'|'.$validated['polygon_tx_hash']);
        }

        $existing = Transaction::where('transaction_hash', $txHash)->first();
        if ($existing) {
            return response()->json(['success' => true, 'transaction' => $existing, 'duplicate' => true]);
        }

        $transaction = Transaction::create([
            'type' => $type,
            // private_receive → user adalah PENERIMA; lainnya → user adalah PENGIRIM.
            'sender_wallet_id' => $isReceive ? null : $wallet->id,
            'receiver_wallet_id' => $isReceive ? $wallet->id : null,
            'receiver_address' => $receiver,
            'amount' => $amount,
            'transaction_hash' => $txHash,
            // PENERIMA privat: TIDAK simpan polygon_tx_hash (memutus link S↔R di DB).
            'polygon_tx_hash' => $isReceive ? null : $validated['polygon_tx_hash'],
            'status' => 'completed',
        ]);

        Log::info('[PaymentController] Pool event recorded', [
            'user_id' => Auth::id(),
            'type' => $type,
            // Untuk penerima privat tidak ada tx_hash yang dicatat (privasi).
            'ref' => $isReceive ? 'receipt_ref(opaque)' : $validated['polygon_tx_hash'],
            'amount_hidden' => $isPrivate,
        ]);

        return response()->json(['success' => true, 'transaction' => $transaction]);
    }

    /**
     * Preview / sanity check withdraw proof sebelum
     * browser submit ke kontrak. Server verify proof struct + nullifier guard
     * supaya user tidak bayar gas untuk tx yang akan revert on-chain.
     *
     * Endpoint TIDAK relay tx — relay tetap lewat /payment/relay.
     */
    public function previewWithdraw(Request $request)
    {
        $request->validate([
            'proof' => 'required|string',
        ]);

        $proof = $request->input('proof');
        $ok = $this->zkSnark->verifyWithdrawProof($proof);

        if (!$ok) {
            return response()->json([
                'success' => false,
                'message' => 'Withdraw proof invalid — kontrak akan tolak tx ini, jangan submit.',
            ], 400);
        }

        $publicInputs = $this->zkSnark->extractWithdrawPublicInputs($proof);

        Log::info('[PaymentController] Withdraw proof preview ok', [
            'user_id' => Auth::id(),
            'nullifier' => $publicInputs['nullifier'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'public_inputs' => $publicInputs,
        ]);
    }

    /**
     * Preview / sanity check private_transfer proof sebelum browser submit ke kontrak.
     * Server verify struct + nullifier guard supaya user tak bayar gas untuk tx yang revert.
     * TIDAK relay tx — relay tetap lewat /payment/relay.
     */
    public function previewTransfer(Request $request)
    {
        $request->validate(['proof' => 'required|string']);

        $proof = $request->input('proof');
        if (!$this->zkSnark->verifyTransferProof($proof)) {
            return response()->json([
                'success' => false,
                'message' => 'Transfer proof invalid — kontrak akan tolak tx ini, jangan submit.',
            ], 400);
        }

        $publicInputs = $this->zkSnark->extractTransferPublicInputs($proof);
        Log::info('[PaymentController] Transfer proof preview ok', [
            'user_id' => Auth::id(),
            'nullifier' => $publicInputs['nullifier'] ?? null,
        ]);

        return response()->json(['success' => true, 'public_inputs' => $publicInputs]);
    }

    public function transactionHistory()
    {
        $wallet = Auth::user()->wallet;

        $transactions = Transaction::where('sender_wallet_id', $wallet->id)
            ->orWhere('receiver_wallet_id', $wallet->id)
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return view('payment.history', compact('transactions', 'wallet'));
    }
}
