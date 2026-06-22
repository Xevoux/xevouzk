// Run: node resources/js/__tests__/xevou-uri.test.mjs
import { buildPlainUri, buildPrivateUri, parseUri } from '../xevou-uri.js';

function assert(c, m){ if(!c){ console.error('✗ '+m); process.exit(1); } else console.log('✓ '+m); }

const addr = '0x71C7656EC7ab88b098defB751B7401B5f6d147a3';
const zk = 'A'.repeat(86);

const p1 = buildPlainUri({ to: addr, amount: '5.0' });
assert(p1 === `xevouzk:transfer?to=${addr}&amount=5.0`, 'buildPlainUri with amount');
assert(buildPlainUri({ to: addr }) === `xevouzk:transfer?to=${addr}`, 'buildPlainUri no amount');

const pr = buildPrivateUri({ zkpub: zk, amount: '2.5' });
assert(pr === `xevouzk:private-transfer?zkpub=${zk}&amount=2.5`, 'buildPrivateUri with amount');

const a = parseUri(p1);
assert(a && a.mode === 'plain' && a.to === addr && a.amount === '5.0', 'parse plain');
const b = parseUri(pr);
assert(b && b.mode === 'private' && b.zkpub === zk && b.amount === '2.5', 'parse private');
assert(parseUri('xevouzk:transfer?to=0xZZZ') === null, 'reject bad 0x');
assert(parseUri('random text') === null, 'reject non-xevou');

const legacy = JSON.stringify({ type:'wallet_address', address: addr });
const l = parseUri(legacy);
assert(l && l.mode==='plain' && l.to===addr, 'legacy json → plain');
assert(parseUri(JSON.stringify({type:'wallet_address',address:'ZKWALLET123'})) === null, 'legacy non-0x rejected');

console.log('ALL XEVOU-URI TESTS PASSED');
