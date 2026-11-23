// 配布版GUIから接続設定・接続失敗・実Dockerビルドまで操作する。外部公開はしない。
import assert from 'node:assert/strict';
import {mkdtemp,mkdir,writeFile,readFile,unlink} from 'node:fs/promises';
import {join,resolve} from 'node:path';
import {tmpdir} from 'node:os';
import {createRequire} from 'node:module';
import {pathToFileURL} from 'node:url';
const require=createRequire(new URL('../../../template/package.json',import.meta.url));
const {chromium}=require('@playwright/test');
const dist=resolve(process.argv[2]),root=resolve(process.argv[3]);
const configFile=join(root,'.agentcms/deploy/staging.json');let original;
try{original=await readFile(configFile);}catch(e){if(e.code!=='ENOENT')throw e;}
const home=await mkdtemp(join(tmpdir(),'sgk-remote-ui-'));
await mkdir(join(home,'.agentcms'));await writeFile(join(home,'.agentcms/desktop-projects.json'),JSON.stringify([root]));
const {startDesktop}=await import(pathToFileURL(join(dist,'desktop/server.mjs')));
const app=await startDesktop({appDir:join(dist,'app'),home});
const browser=await chromium.launch();
try{
 const page=await browser.newPage({viewport:{width:1280,height:900}});const errors=[];page.on('pageerror',e=>errors.push(e.message));await page.goto(app.url);await page.locator('#welcome-skip').click();await app.refresh();
 await page.locator('[data-env=staging]').click();await page.locator('#remote-title').waitFor();
 await page.locator('#connection').evaluate(e=>e.open=true);
 const f=page.locator('#connection-form');
 const values={url:'https://desktop-qa.example.com',host:'127.0.0.1',user:'sgkqa',port:'1',identityFile:'',appDir:'/home/sgkqa/apps/stage',docroot:'/home/sgkqa/stage.example.com/public_html',phpBin:'auto',dbHost:'localhost',healthPath:'/up',keepReleases:'3'};
 for(const [name,value] of Object.entries(values))await f.locator(`[name=${name}]`).fill(value);
 const saved=page.waitForResponse(r=>r.url().endsWith('/api/remote-save'));await page.locator('#save-connection').click();assert.equal((await saved).status(),200);
 await page.locator('[data-remote=check]').click();
 await page.waitForFunction(()=>document.querySelector('#operation').dataset.status==='failed',null,{timeout:120000});
 assert(await page.locator('#checks').innerText().then(t=>t.includes('FAIL')));assert(await page.locator('#review').isHidden());
 console.log('PASS GUI config save > real SSH loopback refusal > actionable FAIL; no review and no external server connection');
 for(const width of [320,390,1280]){
  await page.setViewportSize({width,height:900});await page.locator('#connection').evaluate(e=>e.open=true);
  await page.evaluate(await readFile(require.resolve('axe-core/axe.min.js'),'utf8'));
  const result=await page.evaluate(()=>axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21aa']}}));
  assert.deepEqual(result.violations.map(v=>({id:v.id,nodes:v.nodes.map(n=>n.target)})),[]);
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
  await page.screenshot({path:join(home,`remote-${width}.png`),fullPage:true});
 }
 console.log('PASS remote forms/status desktop/mobile 320/390/1280 axe and overflow');
 await page.locator('#connection').evaluate(e=>e.open=false);
 const buildResponse=page.waitForResponse(r=>r.url().endsWith('/api/remote-build'));
 await page.locator('[data-remote=build]').click();assert.equal((await buildResponse).status(),202);
 // 操作開始と終了をAPIでも照合し、前回の結果を誤って成功扱いしない。
 let state;const deadline=Date.now()+600000;
 do{state=await page.evaluate(async()=>{const s=await(await fetch('/api/state')).json();return(await fetch('/api/remote-state',{method:'POST',headers:{'Content-Type':'application/json','x-sitegrowkit-desktop':'1'},body:JSON.stringify({id:s.projects[0].id,target:'staging'})})).json();});if(state.operation?.action==='build'&&state.operation.status!=='running')break;await page.waitForTimeout(1000);}while(Date.now()<deadline);
 assert.equal(state.operation.action,'build');assert.equal(state.operation.status,'success',JSON.stringify(state.operation.events));
 assert(state.history.some(h=>h.action==='build'&&h.status==='success'));
 console.log('PASS real packaged GUI build using Docker > success > history (dry-run, no upload)');
 assert.deepEqual(errors,[]);console.log('screenshots='+home);
}finally{await browser.close();await app.close();if(original)await writeFile(configFile,original);else await unlink(configFile).catch(()=>{});}
