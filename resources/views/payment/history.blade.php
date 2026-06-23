@extends('layouts.app')

@section('title', 'Riwayat Transaksi — XevouZK')

@section('content')
<div class="history-container">
    <header class="history-header">
        <span class="page-header__eyebrow">HISTORY · ON-CHAIN + LOCAL</span>
        <h1><i data-lucide="history"></i> Riwayat Transaksi</h1>
        <p>Semua transaksi yang pernah Anda kirim atau terima. Klik <em>view</em> untuk membuka di Polygonscan.</p>
    </header>

    <div class="history-filters">
        <div class="filter-group">
            <label>Filter Status</label>
            <select id="statusFilter" onchange="filterTransactions()">
                <option value="all">Semua</option>
                <option value="completed">Selesai</option>
                <option value="pending">Pending</option>
                <option value="failed">Gagal</option>
            </select>
        </div>
        <div class="filter-group">
            <label>Tipe</label>
            <select id="typeFilter" onchange="filterTransactions()">
                <option value="all">Semua</option>
                <option value="sent">Terkirim</option>
                <option value="received">Diterima</option>
            </select>
        </div>
    </div>

    <section class="transactions-table-card">
        <table class="transactions-table">
            <thead>
                <tr>
                    <th>Tanggal</th>
                    <th>Tipe</th>
                    <th>Counterparty</th>
                    <th>Jumlah</th>
                    <th>Status</th>
                    <th>Privasi</th>
                    <th>On-Chain</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($transactions as $transaction)
                @php
                    $type = $transaction->type ?? 'transfer';
                    // Transfer privat punya dua sisi: private_transfer (kirim) &
                    // private_receive (terima). Keduanya menyembunyikan nominal & counterparty.
                    $isPrivate = in_array($type, ['private_transfer', 'private_receive'], true);
                    $outgoing = $transaction->sender_wallet_id == $wallet->id;
                    // Tipe baris untuk filter sent/received.
                    $rowDir = ($type === 'faucet') ? 'received' : ($outgoing ? 'sent' : 'received');
                    // Konfigurasi badge tipe + tanda nominal per jenis. Privat dibedakan
                    // arah lewat panah: ↑ (private_transfer = keluar), ↓ (private_receive = masuk).
                    $typeMeta = [
                        'faucet'           => ['badge' => 'ok',    'icon' => 'droplet',    'label' => 'FAUCET',   'sign' => '+'],
                        'deposit'          => ['badge' => 'info',  'icon' => 'lock',       'label' => 'DEPOSIT',  'sign' => '−'],
                        'withdraw'         => ['badge' => 'warn',  'icon' => 'unlock',     'label' => 'WITHDRAW', 'sign' => '+'],
                        'private_transfer' => ['badge' => 'proof', 'icon' => 'arrow-up',   'label' => 'PRIVAT',   'sign' => ''],
                        'private_receive'  => ['badge' => 'proof', 'icon' => 'arrow-down', 'label' => 'PRIVAT',   'sign' => ''],
                    ];
                    $meta = $typeMeta[$type] ?? [
                        'badge' => $outgoing ? 'error' : 'ok',
                        'icon'  => $outgoing ? 'arrow-up' : 'arrow-down',
                        'label' => $outgoing ? 'SENT' : 'RECV',
                        'sign'  => $outgoing ? '−' : '+',
                    ];
                    // Teks counterparty & nominal dipakai ulang oleh modal detail
                    // (lewat data-* di baris) supaya privasi konsisten dgn tampilan tabel.
                    $counterpartyFull = $isPrivate
                        ? ($type === 'private_receive' ? 'Tersembunyi (masuk)' : 'Tersembunyi (keluar)')
                        : ($type === 'faucet' ? 'Faucet (master)'
                            : ($type === 'deposit' ? 'Pool Privat'
                                : ($outgoing
                                    ? ($transaction->receiverWallet->wallet_address ?? $transaction->receiver_address ?? '—')
                                    : ($transaction->senderWallet->wallet_address ?? '—'))));
                    $amountText = $isPrivate
                        ? '••• MATIC'
                        : trim($meta['sign'].' '.number_format((float) $transaction->amount, 6, '.', '')).' MATIC';
                @endphp
                <tr class="transaction-row"
                    data-status="{{ $transaction->status }}"
                    data-type="{{ $rowDir }}"
                    data-tx-id="{{ $transaction->id }}"
                    data-date="{{ $transaction->created_at->format('d M Y · H:i:s') }}"
                    data-type-label="{{ $meta['label'] }}"
                    data-counterparty="{{ $counterpartyFull }}"
                    data-amount="{{ $amountText }}"
                    data-privacy="{{ ($isPrivate || $transaction->zk_proof) ? 'PRIVATE' : 'PUBLIC' }}">
                    <td><span class="text-mono" style="font-size: 0.78rem; color: var(--text-secondary);">{{ $transaction->created_at->format('d M Y · H:i:s') }}</span></td>
                    <td>
                        <span class="badge badge--{{ $meta['badge'] }}"><i data-lucide="{{ $meta['icon'] }}"></i> {{ $meta['label'] }}</span>
                    </td>
                    <td>
                        @if($isPrivate)
                            <span class="text-muted" title="{{ $type === 'private_receive' ? 'Pengirim disembunyikan demi privasi' : 'Penerima disembunyikan demi privasi' }}">
                                {{ $type === 'private_receive' ? 'Tersembunyi (masuk)' : 'Tersembunyi (keluar)' }}
                            </span>
                        @elseif($type === 'faucet')
                            <span class="text-muted">Faucet (master)</span>
                        @elseif($type === 'deposit')
                            <span class="text-muted">Pool Privat</span>
                        @elseif($outgoing)
                            <code class="wallet-code">{{ Str::limit($transaction->receiverWallet->wallet_address ?? $transaction->receiver_address ?? '—', 20) }}</code>
                        @else
                            <code class="wallet-code">{{ Str::limit($transaction->senderWallet->wallet_address ?? '—', 20) }}</code>
                        @endif
                    </td>
                    <td>
                        @if($isPrivate)
                            <span class="amount" title="Nominal disembunyikan demi privasi (hanya commitment on-chain)">••• MATIC</span>
                        @else
                            <span class="amount {{ $meta['sign'] === '−' ? 'negative' : 'positive' }}">
                                {{ $meta['sign'] }}
                                {{ number_format((float) $transaction->amount, 6, '.', '') }} MATIC
                            </span>
                        @endif
                    </td>
                    <td>
                        <span class="badge badge--{{ $transaction->status == 'completed' ? 'ok' : ($transaction->status == 'pending' ? 'warn' : 'error') }}">
                            {{ strtoupper($transaction->status) }}
                        </span>
                    </td>
                    <td class="text-center">
                        @if($isPrivate || $transaction->zk_proof)
                            <span class="badge badge--proof" title="Nominal &amp; penerima disembunyikan (ZK-SNARK)"><i data-lucide="lock"></i> PRIVATE</span>
                        @else
                            <span class="badge badge--info"><i data-lucide="globe"></i> PUBLIC</span>
                        @endif
                    </td>
                    <td>
                        @if($transaction->polygon_tx_hash)
                            <a href="https://amoy.polygonscan.com/tx/{{ $transaction->polygon_tx_hash }}"
                               target="_blank"
                               rel="noopener noreferrer"
                               class="blockchain-link"
                               title="Lihat di Polygonscan (Amoy)">view</a>
                        @elseif($type === 'private_transfer' && $outgoing)
                            {{-- tx_hash sengaja TIDAK disimpan server (privasi 1b); link diisi
                                 dari localStorage pengirim via JS, dicocokkan pakai send_ref. --}}
                            <span class="onchain-local" data-send-ref="{{ $transaction->transaction_hash }}"><span class="text-muted">—</span></span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td>
                        <button class="btn-icon" onclick="showTransactionDetails(this)" title="Detail transaksi">
                            <i data-lucide="eye"></i>
                        </button>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="text-center">
                        <div class="empty-state">
                            <p>Belum ada transaksi.</p>
                        </div>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <div class="pagination-container">
        {{ $transactions->links() }}
    </div>
