// record-event.js — catat event pool ke riwayat server (best-effort, informatif).
// Binding kebenaran tetap on-chain. PRIVASI:
//  - `private_transfer` (kirim): HANYA tx_hash + type — nominal & penerima tak dikirim.
//  - `private_receive` (terima): HANYA receipt_ref opaque + type — tx_hash TIDAK
//    dikirim sama sekali (gap §3.I/M1) supaya server tak bisa menautkan penerima ke
//    transaksi pengirim. receipt_ref = sha256(commitment‖salt) (salt rahasia note).
// Sesuai CLAUDE.md §3.2. Gagal mencatat tidak membatalkan tx.
export async function recordEvent({ type, polygonTxHash, receiptRef, amountMatic, receiverAddress, csrfToken }) {
    const isReceive = type === 'private_receive';
    if (isReceive) {
        if (!receiptRef || !/^[0-9a-f]{64}$/.test(receiptRef)) return false;
    } else if (!polygonTxHash || !/^0x[0-9a-fA-F]{64}$/.test(polygonTxHash)) {
        return false;
    }
    try {
        // PENERIMA privat → kirim receipt_ref (BUKAN tx_hash). Lainnya → polygon_tx_hash.
        const payload = isReceive
            ? { type, receipt_ref: receiptRef }
            : { type, polygon_tx_hash: polygonTxHash };
        // Jangan pernah kirim amount/receiver untuk transfer privat (kirim/terima).
        const isPrivate = type === 'private_transfer' || isReceive;
        if (!isPrivate) {
            if (amountMatic != null && String(amountMatic) !== '') payload.amount = String(amountMatic);
            if (receiverAddress) payload.receiver_address = receiverAddress;
        }
        const res = await fetch('/payment/record-event', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json', 'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken, 'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(payload),
        });
        return res.ok;
    } catch (e) {
        console.warn('recordEvent gagal (tx tetap valid on-chain):', e.message);
        return false;
    }
}

if (typeof window !== 'undefined') {
    window.RecordEvent = { recordEvent };
}
