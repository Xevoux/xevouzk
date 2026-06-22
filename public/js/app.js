/**
 * XevouZK - Main Application JavaScript
 * Handles general app functionality and interactions
 */

// CSRF Token Setup
const csrfToken = document.querySelector('meta[name="csrf-token"]');
if (csrfToken) {
    window.csrfToken = csrfToken.getAttribute('content');
}

// Global state
window.zkPaymentApp = {
    isConnected: false,
    walletAddress: null,
    balance: 0,
    polygonConnected: false,
};

/**
 * Initialize Application
 */
document.addEventListener('DOMContentLoaded', function() {
    console.log('XevouZK Application initialized');
    
    // Setup event listeners
    setupEventListeners();
    
    // Check wallet connection status
    checkWalletConnection();
    
    // Initialize tooltips
    initTooltips();
    
    // Refresh saldo ditangani live-updates.js (poll /live/state, tanpa reload).
});

/**
 * Setup Event Listeners
 */
function setupEventListeners() {
    // Copy to clipboard buttons
    document.querySelectorAll('.btn-copy').forEach(button => {
        button.addEventListener('click', function() {
            const textToCopy = this.previousElementSibling?.textContent || 
                              this.dataset.copy;
            copyToClipboard(textToCopy);
        });
    });
    
    // Form validation
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function(e) {
            if (!validateForm(this)) {
                e.preventDefault();
            }
        });
    });
    
    // Amount input validation
    document.querySelectorAll('input[type="number"]').forEach(input => {
        input.addEventListener('input', function() {
            validateAmountInput(this);
        });
    });
    
    // Real-time balance check
    const amountInputs = document.querySelectorAll('input[name="amount"]');
    amountInputs.forEach(input => {
        input.addEventListener('input', function() {
            checkSufficientBalance(this.value);
        });
    });
}

/**
 * Copy to Clipboard
 */
function copyToClipboard(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(() => {
            showNotification('Berhasil disalin ke clipboard!', 'success');
        }).catch(err => {
            console.error('Gagal menyalin:', err);
            fallbackCopyToClipboard(text);
        });
    } else {
        fallbackCopyToClipboard(text);
    }
}

function fallbackCopyToClipboard(text) {
    const textArea = document.createElement('textarea');
    textArea.value = text;
    textArea.style.position = 'fixed';
    textArea.style.top = '0';
    textArea.style.left = '0';
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    
    try {
        document.execCommand('copy');
        showNotification('Berhasil disalin ke clipboard!', 'success');
    } catch (err) {
        console.error('Fallback: Gagal menyalin', err);
        showNotification('Gagal menyalin ke clipboard', 'error');
    }
    
    document.body.removeChild(textArea);
}

/**
 * Show Notification
 */
function showNotification(message, type = 'info') {
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.innerHTML = `
        <div class="notification-content">
            <span class="notification-icon">${getNotificationIcon(type)}</span>
            <span class="notification-message">${message}</span>
            <button class="notification-close" onclick="this.parentElement.parentElement.remove()">×</button>
        </div>
    `;
    
    // Add styles if not exist
    if (!document.getElementById('notification-styles')) {
        const styles = document.createElement('style');
        styles.id = 'notification-styles';
        styles.textContent = `
            .notification {
                position: fixed;
                top: 20px;
                right: 20px;
                min-width: 300px;
                padding: 1rem;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                z-index: 9999;
                animation: slideIn 0.3s ease-out;
            }
            .notification-success { background: #d1fae5; color: #065f46; border-left: 4px solid #10b981; }
            .notification-error { background: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444; }
            .notification-info { background: #dbeafe; color: #1e40af; border-left: 4px solid #3b82f6; }
            .notification-content { display: flex; align-items: center; gap: 0.5rem; }
            .notification-icon { font-size: 1.5rem; }
            .notification-close {
                background: none;
                border: none;
                font-size: 1.5rem;
                cursor: pointer;
                margin-left: auto;
                opacity: 0.7;
            }
            .notification-close:hover { opacity: 1; }
            @keyframes slideIn {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
        `;
        document.head.appendChild(styles);
    }
    
    // Add to page
    document.body.appendChild(notification);
    
    // Auto remove after 5 seconds
    setTimeout(() => {
        notification.style.animation = 'slideIn 0.3s ease-out reverse';
        setTimeout(() => notification.remove(), 300);
    }, 5000);
}

function getNotificationIcon(type) {
    const icons = {
        success: '✓',
        error: '✗',
        info: 'ℹ',
        warning: '⚠',
    };
    return icons[type] || icons.info;
}

/**
 * Form Validation
 */
function validateForm(form) {
    let isValid = true;
    const inputs = form.querySelectorAll('input[required], textarea[required]');
    
    inputs.forEach(input => {
        if (!input.value.trim()) {
            showError(input, 'Field ini wajib diisi');
            isValid = false;
        } else {
            clearError(input);
        }
    });
    
    // Validate email
    const emailInputs = form.querySelectorAll('input[type="email"]');
    emailInputs.forEach(input => {
        if (input.value && !isValidEmail(input.value)) {
            showError(input, 'Email tidak valid');
            isValid = false;
        }
    });
    
    // Validate password confirmation
    const password = form.querySelector('input[name="password"]');
    const passwordConfirm = form.querySelector('input[name="password_confirmation"]');
    if (password && passwordConfirm && password.value !== passwordConfirm.value) {
        showError(passwordConfirm, 'Password tidak cocok');
        isValid = false;
    }
    
    return isValid;
}

