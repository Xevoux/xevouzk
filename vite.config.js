import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { nodePolyfills } from 'vite-plugin-node-polyfills';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                // Entry frontend ditambah bertahap saat tiap modul dimigrasi
                // dari public/js (statis) ke resources/js (di-bundle Vite).
                'resources/js/vendor-lucide.js',
                'resources/js/schnorr-auth.js',
                'resources/js/polygon-key.js',
                'resources/js/shield-key.js',
                'resources/js/note-store.js',
                'resources/js/payment-relay.js',
                'resources/js/pool-balance.js',
                'resources/js/polygon-deposit.js',
                'resources/js/polygon-withdraw.js',
                'resources/js/zk-snark.js',
                'resources/js/qr-scanner.js',
                'resources/js/note-crypto.js',
                'resources/js/polygon-transfer.js',
                'resources/js/private-send.js',
                'resources/js/xevou-uri.js',
                'resources/js/receive-qr.js',
                'resources/js/account-guard.js',
                'resources/js/note-backup.js',
            ],
            refresh: true,
        }),
        nodePolyfills(),
    ],
});
