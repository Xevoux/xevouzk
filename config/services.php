<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Polygon Blockchain Configuration
    |--------------------------------------------------------------------------
    |
    | Konfigurasi koneksi & alamat on-chain untuk PolygonService. Semua nilai
    | berasal dari .env (jangan hardcode key/alamat di sini). Target jaringan:
    | Polygon Amoy Testnet (chain id 80002).
    |
    */
    'polygon' => [
        // Penanda jaringan aktif ('testnet'/'mainnet'). Dipakai untuk label UI
        // & guard logika, bukan untuk koneksi langsung.
        'network' => env('POLYGON_NETWORK', 'testnet'),

        // Endpoint JSON-RPC. Semua eth_call / eth_sendRawTransaction / cek saldo
        // lewat URL ini. Default Amoy publik (rawan timeout saat ramai).
        'rpc_url' => env('POLYGON_RPC_URL', 'https://rpc-amoy.polygon.technology/'),

        // RPC UPSTREAM untuk SCAN dana masuk (eth_getLogs historis). Dipakai
        // HANYA oleh proxy server PaymentController::scanRpc — URL ini (berisi
        // API key) tak pernah dikirim ke peramban. RPC publik gratis kini
        // membatasi getLogs historis (publicnode minta token archive; resmi/drpc
        // batasi ~100 blok), jadi set POLYGON_SCAN_RPC_URL ke RPC ber-API-key
        // (mis. Alchemy: https://polygon-amoy.g.alchemy.com/v2/<KEY>). Default
        // publicnode dibiarkan agar tak crash, tapi praktis sudah tak melayani.
        'scan_rpc_url' => env('POLYGON_SCAN_RPC_URL', 'https://polygon-amoy-bor-rpc.publicnode.com'),

        // Chain ID EIP-155 (Amoy = 80002). Diikat ke tanda tangan tx agar tidak
        // bisa di-replay di jaringan lain.
        'chain_id' => env('POLYGON_CHAIN_ID', 80002),

        // Alamat kontrak utama ZKPayment.sol: pool deposit/transfer/withdraw +
        // registry nullifier (pencegahan double-spend).
        'contract_address' => env('POLYGON_CONTRACT_ADDRESS'),

        // Blok deploy kontrak ZKPayment — lantai (floor) untuk scan event
        // EncryptedNote saat penerima mencari dana masuk. Tanpa ini scan mulai
        // dari blok 0 (puluhan juta blok di Amoy → praktis menggantung / rate-limit).
        'contract_deploy_block' => env('POLYGON_CONTRACT_DEPLOY_BLOCK', 0),

        // Groth16Verifier untuk sirkuit balance_check (bukti saldo cukup).
        'balance_verifier_address' => env('POLYGON_BALANCE_VERIFIER_ADDRESS'),

        // Groth16Verifier untuk sirkuit private_transfer (transfer pool privat).
        'transfer_verifier_address' => env('POLYGON_TRANSFER_VERIFIER_ADDRESS'),

        // Groth16Verifier untuk sirkuit withdraw (tarik dari pool ke alamat publik).
        'withdraw_verifier_address' => env('POLYGON_WITHDRAW_VERIFIER_ADDRESS'),

        // Private key wallet "panas" master (server-side). Penandatangan tx
        // outbound & sumber dana faucet. RAHASIA — hanya dari .env.
        'private_key' => env('POLYGON_PRIVATE_KEY'),

        // Alamat publik wallet master (pasangan dari private_key di atas).
        // Sumber MATIC FaucetService & pengirim tx PolygonService.
        'master_wallet' => env('POLYGON_MASTER_WALLET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | ZK-SNARK Configuration
    |--------------------------------------------------------------------------
    */
    'zk_snark' => [
        'enabled' => env('ZK_ENABLED', true),
        'circuit_path' => env('ZK_CIRCUIT_PATH', storage_path('circuits/')),
        'proving_key_path' => env('ZK_PROVING_KEY_PATH', storage_path('keys/proving_key.json')),
        'verification_key_path' => env('ZK_VERIFICATION_KEY_PATH', storage_path('keys/verification_key.json')),
    ],

    /*
    |--------------------------------------------------------------------------
    | QR Code Configuration
    |--------------------------------------------------------------------------
    */
    'qr_code' => [
        'size' => env('QR_CODE_SIZE', 300),
        'format' => env('QR_CODE_FORMAT', 'png'),
        'error_correction' => env('QR_CODE_ERROR_CORRECTION', 'M'),
    ],

];
