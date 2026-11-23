import {test} from 'node:test';
import assert from 'node:assert/strict';
import {mkdtemp,mkdir,writeFile,stat} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {welcomeState} from '../desktop/welcome.mjs';
// ブラウザのcookieが変わっても、端末の完了記録で案内を省略できる。
test('初回から完了後の再起動まで保存する',async()=>{
 const home=await mkdtemp(join(tmpdir(),'sgk-welcome-state-'));
 const state=await welcomeState(home);assert.equal(state.completed,false);await state.complete();
 assert.equal(state.completed,true);assert.equal((await welcomeState(home)).completed,true);
 assert.equal((await stat(join(home,'.agentcms','desktop-welcome.json'))).mode&0o777,0o600);
});
test('壊れた記録を完了として扱わない',async()=>{
 const home=await mkdtemp(join(tmpdir(),'sgk-welcome-invalid-'));await mkdir(join(home,'.agentcms'));
 await writeFile(join(home,'.agentcms','desktop-welcome.json'),'invalid');assert.equal((await welcomeState(home)).completed,false);
});
test('保存に失敗した場合は完了扱いにしない',async()=>{
 const home=await mkdtemp(join(tmpdir(),'sgk-welcome-save-'));await mkdir(join(home,'.agentcms','desktop-welcome.json'),{recursive:true});
 const state=await welcomeState(home);await assert.rejects(state.complete());assert.equal(state.completed,false);
});
