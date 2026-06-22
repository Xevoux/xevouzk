<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Proxy RPC scan dipanggil ethers (JsonRpcProvider) yang tak mengirim
        // token CSRF. Aman dikecualikan: route butuh auth (cookie sesi) & hanya
        // meneruskan method RPC read-only ke upstream. Lihat PaymentController::scanRpc.
        $middleware->validateCsrfTokens(except: [
            'payment/scan-rpc',
        ]);

        // Di belakang tunnel (ngrok) app diakses lewat domain publik. Percayai
        // header X-Forwarded-* dari proxy agar url()/route()/redirect dan deteksi
        // HTTPS memakai domain publik (bukan host lokal) → login & aset tak rusak
        // di HP. '*' aman untuk dev testnet lokal (hanya dijangkau via tunnel/localhost).
        $middleware->trustProxies(at: '*', headers:
            Request::HEADER_X_FORWARDED_FOR |
            Request::HEADER_X_FORWARDED_HOST |
            Request::HEADER_X_FORWARDED_PORT |
            Request::HEADER_X_FORWARDED_PROTO
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
