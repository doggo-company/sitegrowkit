// 外部公開ポートを持つサイトでは復旧を許可しない。
import test from 'node:test';
import assert from 'node:assert/strict';
import {localRecoveryPort} from '../desktop/login-recovery.mjs';
const valid=()=>({services:{web:{ports:[{host_ip:'127.0.0.1',published:'8180',target:80}]},mysql:{ports:[{host_ip:'127.0.0.1',published:'33060',target:3306}]}}});
test('ローカルWebのポートを取り出す',()=>assert.equal(localRecoveryPort(valid()),8180));
for(const host of ['0.0.0.0','::',undefined,'192.168.1.20']) test('公開ポートを拒否: '+host,()=>{const c=valid();c.services.mysql.ports[0].host_ip=host;assert.throws(()=>localRecoveryPort(c),/ローカルサイト専用/);});
test('hostネットワークは拒否する',()=>{const c=valid();c.services.mysql.network_mode='host';assert.throws(()=>localRecoveryPort(c));});
test('欠落・不正なWebポートは拒否する',()=>{for(const value of ['abc','80','65536']){const c=valid();c.services.web.ports[0].published=value;assert.throws(()=>localRecoveryPort(c));}assert.throws(()=>localRecoveryPort({services:{}}));});
