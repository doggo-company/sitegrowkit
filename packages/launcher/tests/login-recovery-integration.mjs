// 隔離QAサイトでのみ実施。秘密情報や一時リンクの値を出力・撮影しない。
import assert from 'node:assert/strict';
import {mkdtemp,mkdir,writeFile,readFile} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join,resolve} from 'node:path';
import {pathToFileURL} from 'node:url';
import {createRequire} from 'node:module';
import {randomBytes} from 'node:crypto';
const require=createRequire(new URL('../../../template/package.json',import.meta.url));
const {chromium}=require('@playwright/test');
const project=resolve(process.argv[2]);assert(project.includes('/.codex/network-buyer-'),'隔離QAサイト以外では実施しません');
const desktop=resolve(process.argv[3]||'packages/launcher/desktop');
const appDir=resolve(process.argv[4]||'release/sitegrowkit-desktop-2.4-0.9.4-rc5-macos-arm64/SiteGrowKit.app/Contents/Resources/app');
const {startDesktop}=await import(pathToFileURL(join(desktop,'server.mjs')));
const home=await mkdtemp(join(tmpdir(),'sgk-login-'));await mkdir(join(home,'.agentcms'));await writeFile(join(home,'.agentcms/desktop-projects.json'),JSON.stringify([project]));
const server=await startDesktop({appDir,home});const browser=await chromium.launch();
try {
 const ctx=await browser.newContext({viewport:{width:1280,height:900}}),page=await ctx.newPage();
 const errors=[];page.on('pageerror',e=>errors.push(e.name));
 assert.equal((await fetch(server.origin+'/api/login-info',{method:'POST'})).status,403);
 await page.goto(server.url);await page.locator('#welcome-skip').click();await server.refresh();
 const s=await page.evaluate(async()=> (await fetch('/api/state')).json());const id=s.projects[0].id,name=s.projects[0].name;
 const post=async(action,data)=>page.evaluate(async({action,data})=>{const r=await fetch('/api/'+action,{method:'POST',headers:{'Content-Type':'application/json','x-sitegrowkit-desktop':'1'},body:JSON.stringify(data)});return {status:r.status,data:await r.json()};},{action,data});
 assert.equal((await post('login-info',{id,target:'production'})).status,403);
 assert.equal((await post('login-reset',{id,target:'local',confirmSite:'wrong'})).status,400);
 assert.equal((await post('login-info',{id:'unknown',target:'local'})).status,404);
 const cookies=await ctx.cookies();const cookie=cookies.map(c=>c.name+'='+c.value).join('; ');
 const bad=await fetch(server.origin+'/api/login-info',{method:'POST',headers:{Cookie:cookie,Origin:'https://evil.example','Content-Type':'application/json','x-sitegrowkit-desktop':'1'},body:JSON.stringify({id,target:'local'})});assert.equal(bad.status,403);
 const button=page.locator('[data-action=login-info]');await button.click();await page.locator('#login-content').waitFor({state:'visible'});
 const email=await page.locator('#login-email').inputValue();assert(email.length>0);
 assert.equal(await page.locator('#login-password').getAttribute('type'),'password');
 const hadInitial=await page.locator('#initial-section').isVisible();
 if(hadInitial){await page.locator('#show-login-password').click();assert.equal(await page.locator('#login-password').getAttribute('type'),'text');await page.locator('#show-login-password').click();}
 for(const width of [320,390,1280]){
  await page.setViewportSize({width,height:900});assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
  await page.evaluate(await readFile(require.resolve('axe-core/axe.min.js'),'utf8'));
  const violations=await page.evaluate(async()=> (await axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21aa']}})).violations.map(v=>v.id));assert.deepEqual(violations,[]);
 }
 // クリップボードは隔離ブラウザ内で試験用スタブに置換し、実際のOSへ秘密を出さない。
 await page.evaluate(()=>{window.copyCount=0;Object.defineProperty(navigator,'clipboard',{value:{writeText:async()=>{window.copyCount++}}});});
 await page.locator('#copy-login-email').click();assert.equal(await page.evaluate(()=>window.copyCount),1);
 await page.locator('#login-confirm').check();
 const [popup]=await Promise.all([page.waitForEvent('popup'),page.locator('#login-reset').click()]);
 await popup.waitForURL(/\/reset-password\//,{timeout:30000});await popup.waitForLoadState('domcontentloaded');
 const resetUrl=new URL(popup.url()),token=decodeURIComponent(resetUrl.pathname.split('/').pop());
 const siteOrigin=resetUrl.origin,password=randomBytes(24).toString('base64url')+'Aa1!';
 const xsrf=async(context)=>decodeURIComponent((await context.cookies(siteOrigin)).find(c=>c.name==='XSRF-TOKEN').value);
 const reset=await ctx.request.post(siteOrigin+'/reset-password',{headers:{'X-XSRF-TOKEN':await xsrf(ctx),Accept:'application/json'},data:{email,token,password,password_confirmation:password},maxRedirects:0});
 assert([200,302,303].includes(reset.status()),'標準の再設定が成功する');
 const reuse=await ctx.request.post(siteOrigin+'/reset-password',{headers:{'X-XSRF-TOKEN':await xsrf(ctx),Accept:'application/json'},data:{email,token,password,password_confirmation:password},maxRedirects:0});assert.equal(reuse.status(),422,'再設定トークンは1回のみ');
 const loginCtx=await browser.newContext();await loginCtx.request.get(siteOrigin+'/login');
 const login=await loginCtx.request.post(siteOrigin+'/login',{headers:{'X-XSRF-TOKEN':await xsrf(loginCtx),Accept:'application/json'},data:{email,password},maxRedirects:0});assert([200,302].includes(login.status()),'新しいパスワードでログイン成功');
 const admin=await loginCtx.request.get(siteOrigin+'/admin');assert.equal(admin.status(),200);assert(new URL(admin.url()).pathname.startsWith('/admin'),'ログイン画面へ戻されず管理画面に到達する');
 await loginCtx.close();await popup.close();
 await page.locator('#login-close').click();await page.waitForFunction(()=>document.getElementById('login-password').value === '');
 await button.click();await page.locator('#login-content').waitFor({state:'visible'});assert(await page.locator('#initial-section').isHidden(),'変更前の初期パスワードは表示しない');
 const state=await page.evaluate(async()=> (await fetch('/api/state')).json());assert(!JSON.stringify(state).includes(token));assert(!JSON.stringify(state).includes(password));
 await page.locator('#login-close').click();assert.deepEqual(errors,[]);
 console.log(JSON.stringify({passed:true,hadInitial,tests:['authentication/Origin/target/confirmation guards','initial credential masked and verified','mobile/desktop/axe','clipboard action','standard password reset','single-use token','login with new password','stale initial password hidden','dialog clear','no secret in state']}));
} finally {await browser.close();await server.close();}
