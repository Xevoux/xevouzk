// Node round-trip test untuk note-crypto ECIES. Run: node resources/js/__tests__/note-crypto.test.mjs
import { deriveEncKeypair, eciesEncrypt, eciesDecrypt } from '../note-crypto.js';

function assert(cond, msg) { if (!cond) { console.error('✗ ' + msg); process.exit(1); } else { console.log('✓ ' + msg); } }

const A = await deriveEncKeypair('alice@example.com', 'pw-alice');
const B = await deriveEncKeypair('bob@example.com', 'pw-bob');

const note = { amount_wei: '300000000000000000', salt: '44332211', commitment: '12345' };
const memo = await eciesEncrypt(note, B.encPub);
assert(typeof memo === 'string' && memo.startsWith('0x'), 'memo is 0x hex');

const dec = await eciesDecrypt(memo, B.encPriv);
assert(JSON.stringify(dec) === JSON.stringify(note), 'recipient decrypts correct note');

let failed = false;
try { await eciesDecrypt(memo, A.encPriv); } catch (e) { failed = true; }
assert(failed, 'wrong key fails to decrypt (auth tag rejects)');

console.log('ALL NOTE-CRYPTO TESTS PASSED');
