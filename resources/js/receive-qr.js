// receive-qr.js — generate QR terima (xevouzk:) di klien. Plain pakai polygon_address;
// Privat derive zkpub (shieldPub‖encPub) dari password (client-only).
import QRCode from 'qrcode';
import { buildPlainUri, buildPrivateUri } from './xevou-uri.js';
import { deriveShieldKeypair, packZkpub } from './shield-key.js';
import { deriveEncKeypair } from './note-crypto.js';

async function renderTo(canvas, text) {
    await QRCode.toCanvas(canvas, text, { width: 280, margin: 1, errorCorrectionLevel: 'M' });
    return text;
}

/** Plain QR dari polygon address. @returns {Promise<string>} uri */
export async function renderPlainQR(canvas, polygonAddress, amount) {
    if (!/^0x[a-fA-F0-9]{40}$/.test(polygonAddress)) throw new Error('Polygon address tidak valid');
    const uri = buildPlainUri({ to: polygonAddress, amount });
    await renderTo(canvas, uri);
    return uri;
}

/** Privat QR: derive zkpub dari (email,password) di klien. @returns {Promise<string>} uri */
export async function renderPrivateQR(canvas, { email, password, amount }) {
    const { shieldPub } = await deriveShieldKeypair(email, password);
    const { encPub } = await deriveEncKeypair(email, password);
    const zkpub = packZkpub(shieldPub, encPub);
    const uri = buildPrivateUri({ zkpub, amount });
    await renderTo(canvas, uri);
    return uri;
}

if (typeof window !== 'undefined') {
    window.ReceiveQR = { renderPlainQR, renderPrivateQR };
    window.dispatchEvent(new Event('receive-qr-ready'));
}
