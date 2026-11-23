// 配布物から起動し、実サイトの起動・停止とローカルAPIの境界を検証する。
import assert from 'node:assert/strict';
import { mkdtemp, writeFile, mkdir, readFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { request } from 'node:http';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { createRequire } from 'node:module';
const require = createRequire(new URL('../../../template/package.json', import.meta.url));
const { chromium } = require('@playwright/test');
const dist = resolve(process.argv[2]);
const project = resolve(process.argv[3]);
const screenshots=await mkdtemp(join(tmpdir(),'sitegrowkit-desktop-screens-'));console.log('screenshots='+screenshots);
const { startDesktop } = await import(pathToFileURL(join(dist, 'desktop/server.mjs')));
const home = await mkdtemp(join(tmpdir(), 'sitegrowkit-desktop-'));
await mkdir(join(home, '.agentcms'));
await writeFile(join(home, '.agentcms/desktop-projects.json'), JSON.stringify([project]), {mode:0o600});
const app = await startDesktop({ appDir: join(dist, 'app'), home, pickFolder: async()=>({ok:true,path:project}), openExternal:async()=>({opened:true}) });
const browser = await chromium.launch();
try {
 const ctx = await browser.newContext({viewport:{width:1280,height:850}});
 const page = await ctx.newPage(); const errors=[]; page.on('pageerror',e=>errors.push(e.message));
 assert.equal((await fetch(app.origin+'/api/state')).status,403);
 assert.equal((await fetch(app.origin+'/app.js')).status,403);
 await page.goto(app.url); assert.equal(page.url(),app.origin+'/');
 await page.locator('#welcome-skip').click();
 const state=()=>page.evaluate(async()=> (await fetch('/api/state')).json());
 await app.refresh();
 let s=await state(); assert.equal(s.projects.length,1); assert.equal(s.projects[0].root,project);
 const id=s.projects[0].id;
 const api=(action,data={id},origin)=>page.evaluate(async({action,data,origin})=>{
  const r=await fetch('/api/'+action,{method:'POST',headers:{'Content-Type':'application/json','x-sitegrowkit-desktop':'1',...(origin?{Origin:origin}:{})},body:JSON.stringify(data)});return {status:r.status,data:await r.json()};
 },{action,data,origin});
 const cookies=await ctx.cookies();const cookie=cookies.map(c=>`${c.name}=${c.value}`).join('; ');
 assert.equal((await fetch(app.origin+'/api/start',{method:'POST',headers:{Cookie:cookie,Origin:'https://evil.example','Content-Type':'application/json','x-sitegrowkit-desktop':'1'},body:JSON.stringify({id})})).status,403);
 assert.equal((await fetch(app.origin+'/api/start',{method:'POST',headers:{Cookie:cookie,Origin:app.origin,'Content-Type':'application/json'},body:JSON.stringify({id})})).status,403);
 assert.equal(await new Promise((resolve,reject)=>{const r=request(app.origin+'/api/state',{headers:{Cookie:cookie,Host:'evil.example'}},s=>{s.resume();resolve(s.statusCode)});r.on('error',reject);r.end();}),403);
 assert.equal((await api('start',{id:'../../tmp'})).status,404);
 assert.equal((await api('execute',{id,command:'touch /tmp/should-not-exist'})).status,404);
 assert.equal((await ctx.request.get(app.url)).status(),200); // 同一セッションの再読込は可能
 const other=await browser.newContext();assert.equal((await other.request.get(app.url)).status(),403);await other.close();
 assert.equal((await api('add')).status,200);assert.equal((await state()).projects.length,1);
 assert.equal((await api('folder')).data.opened,true);
 console.log('PASS token exchange, no unauthenticated assets/API, Origin/header/Host guards, unknown project/action, duplicate folder');
 for (const width of [320,390,1280]) {
  await page.setViewportSize({width,height:900});await page.waitForTimeout(2200);
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
  await page.evaluate(await readFile(require.resolve('axe-core/axe.min.js'),'utf8'));
  const a=await page.evaluate(()=>axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21aa']}}));
  assert.deepEqual(a.violations.map(v=>({id:v.id,nodes:v.nodes.map(n=>n.target)})),[]);
  await page.screenshot({path:join(screenshots,`desktop-${width}.png`),fullPage:true});
 }
 console.log('PASS desktop/mobile 320/390/1280 no overflow, axe, screenshots');
 // 対象は既存の隔離QAサイトのみ。停止時にデータ削除のオプションは渡さない。
 const row=page.locator(`[data-id="${id}"]`);
 async function clickAndWait(action, status) {
  await app.refresh();await page.waitForTimeout(2200);
  const [response]=await Promise.all([page.waitForResponse(r=>r.url().endsWith('/api/'+action)&&r.request().method()==='POST'),row.locator(`[data-action=${action}]`).click()]);
  assert.equal(response.status(),202,await response.text());
  let latest, prior='';const deadline=Date.now()+300000;
  do {latest=await state();const p=latest.projects[0];if(prior!==p.message){console.log('operation='+action+' status='+p.status+' step='+p.message);prior=p.message;}if(!latest.busy&&p.status===status) break;await page.waitForTimeout(1000);} while(Date.now()<deadline);
  const final=latest.projects[0];assert.equal(final.status,status);assert.equal(final.error,null);return final;
 }
 s=await state();
 if (s.projects[0].status!=='stopped') await clickAndWait('stop','stopped');
 const started=await clickAndWait('start','running');
 assert.equal((await fetch(started.url)).status,200);
 await page.waitForTimeout(2200);await row.locator('[data-action=folder]').focus();
 await page.waitForTimeout(2500);assert.equal(await page.evaluate(()=>document.activeElement.dataset.action),'folder');
 console.log('PASS actual GUI stop > stopped > start > running > public HTTP200; keyboard focus preserved');
 // セットアップは同梱済みの本体を別タブに開く。認証cookieは一覧と衝突しない。
 const [popup]=await Promise.all([page.waitForEvent('popup'),page.locator('#new').click()]);
 await popup.waitForURL(/127\.0\.0\.1:\d+\/$/);await popup.locator('[data-action=environment-start]').click();
 await popup.locator('[data-action=environment-continue]').waitFor({state:'visible',timeout:120000});
 await popup.waitForFunction(()=>!document.querySelector('[data-action=environment-continue]').disabled,{},{timeout:120000});
 assert.equal((await page.request.get(app.origin+'/api/state')).status(),200);
 console.log('PASS native-runtime Wizard launch and environment check; independent sessions');
 if(process.env.SGK_DESKTOP_CREATE==='1') {
  await popup.locator('[data-action=environment-continue]').click();
  const projectName='desktop-buyer-'+Date.now();
  await popup.fill('#siteName','GUI版の利用者確認');await popup.fill('#projectName',projectName);
  await popup.locator('[data-action=start]').click();
  await popup.waitForFunction(()=>!document.querySelector('[data-screen=READY]').hidden||!document.querySelector('[data-screen=ERROR]').hidden,{},{timeout:900000});
  assert(await popup.locator('[data-screen=READY]').isVisible(), 'GUI creation must reach READY');
  let next;const limit=Date.now()+10000;
  do{next=await state();if(next.projects.length===2)break;await page.waitForTimeout(500)}while(Date.now()<limit);
  assert.equal(next.projects.length,2);console.log('PASS new GUI site create/dev/all11gates > READY > project list: '+projectName);
 }
 const finish=await api('finish-setup',{});assert.equal(finish.status,200);
 await page.waitForTimeout(2200);assert.equal((await state()).wizardOpen,false);console.log('PASS finish setup returns control to project list');
 assert.deepEqual(errors,[]);
 const result=await api('quit',{});assert.equal(result.status,200);await app.closed;
 console.log('PASS graceful exit retains running site');
} finally {await browser.close();await app.close();}
