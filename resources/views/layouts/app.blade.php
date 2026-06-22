<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Schnorr pubkey akun (publik) → anchor verifikasi password sisi-klien (account-guard.js) --}}
    <meta name="account-schnorr-pub" content="{{ optional(auth()->user())->zk_public_key }}">
    {{-- alamat kontrak ZKPayment v2 dipakai oleh polygon-deposit.js + polygon-withdraw.js --}}
    <meta name="zk-payment-contract" content="{{ env('POLYGON_CONTRACT_ADDRESS', '') }}">
    {{-- blok deploy kontrak: lantai scan event EncryptedNote (terima dana privat) --}}
    <meta name="zk-payment-deploy-block" content="{{ config('services.polygon.contract_deploy_block', 0) }}">
    {{-- RPC khusus SCAN transfer masuk (eth_getLogs). PATH relatif → browser
         menambah origin-nya sendiri (lihat dashboard), jadi tahan-tunnel: di Herd
         Share/HP otomatis ikut domain publik, bukan host lokal. Proxy same-origin
         ini menyimpan API key Alchemy di server, tak pernah ke peramban.
         Lihat PaymentController::scanRpc. --}}
    <meta name="zk-scan-rpc-url" content="/payment/scan-rpc">
    <title>@yield('title', 'XevouZK — Pembayaran Digital Privat')</title>

    {{-- Buffer/process polyfill kini disediakan vite-plugin-node-polyfills di
         dalam bundle (circomlibjs). Tidak perlu lagi shim global manual. --}}

    <link rel="icon" type="image/png" href="{{ asset('LogoXevouZK.png') }}">

    {{-- Google Fonts: Sora (display+body) + JetBrains Mono (hash/address/code) --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;700&display=swap">

    @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/js/vendor-lucide.js', 'resources/js/zk-snark.js', 'resources/js/account-guard.js', 'resources/js/note-backup.js'])
    @stack('styles')
</head>
<body>
    <div class="app-container">
        @include('layouts.partials.navbar')

        <main class="main-content">
            @if(session('success'))
                <div class="alert alert-success">
                    <i data-lucide="check-circle"></i>
                    <span>{{ session('success') }}</span>
                </div>
            @endif

            @if(session('warning'))
                <div class="alert alert-warning">
                    <i data-lucide="triangle-alert"></i>
                    <span>{{ session('warning') }}</span>
                </div>
            @endif

            @if(session('error'))
                <div class="alert alert-error">
                    <i data-lucide="alert-circle"></i>
                    <span>{{ session('error') }}</span>
                </div>
            @endif

            @yield('content')
        </main>

        <footer class="footer">
            <p>XEVOUZK · ZERO-KNOWLEDGE PAYMENT · POLYGON AMOY · &copy; {{ date('Y') }}</p>
        </footer>
    </div>

    {{-- Lucide icons dimuat via Vite bundle (resources/js/vendor-lucide.js) di
         <head>; bridge itu set window.lucide + render ikon saat load. --}}

    {{-- snarkjs kini di-bundle Vite via resources/js/zk-snark.js (import langsung),
         dimuat di <head> @vite. Tidak ada lagi UMD global dari public/vendor. --}}

    <script src="{{ asset('js/app.js') }}"></script>
    {{-- Live updates: refresh dinamis saldo/jaringan/riwayat/faucet tanpa reload --}}
    <script src="{{ asset('js/live-updates.js') }}"></script>
    @stack('scripts')
</body>
</html>
