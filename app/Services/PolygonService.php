<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Web3\Web3;
use Web3\Contract;
use Web3\Utils;
use kornrunner\Keccak;
use kornrunner\Ethereum\Transaction;
use Elliptic\EC;

/**
 * Polygon Blockchain Service
 * Service untuk interaksi dengan Polygon Network
 * Uses proper secp256k1 cryptography for wallet generation
 */
class PolygonService
{
    private $rpcUrl;
    private $chainId;
    private $contractAddress;
    private $privateKey;
    private $web3;
    private $eth;
    private $ec;

    public function __construct()
    {
        $this->rpcUrl = config('services.polygon.rpc_url', env('POLYGON_RPC_URL', 'https://rpc-amoy.polygon.technology/'));
        $this->chainId = config('services.polygon.chain_id', env('POLYGON_CHAIN_ID', '80002'));
        $this->contractAddress = config('services.polygon.contract_address', env('POLYGON_CONTRACT_ADDRESS'));
        $this->privateKey = config('services.polygon.private_key', env('POLYGON_PRIVATE_KEY'));
        
        // Initialize secp256k1 elliptic curve
        $this->ec = new EC('secp256k1');
        
        // Initialize Web3
        $this->initializeWeb3();
    }
    
    /**
     * Initialize Web3 instance
     */
    private function initializeWeb3()
    {
        try {
            $this->web3 = new Web3($this->rpcUrl);
            $this->eth = $this->web3->eth;
            Log::info('[PolygonService] Web3 initialized successfully');
        } catch (\Exception $e) {
            Log::error('[PolygonService] Failed to initialize Web3: ' . $e->getMessage());
            $this->web3 = null;
            $this->eth = null;
        }
    }

