// 開発マシンへのリンクが残った配布物と、別マシンでの参照切れを再現する。
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {mkdtempSync,mkdirSync,writeFileSync,symlinkSync,readlinkSync,rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join,dirname} from 'node:path';
import {normalizeBundleLinks} from '../../../scripts/lib/macos-bundle.mjs';
const fixture=()=>{const root=mkdtempSync(join(tmpdir(),'sgk-sign-links-'));return {root,put(name){const p=join(root,name);mkdirSync(dirname(p),{recursive:true});writeFileSync(p,'fixture');},link(name,target){const p=join(root,name);mkdirSync(dirname(p),{recursive:true});symlinkSync(target,p);},close(){rmSync(root,{recursive:true,force:true});}};};
test('開発環境の npm / CLI の絶対リンクを同梱相対リンクへ直す',()=>{
 const f=fixture();try{
  f.put('Contents/Resources/runtime/lib/node_modules/npm/bin/npm-cli.js');
  f.put('Contents/Resources/app/dist/index.js');
  f.link('Contents/Resources/runtime/bin/npm','/absent-developer-machine/npm.js');
  f.link('Contents/Resources/app/node_modules/.bin/agentcms','/absent-developer-machine/index.js');
  assert.equal(normalizeBundleLinks(f.root).length,2);
  assert.equal(readlinkSync(join(f.root,'Contents/Resources/app/node_modules/.bin/agentcms')),'../../dist/index.js');
  assert.deepEqual(normalizeBundleLinks(f.root),[]);
 }finally{f.close();}
});
test('未知の外部リンクと同梱先がないリンクは拒否する',()=>{
 const f=fixture();try{f.link('external','/etc/hosts');assert.throws(()=>normalizeBundleLinks(f.root),/絶対リンク/);}finally{f.close();}
 const g=fixture();try{g.link('Contents/Resources/runtime/bin/npm','/missing');assert.throws(()=>normalizeBundleLinks(g.root),/同梱リンク先/);}finally{g.close();}
});
