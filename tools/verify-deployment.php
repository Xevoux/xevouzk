<?php
// Smoke test SPEC-007: verify .env contract address + reachable via PolygonService.
// Usage: php tools/verify-deployment.php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$addr = config('services.polygon.contract_address');
echo "Configured POLYGON_CONTRACT_ADDRESS: $addr\n";

if (!$addr || stripos($addr, '0x') !== 0) {
    echo "✗ Address tidak valid\n";
    exit(1);
}

// Test reachable via raw eth_call ke ZKPayment.owner()
$rpcUrl = config('services.polygon.rpc_url');
echo "RPC URL: $rpcUrl\n";

// keccak256("owner()") prefix 4 bytes = 0x8da5cb5b
$payload = [
    'jsonrpc' => '2.0',
    'method' => 'eth_call',
    'params' => [
        ['to' => $addr, 'data' => '0x8da5cb5b'],
        'latest',
    ],
    'id' => 1,
];

$ch = curl_init($rpcUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
$response = curl_exec($ch);
curl_close($ch);

$json = json_decode($response, true);
if (!empty($json['result']) && strlen($json['result']) >= 42) {
    // owner address di 32 byte terakhir, ambil 20 byte terakhir = 40 hex
    $owner = '0x'.substr($json['result'], -40);
    echo "✓ Contract reachable. Owner = $owner\n";
    echo "✓ Expected owner = 0x16a747E428a954328bd3cb67963fa85f4175E6a4\n";
    exit(0);
}

echo "✗ Call gagal. Response: $response\n";
exit(2);
