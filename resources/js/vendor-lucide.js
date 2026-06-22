// Bridge: sediakan window.lucide (API setara UMD) dari paket npm `lucide`,
// lalu render ikon saat load. wallet.js & live-updates.js memanggil
// window.lucide.createIcons() belakangan (setelah update DOM).
import { createIcons, icons } from 'lucide';

window.lucide = {
    createIcons: (opts = {}) => createIcons({ icons, ...opts }),
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => window.lucide.createIcons());
} else {
    window.lucide.createIcons();
}
