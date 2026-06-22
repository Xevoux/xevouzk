// Wallet Page JavaScript
let currentCustomQRData = null;

// Logging function
function addLog(message, type = 'info') {
    const logsContainer = document.getElementById('walletLogs');
    if (!logsContainer) return;
    
    const logEntry = document.createElement('div');
    logEntry.className = `log-entry log-${type}`;
    
    const now = new Date();
    const timeStr = now.toTimeString().split(' ')[0];
    
    logEntry.innerHTML = `
        <span class="log-time">[${timeStr}]</span>
        <span class="log-message">${message}</span>
    `;
    
    logsContainer.appendChild(logEntry);
    logsContainer.scrollTop = logsContainer.scrollHeight;
}

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', function() {
    addLog('Wallet module initialized', 'success');
    addLog('Default QR code loaded', 'success');
    addLog('Ready to receive payments', 'info');
    
    // Check faucet availability
    checkFaucetAvailability();
    
    // Initialize QR form
    initializeQRForm();
});

// Copy to clipboard
function copyToClipboard(text, label = 'Teks') {
    navigator.clipboard.writeText(text).then(() => {
        addLog(`✓ ${label} disalin ke clipboard`, 'success');
        alert(label + ' berhasil disalin!');
    }).catch(err => {
        console.error('Gagal menyalin:', err);
        addLog(`✗ Gagal menyalin ${label}`, 'error');
        alert('Gagal menyalin!');
    });
}

// Re-render Lucide icons after innerHTML/DOM changes.
function rerenderIcons() {
    if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons();
    }
}

// Toggle public key display (mask dengan "•" lalu tampilkan full key saat di-toggle)
function togglePublicKeyDisplay() {
    const display = document.getElementById('publicKeyDisplay');
    if (!display) return;

    const fullKey = display.getAttribute('data-full-key') || '';
    const button = display.nextElementSibling.nextElementSibling;

    // Mask default (16 bullet) – harus sama dengan di Blade
    const masked = '••••••••••••••••';

    if (display.textContent.trim() === masked) {
        display.textContent = fullKey;
        button.innerHTML = '<i data-lucide="eye-off"></i>';
        button.title = 'Sembunyikan public key';
        addLog('Public key: full display', 'info');
    } else {
        display.textContent = masked;
        button.innerHTML = '<i data-lucide="eye"></i>';
        button.title = 'Tampilkan public key';
        addLog('Public key: masked display', 'info');
    }
    rerenderIcons();
}

// Initialize QR form
function initializeQRForm() {
    const qrForm = document.getElementById('generateCustomQR');
    if (!qrForm) return;
    
    qrForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const amount = document.getElementById('qr_amount')?.value;
        const description = document.getElementById('qr_description')?.value;
        const generateUrl = this.dataset.generateUrl;
        
        addLog('Generating custom QR code...', 'warning');
        if (amount) {
            addLog(`Amount: ${parseFloat(amount).toFixed(4)} MATIC`);
        }
        if (description) {
            addLog(`Description: ${description}`);
        }
        
        const formData = new FormData(this);
        
        try {
            const response = await fetch(generateUrl, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                // Show custom QR
                const defaultQR = document.getElementById('defaultQRCode');
                const customQR = document.getElementById('customQRCode');
                const customImage = document.getElementById('customQRImage');
                
                if (defaultQR) defaultQR.style.display = 'none';
                if (customQR) customQR.style.display = 'block';
                if (customImage) {
                    const imageType = data.format === 'svg' ? 'image/svg+xml' : 'image/png';
                    customImage.src = 'data:' + imageType + ';base64,' + data.qr_code;
                }
                
                currentCustomQRData = data;
                
                addLog('✓ Custom QR code generated', 'success');
                addLog('QR code contains payment request data', 'info');
            } else {
                addLog('✗ Failed to generate QR', 'error');
                alert('Gagal generate QR Code');
            }
        } catch (error) {
            console.error('Error:', error);
            addLog('✗ Error: ' + error.message, 'error');
            alert('Terjadi kesalahan');
        }
    });
}

// Reset to default QR
function resetToDefaultQR() {
    const defaultQR = document.getElementById('defaultQRCode');
    const customQR = document.getElementById('customQRCode');
    const qrForm = document.getElementById('generateCustomQR');
    
    if (defaultQR) defaultQR.style.display = 'block';
    if (customQR) customQR.style.display = 'none';
    if (qrForm) qrForm.reset();
    
    currentCustomQRData = null;
    addLog('Reset to default QR code', 'info');
}

// Download current QR
function downloadCurrentQR() {
    addLog('Downloading QR code...', 'info');
    
    if (currentCustomQRData) {
        const img = document.getElementById('customQRImage');
        if (img) {
            const link = document.createElement('a');
            link.download = 'payment-request-qr.png';
            link.href = img.src;
            link.click();
            addLog('✓ Custom QR code downloaded', 'success');
        }
    } else {
        const downloadUrl = document.getElementById('qrDownloadUrl')?.value;
        if (downloadUrl) {
            window.location.href = downloadUrl;
            addLog('✓ Default QR code downloaded', 'success');
        }
    }
}

