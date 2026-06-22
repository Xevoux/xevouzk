// xevou-uri.js — build + parse skema URI XevouZK untuk QR.
//   Plain   : xevouzk:transfer?to=0x..&amount=..
//   Privat  : xevouzk:private-transfer?zkpub=<b64url 64B>&amount=..
// Tidak ada secret di URI. zkpub = base64url(shieldPub(32B)‖encPub(32B)).
const ADDR_RE = /^0x[a-fA-F0-9]{40}$/;
const B64URL_RE = /^[A-Za-z0-9_-]+$/;

export function buildPlainUri({ to, amount }) {
    let u = `xevouzk:transfer?to=${to}`;
    if (amount !== undefined && amount !== null && String(amount) !== '') u += `&amount=${amount}`;
    return u;
}
export function buildPrivateUri({ zkpub, amount }) {
    let u = `xevouzk:private-transfer?zkpub=${zkpub}`;
    if (amount !== undefined && amount !== null && String(amount) !== '') u += `&amount=${amount}`;
    return u;
}

function parseQuery(qs) {
    const out = {};
    for (const pair of qs.split('&')) {
        const i = pair.indexOf('=');
        if (i < 0) continue;
        out[decodeURIComponent(pair.slice(0, i))] = decodeURIComponent(pair.slice(i + 1));
    }
    return out;
}

/** @returns {{mode:'plain',to,amount?}|{mode:'private',zkpub,amount?}|null} */
export function parseUri(text) {
    if (typeof text !== 'string') return null;
    const t = text.trim();

    if (t.startsWith('xevouzk:transfer?')) {
        const q = parseQuery(t.slice('xevouzk:transfer?'.length));
        if (!q.to || !ADDR_RE.test(q.to)) return null;
        return { mode: 'plain', to: q.to, amount: q.amount };
    }
    if (t.startsWith('xevouzk:private-transfer?')) {
        const q = parseQuery(t.slice('xevouzk:private-transfer?'.length));
        if (!q.zkpub || !B64URL_RE.test(q.zkpub)) return null;
        return { mode: 'private', zkpub: q.zkpub, amount: q.amount };
    }
    if (t.startsWith('{')) {
        try {
            const j = JSON.parse(t);
            if (j && j.type === 'wallet_address' && ADDR_RE.test(j.address || '')) {
                return { mode: 'plain', to: j.address, amount: undefined };
            }
        } catch { /* ignore */ }
    }
    return null;
}

if (typeof window !== 'undefined') {
    window.XevouUri = { buildPlainUri, buildPrivateUri, parseUri };
}