    /**
     * Relay signed raw transaction ke RPC.
     * Browser sign tx (pakai ethers.js) → kirim hex string ke endpoint ini
     * → eth_sendRawTransaction ke RPC node Polygon Amoy.
     *
     * Server tidak punya private key user — kalau tx invalid (saldo kurang,
     * nonce salah, signature invalid) RPC akan tolak, kita relay error itu.
     */
    public function sendRawTransaction(string $rawTx): array
    {
        if (!str_starts_with($rawTx, '0x') || !ctype_xdigit(substr($rawTx, 2))) {
            return [
                'success' => false,
                'error' => 'rawTx harus hex string ber-prefix 0x',
            ];
        }

        try {
            $response = Http::timeout(15)->post($this->rpcUrl, [
                'jsonrpc' => '2.0',
                'method' => 'eth_sendRawTransaction',
                'params' => [$rawTx],
                'id' => 1,
            ]);

            if (!$response->ok()) {
                return [
                    'success' => false,
                    'error' => 'RPC HTTP '.$response->status(),
                ];
            }

            $body = $response->json();
            if (isset($body['error'])) {
                $msg = $body['error']['message'] ?? 'RPC error';
                Log::warning('[PolygonService] sendRawTransaction RPC error', ['error' => $body['error']]);
                return [
                    'success' => false,
                    'error' => $msg,
                ];
            }

            if (!isset($body['result'])) {
                return [
                    'success' => false,
                    'error' => 'RPC response tidak punya field result',
                ];
            }

            Log::info('[PolygonService] sendRawTransaction relayed', ['tx_hash' => $body['result']]);
            return [
                'success' => true,
                'tx_hash' => $body['result'],
            ];
        } catch (\Throwable $e) {
            Log::error('[PolygonService] sendRawTransaction exception: '.$e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Convert address to EIP-55 checksum format
     * This makes addresses compatible with MetaMask and other wallets
     */
    private function toChecksumAddress($address)
    {
        $address = strtolower(str_replace('0x', '', $address));
        $hash = Keccak::hash($address, 256);
        
        $checksumAddress = '0x';
        for ($i = 0; $i < 40; $i++) {
            if (intval($hash[$i], 16) >= 8) {
                $checksumAddress .= strtoupper($address[$i]);
            } else {
                $checksumAddress .= $address[$i];
            }
        }
        
        return $checksumAddress;
    }

    /**
     * Verify that a private key is valid and matches an address
     */
    public function verifyPrivateKey($privateKey, $expectedAddress)
    {
        try {
            $keyPair = $this->ec->keyFromPrivate($privateKey);
            $publicKeyPoint = $keyPair->getPublic();
            
            $publicKeyX = str_pad($publicKeyPoint->getX()->toString(16), 64, '0', STR_PAD_LEFT);
            $publicKeyY = str_pad($publicKeyPoint->getY()->toString(16), 64, '0', STR_PAD_LEFT);
            
            $hash = Keccak::hash(hex2bin($publicKeyX . $publicKeyY), 256);
            $derivedAddress = '0x' . substr($hash, -40);
            
            return strtolower($derivedAddress) === strtolower($expectedAddress);
            
        } catch (\Exception $e) {
            Log::error('[PolygonService] Private key verification failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Sign a message with private key
     */
    public function signMessage($message, $privateKey)
    {
        try {
            $keyPair = $this->ec->keyFromPrivate($privateKey);
            $messageHash = Keccak::hash($message, 256);
            
            $signature = $keyPair->sign($messageHash, ['canonical' => true]);
            
            $r = str_pad($signature->r->toString(16), 64, '0', STR_PAD_LEFT);
            $s = str_pad($signature->s->toString(16), 64, '0', STR_PAD_LEFT);
            $v = dechex($signature->recoveryParam + 27);
            
            return [
                'success' => true,
                'signature' => '0x' . $r . $s . $v,
                'r' => '0x' . $r,
                's' => '0x' . $s,
                'v' => '0x' . $v,
            ];
            
        } catch (\Exception $e) {
            Log::error('[PolygonService] Message signing failed: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send transaction to Polygon
     */
    public function sendTransaction($to, $amount, $data = null)
    {
        try {
            // Get master wallet address
            $masterWallet = env('POLYGON_MASTER_WALLET');

            if (!$masterWallet) {
                Log::error('[PolygonService] No master wallet configured');
                return null;
            }

            if (!$this->privateKey) {
                Log::error('[PolygonService] No private key configured');
                return null;
            }

            // Convert amount to wei
            $amountInWei = $this->toWei($amount);

            // Get nonce for master wallet
            $nonce = $this->getTransactionCount($masterWallet);

            // Build transaction
            $transaction = [
                'from' => $masterWallet,
                'to' => $to,
                'value' => $amountInWei,
                'gas' => '0x5208', // 21000
                'gasPrice' => $this->getGasPrice(),
                'chainId' => $this->chainId,
                'nonce' => '0x' . dechex($nonce),
            ];

            if ($data) {
                $transaction['data'] = $data;
            }

            // Sign transaction
            $signedTx = $this->signTransaction($transaction);

            // Send via RPC
            $response = $this->rpcCall('eth_sendRawTransaction', [$signedTx]);

            if (isset($response['result'])) {
                Log::info('Transaction sent to Polygon: ' . $response['result']);
                return $response['result'];
            }

            Log::error('Failed to send transaction: ' . json_encode($response));
            return null;

        } catch (\Exception $e) {
            Log::error('Polygon Transaction Error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get transaction receipt
     */
    public function getTransactionReceipt($txHash)
    {
        try {
            $response = $this->rpcCall('eth_getTransactionReceipt', [$txHash]);
            
            if (isset($response['result'])) {
                return $response['result'];
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Get Receipt Error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get balance using Web3
     */
    public function getBalance($address)
    {
        try {
            if ($this->eth) {
                // Use Web3.php
                $balance = null;
                $this->eth->getBalance($address, 'latest', function ($err, $result) use (&$balance) {
                    if ($err !== null) {
                        throw new \Exception($err->getMessage());
                    }
                    $balance = $result;
                });
                
                if ($balance) {
                    return $this->fromWei($balance->toString());
                }
            }
            
            // Fallback to RPC
            $response = $this->rpcCall('eth_getBalance', [$address, 'latest']);
            
            if (isset($response['result'])) {
                // Convert from wei to ether
                return $this->fromWei(hexdec($response['result']));
            }

            return 0;
        } catch (\Exception $e) {
            Log::error('Get Balance Error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get gas price (hex wei).
     *
     * Polygon Amoy menolak transaksi dengan priority fee di bawah minimum
     * "gas price below minimum: gas tip cap 1500000000, minimum needed 25000000000"
     * Untuk transaksi legacy, `gasPrice` berlaku sebagai tip, jadi kita beri
     * lantai (floor) 30 gwei = 25 gwei minimum Amoy + headroom. Tanpa ini,
     * harga dari `eth_gasPrice` (sering ~1.5-2.5 gwei) akan ditolak node dan
     * faucet/transferMatic gagal.
     */
    public function getGasPrice()
    {
        // 30 gwei. Floor untuk Amoy (chainId 80002); aman juga untuk jaringan lain.
        $minGasPrice = 30000000000;

        try {
            $response = $this->rpcCall('eth_gasPrice', []);

            $rpcGasPrice = isset($response['result']) ? (int) hexdec($response['result']) : 0;

            // Beri buffer 25% di atas harga RPC, lalu pastikan tidak di bawah floor.
            $gasPrice = max((int) ($rpcGasPrice * 1.25), $minGasPrice);

            return '0x' . dechex($gasPrice);
        } catch (\Exception $e) {
            Log::error('Get Gas Price Error: ' . $e->getMessage());
            return '0x' . dechex($minGasPrice);
        }
    }

    /**
     * Kirim transaksi write ke smart contract.
     *
     * MVP behavior: jika `$this->web3` belum siap, `$this->contractAddress` kosong,
     * atau ABI tidak tersedia, return graceful failure agar layer atas
     * (PaymentController) bisa fallback / log warning. Implementasi pairing &
     * encoding lengkap dijadwalkan saat trusted setup + deployment selesai
     * (/ dokumen out-of-scope §6).
     *
     * Production usage
     * $polygon->sendContractTransaction(
     * $contractAddress,
     * 'privateTransfer(uint256[2],uint256[2][2],uint256[2],uint256[4],bytes)',
     * [$a, $b, $c, $pubSignals, $encryptedNote]
     *);
     */
    public function sendContractTransaction(string $contractAddress, string $methodSignature, array $params): array
    {
        try {
            if (!$this->web3 || !$contractAddress || !$this->privateKey) {
                Log::warning('[PolygonService] sendContractTransaction skipped — config incomplete', [
                    'has_web3' => (bool) $this->web3,
                    'has_address' => (bool) $contractAddress,
                    'has_key' => (bool) $this->privateKey,
                ]);
                return [
                    'success' => false,
                    'error' => 'Contract or signer not configured (deployment pending)',
                ];
            }

            // Encode function call data: 4-byte selector + ABI-encoded params.
            // Sederhana: gunakan keccak256(methodSignature) prefix + manual encode.
            // Untuk pembayaran zk-SNARK yang struktur paramnya kompleks (proof + pubSignals),
            // production sebaiknya gunakan Web3\Contract instance dari ABI JSON.
            $selector = '0x'.substr(\kornrunner\Keccak::hash($methodSignature, 256), 0, 8);
            $encodedParams = $this->encodeContractParams($params);
            $data = $selector.$encodedParams;

            $result = $this->sendTransaction($contractAddress, 0, $data);

            if (!$result) {
                return [
                    'success' => false,
                    'error' => 'Transaction broadcast failed',
                ];
            }

            return [
                'success' => true,
                'tx_hash' => is_array($result) ? ($result['tx_hash'] ?? '0x') : $result,
            ];
        } catch (\Exception $e) {
            Log::error('[PolygonService] sendContractTransaction error: '.$e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * ABI-encode parameter list (sangat sederhana, hanya support uint256[N] / nested).
     * Untuk struct kompleks (proof Groth16) gunakan Web3\Contract dari ABI JSON.
     */
    private function encodeContractParams(array $params): string
    {
        $out = '';
        foreach ($params as $p) {
            if (is_array($p)) {
                $out .= $this->encodeContractParams($p);
            } elseif (is_int($p) || is_string($p)) {
                $hex = is_string($p) && str_starts_with(strtolower($p), '0x')
                    ? substr($p, 2)
                    : dechex((int) $p);
                $out .= str_pad($hex, 64, '0', STR_PAD_LEFT);
            }
        }
        return $out;
    }

    /**
     * Call smart contract method
     */
    public function callContract($method, $params = [])
    {
        try {
            // Encode function call
            $data = $this->encodeFunctionCall($method, $params);

            $transaction = [
                'to' => $this->contractAddress,
                'data' => $data,
            ];

            $response = $this->rpcCall('eth_call', [$transaction, 'latest']);

            if (isset($response['result'])) {
                return $this->decodeResult($response['result']);
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Contract Call Error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * RPC Call to Polygon
     */
    private function rpcCall($method, $params = [])
    {
        $payload = [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
            'id' => time(),
        ];

        $response = Http::timeout(30)
            ->post($this->rpcUrl, $payload);

        if ($response->successful()) {
            return $response->json();
        }

        throw new \Exception('RPC call failed: ' . $response->body());
    }

    /**
     * Sign transaction using ethereum-offline-raw-tx library
     */
    private function signTransaction($transaction)
    {
        try {
            if (!$this->privateKey) {
                Log::error('[PolygonService] No private key configured');
                return null;
            }

            // Buang prefix 0x dari private key (getRaw() minta hex 1-64 karakter).
            $privateKey = preg_replace('/^0x/', '', $this->privateKey);

            // kornrunner\Ethereum\Transaction minta argumen POSISIONAL berupa
            // string hex (nonce, gasPrice, gasLimit, to, value, data) — BUKAN
            // associative array, dan BUKAN integer desimal. Semua field di
            // $transaction sudah berbentuk hex ('0x…') dari pemanggilnya, jadi
            // jangan di-hexdec() (merusak nilai wei besar & melempar TypeError).
            $transactionObj = new Transaction(
                $transaction['nonce'],
                $transaction['gasPrice'],
                $transaction['gas'],
                $transaction['to'],
                $transaction['value'],
                $transaction['data'] ?? ''
            );

            // getRaw() menandatangani (EIP-155 dengan chainId) dan mengembalikan
            // raw tx hex tanpa prefix. Method sign() di library bersifat private —
            // jangan dipanggil langsung.
            $signedTransaction = $transactionObj->getRaw($privateKey, (int) $transaction['chainId']);

            return '0x' . $signedTransaction;

        } catch (\Throwable $e) {
            // \Throwable (bukan hanya \Exception) supaya TypeError/Error dari
            // library kripto ikut tertangkap → tidak bocor jadi HTML 500 ke klien.
            Log::error('[PolygonService] Transaction signing error: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get transaction count (nonce)
     */
    public function getTransactionCount($address)
    {
        try {
            if ($this->eth && $address) {
                $count = null;
                $this->eth->getTransactionCount($address, 'pending', function ($err, $result) use (&$count) {
                    if ($err === null) {
                        $count = $result;
                    }
                });
                
                if ($count !== null) {
                    // web3p Eth::getTransactionCount mengembalikan BigInteger;
                    // toString()-nya sudah DESIMAL. Jangan hexdec() — itu salah
                    // tafsir, mis. nonce 10 → "10" → hexdec=16, bikin nonce gap
                    // sehingga tx nyangkut di mempool & tidak pernah ter-mine.
                    return (int) $count->toString();
                }
            }
            
            // Fallback
            $response = $this->rpcCall('eth_getTransactionCount', [$address, 'pending']);
            return isset($response['result']) ? hexdec($response['result']) : 0;
            
        } catch (\Exception $e) {
            Log::error('[PolygonService] Get transaction count error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Encode function call (simplified)
     */
    private function encodeFunctionCall($method, $params)
    {
        $methodSignature = substr(Keccak::hash($method, 256), 0, 8);
        $encodedParams = '';
        
        foreach ($params as $param) {
            if (is_numeric($param)) {
                $encodedParams .= str_pad(dechex($param), 64, '0', STR_PAD_LEFT);
            } elseif (strpos($param, '0x') === 0) {
                $encodedParams .= str_pad(substr($param, 2), 64, '0', STR_PAD_LEFT);
            } else {
                $encodedParams .= bin2hex($param);
            }
        }
        
        return '0x' . $methodSignature . $encodedParams;
    }

    /**
     * Decode result (simplified)
     */
    private function decodeResult($result)
    {
        return $result;
    }

    /**
     * Convert to Wei
     */
    private function toWei($amount)
    {
        // 1 ether = 10^18 wei
        $weiAmount = bcmul((string)$amount, '1000000000000000000', 0);
        return '0x' . $this->bcdechex($weiAmount);
    }

    /**
     * Convert large decimal to hex
     */
    private function bcdechex($dec)
    {
        $hex = '';
        do {
            $last = bcmod($dec, 16);
            $hex = dechex($last) . $hex;
            $dec = bcdiv(bcsub($dec, $last), 16);
        } while ($dec > 0);
        
        return $hex ?: '0';
    }

    /**
     * Convert wei → ether sebagai decimal string presisi 18.
     *
     * NB: web3p Utils::fromWei mengembalikan [quotient, remainder] BigInteger
     * array (lewat phpseclib BigInteger::divide), BUKAN scalar. Bila hasilnya
     * di-assign ke kolom Eloquent decimal cast, Brick\Math BigNumber::of
     * melempar TypeError ("array given"). Jadi pakai bcmath langsung — output
     * deterministik, presisi 18 desimal, dan aman buat $wallet->balance.
     */
    private function fromWei($wei)
    {
        return bcdiv((string) $wei, '1000000000000000000', 18);
    }
    
    /**
     * Create contract instance
     */
    public function getContract($abi)
    {
        try {
            if (!$this->web3 || !$this->contractAddress) {
                throw new \Exception('Web3 or contract address not configured');
            }
            
            $contract = new Contract($this->rpcUrl, $abi);
            $contract->at($this->contractAddress);
            
            return $contract;
            
        } catch (\Exception $e) {
            Log::error('[PolygonService] Contract initialization error: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Estimate gas for transaction
     */
    public function estimateGas($transaction)
    {
        try {
            if ($this->eth) {
                $gas = null;
                $this->eth->estimateGas($transaction, function ($err, $result) use (&$gas) {
                    if ($err === null) {
                        $gas = $result;
                    }
                });
                
                if ($gas !== null) {
                    // BigInteger->toString() sudah DESIMAL — sama seperti nonce,
                    // jangan hexdec() (lihat getTransactionCount).
                    return (int) $gas->toString();
                }
            }
            
            // Fallback to RPC
            $response = $this->rpcCall('eth_estimateGas', [$transaction]);
            return isset($response['result']) ? hexdec($response['result']) : 21000;
            
        } catch (\Exception $e) {
            Log::error('[PolygonService] Gas estimation error: ' . $e->getMessage());
            return 21000;
        }
    }

    /**
     * Verify transaction on blockchain
     */
    public function verifyTransaction($txHash)
    {
        try {
            $receipt = $this->getTransactionReceipt($txHash);
            
            if (!$receipt) {
                return [
                    'verified' => false,
                    'error' => 'Transaction not found',
                ];
            }

            return [
                'verified' => true,
                'status' => $receipt['status'] === '0x1' ? 'success' : 'failed',
                'blockNumber' => hexdec($receipt['blockNumber']),
                'gasUsed' => hexdec($receipt['gasUsed']),
            ];
        } catch (\Exception $e) {
            Log::error('Verify Transaction Error: ' . $e->getMessage());
            return [
                'verified' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get real-time balance from blockchain
     * Sync balance directly from Polygon network
     */
    public function getRealTimeBalance($address)
    {
        try {
            Log::info('[PolygonService] Getting real-time balance for: ' . $address);
            
            $balance = $this->getBalance($address);
            
            return [
                'success' => true,
                'balance' => $balance,
                'address' => $address,
                'network' => 'Polygon Amoy Testnet',
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            Log::error('[PolygonService] Real-time balance error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'balance' => 0,
            ];
        }
    }

    /**
     * Transfer MATIC to wallet (for top-up)
     */
    public function transferMatic($toAddress, $amount)
    {
        try {
            Log::info('[PolygonService] Transferring MATIC', [
                'to' => $toAddress,
                'amount' => $amount,
            ]);

            // Get master wallet address
            $masterWallet = env('POLYGON_MASTER_WALLET');
            
            if (!$masterWallet) {
                Log::warning('[PolygonService] No master wallet configured, using simulation mode');
                // Simulation mode - return fake tx hash
                $txHash = '0x' . bin2hex(random_bytes(32));
                
                return [
                    'success' => true,
                    'tx_hash' => $txHash,
                    'amount' => $amount,
                    'to' => $toAddress,
                    'simulation' => true,
                ];
            }

            // Send transaction
            $txHash = $this->sendTransaction($toAddress, $amount);
            
            if ($txHash) {
                return [
                    'success' => true,
                    'tx_hash' => $txHash,
                    'amount' => $amount,
                    'to' => $toAddress,
                    'simulation' => false,
                ];
            }

            return [
                'success' => false,
                'error' => 'Failed to send transaction',
            ];

        } catch (\Exception $e) {
            Log::error('[PolygonService] Transfer error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Sync wallet balance from blockchain and update database
     */
    public function syncWalletBalance($walletAddress)
    {
        try {
            $balanceData = $this->getRealTimeBalance($walletAddress);

            if ($balanceData['success']) {
                $wallet = \App\Models\Wallet::where('polygon_address', $walletAddress)->first();

                if ($wallet) {
                    // Defense-in-depth: pastikan scalar sebelum di-assign ke
                    // decimal cast. Bila ada bug upstream yang mengembalikan
                    // array/object, log + abort sync — JANGAN biarkan
                    // Brick\Math TypeError lolos ke user.
                    $balance = $balanceData['balance'];
                    if (!is_scalar($balance)) {
                        Log::error('[PolygonService] Bad balance shape from RPC', [
                            'wallet_id' => $wallet->id,
                            'type' => gettype($balance),
                        ]);
                        return [
                            'success' => false,
                            'error' => 'Invalid balance shape from RPC',
                        ];
                    }

                    $wallet->balance = $balance;
                    $wallet->last_sync_at = now();
                    $wallet->save();

                    Log::info('[PolygonService] Wallet balance synced', [
                        'wallet_id' => $wallet->id,
                        'balance' => $balance,
                    ]);
                }

                return $balanceData;
            }

            return $balanceData;
        } catch (\Throwable $e) {
            Log::error('[PolygonService] Sync error: ' . $e->getMessage(), [
                'exception' => get_class($e),
            ]);
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Validate Polygon address format
     */
    public function isValidAddress($address)
    {
        return preg_match('/^0x[a-fA-F0-9]{40}$/', $address) === 1;
    }

    /**
     * Get network info
     */
    public function getNetworkInfo()
    {
        return [
            'rpc_url' => $this->rpcUrl,
            'chain_id' => $this->chainId,
            'contract_address' => $this->contractAddress,
            'network_name' => $this->chainId == '137' ? 'Polygon Mainnet' : 'Polygon Amoy Testnet',
        ];
    }
}