// Faucet functions
async function checkFaucetAvailability() {
    try {
        const checkUrl = document.getElementById('faucetCheckUrl')?.value;
        if (!checkUrl) return;
        
        const response = await fetch(checkUrl, {
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            }
        });
        
        const data = await response.json();
        
        if (data.success) {
            const btn = document.getElementById('requestTestMaticBtn');
            const cooldownEl = document.getElementById('faucetCooldown');
            
            if (!btn || !cooldownEl) return;
            
            if (data.can_request) {
                btn.disabled = false;
                cooldownEl.textContent = 'Ready';
                cooldownEl.style.color = '#10b981';
                addLog('Faucet ready: You can request test MATIC', 'success');
            } else {
                btn.disabled = true;
                const hours = Math.floor(data.remaining_seconds / 3600);
                const minutes = Math.floor((data.remaining_seconds % 3600) / 60);
                cooldownEl.textContent = `${hours}h ${minutes}m`;
                cooldownEl.style.color = '#fbbf24';
                addLog(`Faucet cooldown: ${hours}h ${minutes}m remaining`, 'warning');
                
                // Set timer to re-check
                setTimeout(checkFaucetAvailability, 60000); // Check every minute
            }
        }
    } catch (error) {
        console.error('Error checking faucet:', error);
        addLog('Error checking faucet availability', 'error');
    }
}

async function requestTestMatic() {
    const btn = document.getElementById('requestTestMaticBtn');
    const statusDiv = document.getElementById('faucetStatus');
    const messageDiv = document.getElementById('faucetMessage');
    const requestUrl = document.getElementById('faucetRequestUrl')?.value;
    
    if (!btn || !statusDiv || !messageDiv || !requestUrl) return;
    
    // Disable button
    btn.disabled = true;
    btn.innerHTML = '<i data-lucide="loader-circle" class="lucide-spin"></i> Requesting...';
    rerenderIcons();

    addLog('=== FAUCET REQUEST INITIATED ===', 'warning');
    addLog('Requesting 0.01 test MATIC from faucet...', 'info');
    
    try {
        const response = await fetch(requestUrl, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Content-Type': 'application/json',
            }
        });
        
        const data = await response.json();
        
        statusDiv.style.display = 'block';
        
        if (data.success) {
            statusDiv.className = 'faucet-status success';
            
            let message = `✅ ${data.message}<br>`;
            message += `Amount: ${data.amount} MATIC<br>`;
            message += `TX Hash: <code style="font-size: 0.8em; word-break: break-all;">${data.tx_hash}</code>`;
            
            if (data.simulation) {
                message += `<br><em>⚠️ Simulation Mode - Master wallet belum dikonfigurasi</em>`;
            }
            
            messageDiv.innerHTML = message;
            
            addLog('✓ FAUCET REQUEST SUCCESSFUL', 'success');
            addLog(`Amount received: ${data.amount} MATIC`, 'success');
            addLog(`TX Hash: ${data.tx_hash}`, 'info');
            
            if (data.simulation) {
                addLog('⚠️ Running in simulation mode', 'warning');
            }
            
            // Update saldo & cooldown tanpa reload (live-updates.js).
            addLog('Refreshing balance on-chain...', 'info');
            btn.innerHTML = '<i data-lucide="droplet"></i> Request Test MATIC';
            rerenderIcons();
            if (window.LiveUpdates) {
                window.LiveUpdates.refresh();
                // beri jeda agar tx sempat ter-mine, lalu sync sekali lagi
                setTimeout(() => window.LiveUpdates.refresh(), 4000);
            }

        } else {
            statusDiv.className = 'faucet-status error';
            messageDiv.innerHTML = `❌ ${data.error || 'Request failed'}`;
            
            addLog('✗ FAUCET REQUEST FAILED', 'error');
            addLog(`Error: ${data.error}`, 'error');
            
            // Re-enable button after 3 seconds
            setTimeout(() => {
                btn.disabled = false;
                btn.innerHTML = '<i data-lucide="droplet"></i> Request Test MATIC';
                rerenderIcons();
            }, 3000);
        }

    } catch (error) {
        console.error('Error requesting test MATIC:', error);
        
        statusDiv.style.display = 'block';
        statusDiv.className = 'faucet-status error';
        messageDiv.innerHTML = `❌ Error: ${error.message}`;
        
        addLog('✗ FAUCET REQUEST ERROR', 'error');
        addLog(`Error: ${error.message}`, 'error');
        
        // Re-enable button
        btn.disabled = false;
        btn.innerHTML = '<i data-lucide="droplet"></i> Request Test MATIC';
        rerenderIcons();
    }
}

