// 配布CMSと独立した利用者ホームで、初回・再起動・失敗からの再試行を操作する。
import assert from 'node:assert/strict';
import {mkdtemp,readFile,writeFile,mkdir} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join,resolve} from 'node:path';
import {pathToFileURL} from 'node:url';
import {createRequire} from 'node:module';
const require=createRequire(new URL('../../../template/package.json',import.meta.url));
const {chromium}=require('@playwright/test');
const desktop=resolve(process.argv[2]||'packages/launcher/desktop');
const appDir=resolve(process.argv[3]||'release/sitegrowkit-desktop-2.5-0.9.4-rc5-macos-arm64/SiteGrowKit.app/Contents/Resources/app');
const {startDesktop}=await import(pathToFileURL(join(desktop,'server.mjs')));
const {checkWelcomeEnvironment}=await import(pathToFileURL(join(desktop,'welcome.mjs')));
const home=await mkdtemp(join(tmpdir(),'sgk-welcome-'));
const screenshotDir=resolve('.codex/welcome-screens');await mkdir(screenshotDir,{recursive:true});
let checks=0,picks=0;
const project=resolve('.codex/network-buyer-20260910');
const picker=async()=>++picks===1?{canceled:true}:picks===2?{ok:true,path:home}:{ok:true,path:project};
const checker=async()=>++checks===1?{ready:false,checks:[{id:'docker',title:'サイトを動かす準備',status:'fail',message:'Docker Desktopを起動してください'}]}:checkWelcomeEnvironment(home);
let server=await startDesktop({appDir,home,pickFolder:picker,checkEnvironment:checker});
const browser=await chromium.launch();
try{
 const context=await browser.newContext(),page=await context.newPage(),errors=[];
 page.on('pageerror',e=>errors.push(e.name));
 assert.equal((await fetch(server.origin+'/api/welcome-check',{method:'POST'})).status,403);
 await page.goto(server.url);await page.locator('#welcome').waitFor({state:'visible'});
 assert(await page.locator('#workspace').isHidden());
 for(const width of [320,390,1280]){
  await page.setViewportSize({width,height:900});await page.evaluate(async()=>{await document.fonts.ready;await Promise.all(document.getAnimations().map(a=>a.finished));});
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
  await page.evaluate(await readFile(require.resolve('axe-core/axe.min.js'),'utf8'));
  const violations=await page.evaluate(async()=>(await axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21aa']}})).violations.map(v=>({id:v.id,nodes:v.nodes.map(n=>({target:n.target,summary:n.failureSummary}))})));assert.deepEqual(violations,[]);
  await page.screenshot({path:join(screenshotDir,'intro-'+width+'.png'),fullPage:true});
 }
 await page.emulateMedia({reducedMotion:'reduce'});assert.equal(await page.locator('.welcome__intro').evaluate(e=>getComputedStyle(e).animationName),'none');
 await page.locator('#welcome-start').click();await page.getByText('Docker Desktopを起動してください',{exact:true}).waitFor();
 assert(await page.locator('#welcome-next').isDisabled());
 await page.locator('#welcome-recheck').click();await page.locator('#welcome-next').waitFor();
 await page.waitForFunction(()=>!document.getElementById('welcome-next').disabled,{},{timeout:20000});
 assert.equal(await page.locator('.welcome__check[data-status=pass]').count(),3);
 for(const width of [320,1280]){
  await page.setViewportSize({width,height:900});assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
  const violations=await page.evaluate(async()=>(await axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21aa']}})).violations.map(v=>({id:v.id,nodes:v.nodes.map(n=>({target:n.target,summary:n.failureSummary}))})));assert.deepEqual(violations,[]);
  await page.screenshot({path:join(screenshotDir,'environment-'+width+'.png'),fullPage:true});
 }
 await page.locator('#welcome-next').click();await page.locator('#welcome-add').click();await page.getByText('追加をキャンセルしました。いつでも選び直せます',{exact:true}).waitFor();assert(await page.locator('#welcome').isVisible());
 await page.locator('#welcome-add').click();await page.waitForFunction(()=>document.getElementById('welcome-status').textContent.includes('フォルダを選んで'));assert(await page.locator('#welcome').isVisible());
 await page.screenshot({path:join(screenshotDir,'choose.png'),fullPage:true});
 await page.locator('#welcome-add').click();await page.locator('#workspace').waitFor({state:'visible'});assert.equal(await page.locator('.site-nav-item').count(),1);
 await page.reload();await page.locator('#workspace').waitFor({state:'visible'});assert(await page.locator('#welcome').isHidden());
 await page.locator('#welcome-reopen').click();await page.locator('#welcome-title').waitFor();await page.locator('#welcome-skip').click();await page.locator('#workspace').waitFor();
 // ブラウザとサーバーを再生成しても、利用者ホームの完了状態が維持される。
 await server.close();server=await startDesktop({appDir,home,pickFolder:picker});
 const next=await browser.newPage();await next.goto(server.url);await next.locator('#workspace').waitFor();assert(await next.locator('#welcome').isHidden());await next.close();
 assert.deepEqual(errors,[]);await server.close();
 // 新規利用者から実際の作成ウィザードへ渡し、重複したWelcomeを飛ばす。
 const freshHome=await mkdtemp(join(tmpdir(),'sgk-welcome-new-'));server=await startDesktop({appDir,home:freshHome});
 const fresh=await browser.newPage();await fresh.goto(server.url);await fresh.locator('#welcome-start').click();await fresh.waitForFunction(()=>!document.getElementById('welcome-next').disabled,{},{timeout:20000});await fresh.locator('#welcome-next').click();
 const [popup]=await Promise.all([fresh.waitForEvent('popup'),fresh.locator('#welcome-create').click()]);
 await popup.waitForURL(url=>url.protocol==='http:');await popup.locator('[data-screen=PROJECT]').waitFor({state:'visible',timeout:45000});
 assert(await popup.locator('#projectName').isVisible());await fresh.locator('#workspace').waitFor();
 await popup.close();await fresh.locator('#finish-setup').click();
 console.log(JSON.stringify({passed:true,checks:['first launch','responsive/axe/reduced motion','environment failure and live retry','folder cancel/invalid/success','reload and new process persistence','reopen and skip','real wizard PROJECT handoff'],screenshots:screenshotDir}));
}finally{await browser.close();await server.close();}
