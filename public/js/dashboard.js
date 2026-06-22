/**
 * Dashboard Module
 */

function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        alert('Alamat berhasil disalin!');
    }).catch(err => {
        console.error('Gagal menyalin:', err);
    });
}

// Refresh saldo/jaringan/riwayat ditangani live-updates.js (tanpa reload).

