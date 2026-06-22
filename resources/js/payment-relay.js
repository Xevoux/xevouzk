// Non-custodial payment signing.
// Derive Polygon key dari (email, password) → sign EIP-1559 tx di browser
// (ethers.js v6) → POST raw hex ke /payment/relay → server eth_sendRawTransaction.
//
// Load
// <script type="module" src="{{ asset('js/payment-relay.js') }}"></script>

import { ethers } from 'ethers';

const POLYGON_AMOY_CHAIN_ID = 80002;
const POLYGON_RPC_URL = 'https://rpc-amoy.polygon.technology/';

// Amoy menolak transaksi dengan priority fee di bawah 25 gwei. Pakai 30 gwei
// sebagai floor (25 minimum + headroom) untuk priority maupun max fee.
export const AMOY_MIN_PRIORITY_FEE = 30000000000n; // 30 gwei

/**
 * Pastikan fee EIP-1559 tidak di bawah minimum Amoy.
 * @param {{maxFeePerGas: bigint|null, maxPriorityFeePerGas: bigint|null}} feeData
 * @returns {{maxFeePerGas: bigint, maxPriorityFeePerGas: bigint}}
 */
export function amoyFloorFees(feeData) {
    const priority = feeData.maxPriorityFeePerGas ?? 0n;
    const maxPriorityFeePerGas = priority > AMOY_MIN_PRIORITY_FEE ? priority : AMOY_MIN_PRIORITY_FEE;
    // maxFeePerGas harus >= priority. Floor juga ke nilai yang sama bila lebih rendah.
    const baseMax = feeData.maxFeePerGas ?? 0n;
    const maxFeePerGas = baseMax > maxPriorityFeePerGas ? baseMax : maxPriorityFeePerGas;
    return { maxFeePerGas, maxPriorityFeePerGas };
}

/**
 * Sign + relay a plain MATIC transfer.
 * @param {object} opts
 * @param {string} opts.email
 * @param {string} opts.password
 * @param {string} opts.recipientAddress - 0x... checksum
 * @param {string|number} opts.amountMatic - jumlah dalam MATIC (bukan wei)
 * @param {string} opts.csrfToken
 * @returns {Promise<{success:boolean, tx_hash?:string, error?:string}>}
 */
export async function signAndRelay({ email, password, recipientAddress, amountMatic, csrfToken }) {
    if (!window.PolygonKey) {
        throw new Error('PolygonKey module belum dimuat');
    }

    const { privateKey, address } = window.PolygonKey.deriveWallet(email, password);

    const provider = new ethers.JsonRpcProvider(POLYGON_RPC_URL, {
        chainId: POLYGON_AMOY_CHAIN_ID,
        name: 'amoy',
    });
    const wallet = new ethers.Wallet('0x' + privateKey, provider);

    const valueWei = ethers.parseEther(String(amountMatic));

    // Fee data + nonce dari RPC. Untuk Amoy, EIP-1559 fee fields didukung.
    const [feeData, nonce] = await Promise.all([
        provider.getFeeData(),
        provider.getTransactionCount(address, 'pending'),
    ]);

    // Amoy menolak priority fee < 25 gwei. getFeeData kerap mengembalikan
    // ~1.5 gwei → di-floor ke 30 gwei (25 minimum + headroom) agar tx diterima.
    const { maxFeePerGas, maxPriorityFeePerGas } = amoyFloorFees(feeData);

    const tx = {
        to: recipientAddress,
        value: valueWei,
        nonce,
        chainId: POLYGON_AMOY_CHAIN_ID,
        type: 2,
        maxFeePerGas,
        maxPriorityFeePerGas,
        gasLimit: 21000n,
    };

    const signedTx = await wallet.signTransaction(tx);

    const response = await fetch('/payment/relay', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ raw_tx: signedTx }),
    });

    const body = await response.json().catch(() => ({}));
    if (!response.ok) {
        return {
            success: false,
            error: body.message || body.error || `HTTP ${response.status}`,
        };
    }
    return body;
}

if (typeof window !== 'undefined') {
    window.PaymentRelay = { signAndRelay };
    window.dispatchEvent(new Event('payment-relay-ready'));
}
