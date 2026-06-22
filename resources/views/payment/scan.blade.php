@extends('layouts.app')

@section('title', 'Scan QR — XevouZK')

@section('content')
<div class="scan-container">
    <header class="scan-header">
        <span class="page-header__eyebrow">SCAN · QR PAYMENT</span>
        <h1><i data-lucide="qr-code"></i> Scan QR Code</h1>
        <p>Arahkan kamera ke QR Code untuk melakukan pembayaran, atau gunakan input manual.</p>
    </header>

    <div class="scan-content">
        <div class="scan-left-panel">
            <section class="scanner-card">
                <div class="scanner-wrapper">
                    <div id="reader"></div>
                    <div id="manualInput" style="display: none; width: 100%;">
                        <h3>Input Manual</h3>
                        <textarea id="qrDataInput" class="qr-input" placeholder="Paste data QR code di sini..."></textarea>
                        <button class="btn btn--primary" onclick="processManualInput()">
                            <i data-lucide="check"></i> Proses
                        </button>
                    </div>
                </div>

                <div class="scanner-actions">
                    <button class="btn btn--ghost" onclick="toggleManualInput()">
                        <i data-lucide="keyboard"></i> Input Manual
                    </button>
                    <button class="btn btn--ghost" onclick="stopScanner()">
                        <i data-lucide="square"></i> Berhenti
                    </button>
                </div>
            </section>

            <section id="paymentConfirmation" class="payment-confirmation" style="display: none;">
                <h2>Konfirmasi Pembayaran <span id="modeBadge" class="badge"></span></h2>

                <div class="confirmation-details">
                    <div class="detail-row" id="confirmReceiverRow">
                        <label>Penerima</label>
                        <span id="confirmReceiverAddress" class="text-mono"></span>
                    </div>
                    <div class="detail-row" id="privateNoteRow" style="display:none;">
                        <label>Penerima</label>
                        <span>Tersembunyi (pool) · <span id="selectedNoteInfo" class="text-mono"></span></span>
                    </div>
                    <div class="detail-row">
                        <label>Jumlah</label>
                        <span id="confirmAmount" class="amount-highlight"></span>
                    </div>
                </div>

                <div class="confirmation-actions">
                    <button class="btn btn--primary btn--lg" onclick="confirmPayment()">
                        <i data-lucide="check"></i> Konfirmasi &amp; Bayar
                    </button>
                    <button class="btn btn--ghost btn--lg" onclick="cancelPayment()">
                        <i data-lucide="x"></i> Batal
                    </button>
                </div>
            </section>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<span id="userBalance" style="display: none;" data-balance="{{ Auth::user()->wallet->balance ?? 0 }}"></span>
<meta name="user-email" content="{{ Auth::user()->email }}">
<meta name="dashboard-url" content="{{ route('dashboard') }}">
<meta name="record-relay-url" content="{{ route('payment.record-relay') }}">

{{-- Mode ditentukan oleh QR (xevouzk:): plain → relay publik; privat → transferFromPool --}}
@vite([
    'resources/js/xevou-uri.js',
    'resources/js/shield-key.js',
    'resources/js/note-store.js',
    'resources/js/note-crypto.js',
    'resources/js/payment-relay.js',
    'resources/js/polygon-key.js',
    'resources/js/polygon-transfer.js',
    'resources/js/pool-balance.js',
    'resources/js/qr-scanner.js',
])
@endpush