</div>

{{-- Modal Detail Transaksi --}}
<div id="transactionModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i data-lucide="receipt"></i> Detail Transaksi</h2>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body" id="transactionDetails">
            {{-- Filled via JS --}}
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    function filterTransactions() {
        const statusFilter = document.getElementById('statusFilter').value;
        const typeFilter = document.getElementById('typeFilter').value;
        const rows = document.querySelectorAll('.transaction-row');

        rows.forEach(row => {
            const status = row.dataset.status;
            const type = row.dataset.type;

            let showRow = true;
            if (statusFilter !== 'all' && status !== statusFilter) showRow = false;
            if (typeFilter !== 'all' && type !== typeFilter) showRow = false;

            row.style.display = showRow ? '' : 'none';
        });
    }

    // Detail dibangun dari data baris yang sudah dirender server (tanpa round-trip
    // & tanpa endpoint baru): privasi sama persis dgn tabel (nominal/counterparty
    // privat tetap tersembunyi). Link on-chain diambil dari sel baris agar ikut
    // mencakup link lokal transfer privat (diisi dari localStorage di bawah).
    function showTransactionDetails(btn) {
        const row = btn.closest('tr');
        if (!row) return;
        const d = row.dataset;
        document.getElementById('transactionModal').classList.add('active');

        const link = row.querySelector('.blockchain-link');
        const onchain = link
            ? `<a href="${link.href}" target="_blank" rel="noopener noreferrer" class="blockchain-link">Lihat di Polygonscan ↗</a>`
            : '<span class="text-muted">—</span>';
        const privacyBadge = d.privacy === 'PRIVATE'
            ? '<span class="badge badge--proof"><i data-lucide="lock"></i> PRIVATE</span>'
            : '<span class="badge badge--info"><i data-lucide="globe"></i> PUBLIC</span>';
        const sb = d.status === 'completed' ? 'ok' : (d.status === 'pending' ? 'warn' : 'error');
        const statusBadge = `<span class="badge badge--${sb}">${(d.status || '').toUpperCase()}</span>`;

        document.getElementById('transactionDetails').innerHTML = `
            <div class="detail-section">
                <h3>Informasi Transaksi</h3>
                <div class="confirmation-details" style="background: var(--bg-elevated); border: 1px solid var(--border-soft); border-radius: var(--radius-md); padding: var(--s-4);">
                    <div class="detail-row"><label>Transaction ID</label><span class="text-mono">${d.txId}</span></div>
                    <div class="detail-row"><label>Tanggal</label><span>${d.date}</span></div>
                    <div class="detail-row"><label>Tipe</label><span>${d.typeLabel}</span></div>
                    <div class="detail-row"><label>Counterparty</label><span class="text-mono">${d.counterparty}</span></div>
                    <div class="detail-row"><label>Jumlah</label><span>${d.amount}</span></div>
                    <div class="detail-row"><label>Status</label>${statusBadge}</div>
                    <div class="detail-row"><label>Privasi</label>${privacyBadge}</div>
                    <div class="detail-row"><label>On-Chain</label>${onchain}</div>
                </div>
            </div>
        `;
        if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons();
    }

    function closeModal() {
        document.getElementById('transactionModal').classList.remove('active');
    }

    window.addEventListener('click', function(event) {
        const modal = document.getElementById('transactionModal');
        if (event.target === modal) closeModal();
    });

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') closeModal();
    });

    // Link explorer LOKAL untuk transfer privat keluar: tx_hash hanya ada di
    // localStorage pengirim (server tak menyimpannya demi privasi 1b). Cocokkan
    // baris ↔ entri lokal pakai send_ref (== transaction_hash baris).
    document.querySelectorAll('.onchain-local').forEach(function(cell) {
        const ref = cell.dataset.sendRef;
        if (!ref) return;
        try {
            const raw = localStorage.getItem('xevouzk_sent_v1_' + ref);
            if (!raw) return;
            const tx = JSON.parse(raw).tx_hash;
            if (!tx) return;
            cell.innerHTML = '<a href="https://amoy.polygonscan.com/tx/' + tx +
                '" target="_blank" rel="noopener noreferrer" class="blockchain-link"' +
                ' title="Lihat di Polygonscan (Amoy) — link lokal">view</a>';
        } catch (e) { /* abaikan */ }
    });
</script>
@endpush