function showError(input, message) {
    clearError(input);
    input.classList.add('input-error');
    const error = document.createElement('span');
    error.className = 'error-message';
    error.textContent = message;
    input.parentNode.appendChild(error);
}

function clearError(input) {
    input.classList.remove('input-error');
    const error = input.parentNode.querySelector('.error-message');
    if (error) {
        error.remove();
    }
}

function isValidEmail(email) {
    const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return re.test(email);
}

/**
 * Validate Amount Input
 */
function validateAmountInput(input) {
    const value = parseFloat(input.value);
    
    if (isNaN(value) || value < 0) {
        showError(input, 'Jumlah harus berupa angka positif');
        return false;
    }
    
    const min = parseFloat(input.getAttribute('min'));
    if (min && value < min) {
        showError(input, `Jumlah minimal adalah ${min}`);
        return false;
    }
    
    const max = parseFloat(input.getAttribute('max'));
    if (max && value > max) {
        showError(input, `Jumlah maksimal adalah ${max}`);
        return false;
    }
    
    clearError(input);
    return true;
}

/**
 * Check Sufficient Balance
 */
function checkSufficientBalance(amount) {
    const balanceElement = document.querySelector('.balance-amount');
    if (!balanceElement) return true;
    
    const balanceText = balanceElement.textContent.replace(/[^\d,]/g, '').replace(',', '.');
    const balance = parseFloat(balanceText);
    const amountValue = parseFloat(amount);
    
    if (amountValue > balance) {
        showNotification('Saldo tidak mencukupi!', 'warning');
        return false;
    }
    
    return true;
}

/**
 * Check Wallet Connection
 */
async function checkWalletConnection() {
    try {
        if (typeof window.ethereum !== 'undefined') {
            const accounts = await window.ethereum.request({ method: 'eth_accounts' });
            if (accounts.length > 0) {
                window.zkPaymentApp.isConnected = true;
                window.zkPaymentApp.walletAddress = accounts[0];
                window.zkPaymentApp.polygonConnected = true;
                
                console.log('Wallet connected:', accounts[0]);
                updateWalletUI(accounts[0]);
            }
        }
    } catch (error) {
        console.error('Error checking wallet:', error);
    }
}

/**
 * Update Wallet UI
 */
function updateWalletUI(address) {
    const walletIndicators = document.querySelectorAll('.wallet-indicator');
    walletIndicators.forEach(indicator => {
        indicator.textContent = `${address.substring(0, 6)}...${address.substring(38)}`;
        indicator.classList.add('connected');
    });
}

/**
 * Format Currency
 */
function formatCurrency(amount) {
    var n = Number(amount);
    if (isNaN(n)) n = 0;
    return n.toFixed(6) + ' MATIC';
}

/**
 * Format Date
 */
function formatDate(date) {
    return new Intl.DateTimeFormat('id-ID', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(date));
}

/**
 * Initialize Tooltips
 */
function initTooltips() {
    const tooltips = document.querySelectorAll('[data-tooltip]');
    tooltips.forEach(element => {
        element.addEventListener('mouseenter', function() {
            showTooltip(this, this.dataset.tooltip);
        });
        element.addEventListener('mouseleave', function() {
            hideTooltip();
        });
    });
}

function showTooltip(element, text) {
    const tooltip = document.createElement('div');
    tooltip.className = 'tooltip';
    tooltip.textContent = text;
    tooltip.id = 'active-tooltip';
    
    document.body.appendChild(tooltip);
    
    const rect = element.getBoundingClientRect();
    tooltip.style.top = (rect.top - tooltip.offsetHeight - 10) + 'px';
    tooltip.style.left = (rect.left + rect.width / 2 - tooltip.offsetWidth / 2) + 'px';
}

function hideTooltip() {
    const tooltip = document.getElementById('active-tooltip');
    if (tooltip) {
        tooltip.remove();
    }
}

// Auto-refresh saldo dipindah ke live-updates.js (poll /live/state, satu sumber
// kebenaran untuk semua data dinamis). Fungsi lama refreshBalance /
// setupBalanceAutoRefresh yang menembak /api/wallet/balance dihapus.

/**
 * Loading Overlay
 */
function showLoading(message = 'Loading...') {
    const overlay = document.createElement('div');
    overlay.id = 'loading-overlay';
    overlay.innerHTML = `
        <div class="loading-spinner"></div>
        <p class="loading-message">${message}</p>
    `;
    document.body.appendChild(overlay);
}

function hideLoading() {
    const overlay = document.getElementById('loading-overlay');
    if (overlay) {
        overlay.remove();
    }
}

/**
 * Confirm Dialog
 */
function confirmDialog(message, onConfirm, onCancel) {
    const confirmed = confirm(message);
    if (confirmed && onConfirm) {
        onConfirm();
    } else if (!confirmed && onCancel) {
        onCancel();
    }
}

/**
 * Debounce Function
 */
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

/**
 * Throttle Function
 */
function throttle(func, limit) {
    let inThrottle;
    return function(...args) {
        if (!inThrottle) {
            func.apply(this, args);
            inThrottle = true;
            setTimeout(() => inThrottle = false, limit);
        }
    };
}

// Export global functions
window.zkPayment = {
    showNotification,
    showLoading,
    hideLoading,
    confirmDialog,
    copyToClipboard,
    formatCurrency,
    formatDate,
};

console.log('XevouZK App JS loaded successfully');

