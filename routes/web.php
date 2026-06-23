<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login');
});

// Authentication Routes
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    // throttle:10,1 = lapisan HTTP kasar (per-IP). Login juga punya rate-limit
    // per-(email+IP) di controller; register dibatasi untuk cegah spam akun.
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
});

Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Protected Routes
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Live state — satu endpoint untuk refresh dinamis tanpa reload.
    // ?chain=1 memaksa sync on-chain; default (chain=0) DB-only.
    Route::get('/live/state', [\App\Http\Controllers\WalletController::class, 'liveState'])->name('live.state');

    // Publish shielded keypair public (Poseidon) saat login
    Route::post('/pubkeys', [\App\Http\Controllers\WalletController::class, 'publishPubkeys'])->name('pubkeys.publish');

    // Backup note terenkripsi (lintas device). Server simpan ciphertext opaque saja.
    Route::post('/notes/backup', [\App\Http\Controllers\NoteBackupController::class, 'store'])
        ->middleware('throttle:60,1')->name('notes.backup.store');
    Route::get('/notes/backup', [\App\Http\Controllers\NoteBackupController::class, 'index'])
        ->name('notes.backup.index');

    // Wallet Routes
    Route::prefix('wallet')->name('wallet.')->group(function () {
        Route::get('/', [\App\Http\Controllers\WalletController::class, 'index'])->name('index');
        Route::post('/generate-receive-qr', [\App\Http\Controllers\WalletController::class, 'generateReceiveQR'])->name('generate-receive-qr');
        Route::post('/decode-qr', [\App\Http\Controllers\WalletController::class, 'decodeQR'])->name('decode-qr');
        Route::get('/download-qr', [\App\Http\Controllers\WalletController::class, 'downloadQR'])->name('download-qr');
        Route::get('/info', [\App\Http\Controllers\WalletController::class, 'getInfo'])->name('info');

        // Balance sync
        Route::get('/balance/sync', [\App\Http\Controllers\WalletController::class, 'syncBalance'])->name('balance.sync');
        Route::get('/balance/realtime', [\App\Http\Controllers\WalletController::class, 'getRealTimeBalance'])->name('balance.realtime');
        
        // Faucet routes
        // throttle:5,1 = 5 request per menit (lapisan HTTP di atas cooldown 24h
        // per-user di FaucetService).
        Route::post('/faucet/request', [\App\Http\Controllers\WalletController::class, 'requestTestMatic'])
            ->middleware('throttle:5,1')
            ->name('faucet.request');
        Route::get('/faucet/history', [\App\Http\Controllers\WalletController::class, 'getFaucetHistory'])->name('faucet.history');
        Route::get('/faucet/can-request', [\App\Http\Controllers\WalletController::class, 'canRequestTestMatic'])->name('faucet.can-request');
    });
    
    // Payment Routes
    Route::get('/payment', [PaymentController::class, 'showPaymentForm'])->name('payment.form');
    Route::post('/payment/generate-qr', [PaymentController::class, 'generateQRCode'])->name('payment.generate-qr');
    Route::get('/payment/scan', [PaymentController::class, 'scanQRCode'])->name('payment.scan');
    Route::post('/payment/relay', [PaymentController::class, 'relayRawTransaction'])->name('payment.relay');
    // Proxy RPC read-only utk scan transfer masuk (eth_getLogs/eth_call) — kunci
    // API upstream tetap di server, tak pernah ke browser. Lihat scanRpc().
    Route::post('/payment/scan-rpc', [PaymentController::class, 'scanRpc'])->name('payment.scan-rpc');
    Route::post('/payment/record-relay', [PaymentController::class, 'recordRelayTransfer'])->name('payment.record-relay');
    Route::post('/payment/record-event', [PaymentController::class, 'recordPoolEvent'])->name('payment.record-event');
    Route::post('/payment/qr/scan', [PaymentController::class, 'scanQrApi'])->name('payment.qr.scan');
    Route::get('/payment/history', [PaymentController::class, 'transactionHistory'])->name('payment.history');
});
