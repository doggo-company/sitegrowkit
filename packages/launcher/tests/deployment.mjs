// 実サーバーへ接続せず、確認票と実行の境界を実際のコントローラー・ワーカーで検査する。
import assert from 'node:assert/strict';
import {mkdtemp,mkdir,writeFile,readFile,symlink,stat} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {EventEmitter} from 'node:events';
import {validateConfig,saveConfig,readConfig,sourceDigest,acquireLock,digest} from '../desktop/deployment.mjs';
import {runDeployment} from '../desktop/deploy-worker.mjs';
import {deploymentController} from '../desktop/deploy-controller.mjs';
const root=await mkdtemp(join(tmpdir(),'sgk-deploy-test-'));
await mkdir(join(root,'app'));await writeFile(join(root,'app/site.php'),'original');
const cfg={provider:'xserver',target:'staging',ssh:{host:'sv.example.com',port:10022,user:'example'},remote:{appDir:'/home/example/apps/stage',docroot:'/home/example/stage.example.com/public_html',phpBin:'auto'},app:{url:'https://stage.example.com',healthPath:'/up'},keepReleases:3};
for(const mutate of [c=>c.ssh.host='-oProxyCommand=evil',c=>c.ssh.port=0,c=>c.remote.appDir='/home/test;evil',c=>c.remote.appDir='/home/../public',c=>c.app.url='https://user:secret@example.com',c=>c.app.url='https://example.com/evil',c=>c.remote.docroot=c.remote.appDir,c=>c.target='production',c=>c.ssh.user='u\ncommand',c=>c.app.healthPath='//elsewhere']){const c=structuredClone(cfg);mutate(c);assert.throws(()=>validateConfig(c,'staging'));}
await saveConfig(root,'staging',cfg);assert.deepEqual(await readConfig(root,'staging'),cfg);assert.equal((await stat(join(root,'.agentcms/deploy/staging.json'))).mode&0o777,0o600);
await assert.rejects(()=>saveConfig(root,'production',{...cfg,target:'production'}));
const lock=await acquireLock(root);await assert.rejects(()=>acquireLock(root));await lock();
const before=await sourceDigest(root);await writeFile(join(root,'app/site.php'),'changed');assert.notEqual(await sourceDigest(root),before);
await symlink('/etc/hosts',join(root,'app/link'));await assert.rejects(()=>sourceDigest(root));await (await import('node:fs/promises')).unlink(join(root,'app/link'));
console.log('PASS invalid SSH/path/URL/target rejected; environment isolation; private config; cross-instance lock; source edits/links detected');
let currentRelease='/home/example/apps/stage/releases/20260910_2',failDoctor=false,calls=[];
const fakeModules={
 'wizard/sanitize.js':{sanitizeLogLine:s=>s.replace(/password=\S+/g,'password=********')},
 'ui.js':{setUiSink:()=>{}},
 'commands/deploy.js':{deployStaging:async(t,o)=>{calls.push({action:'deploy',t,o});return 0;},deployRollback:async(t,o)=>{calls.push({action:'rollback',t,o});return 0;}},
 'deploy/executor.js':{SshExecutor:class {async exec(){return {code:0,stdout:'20260910_2\n20260910_1',stderr:''};}}},
 'deploy/providers/xserver.js':{xserverDoctor:async()=>({capabilities:{currentRelease,checks:[{name:'SSH',status:failDoctor?'FAIL':'PASS'}]}})},
 'deploy/plan.js':{buildPlan:async()=>({appUrl:cfg.app.url,artifact:{fileCount:2,sizeBytes:10},database:{pendingMigrations:[],source:'test'},backup:{},docrootActions:[],symlinkChanges:[],cronChanges:[]})},
 'deploy/release.js':{currentTarget:async()=>currentRelease,pickRollbackTarget:()=> '20260910_1'}
};
const load=async n=>{assert(fakeModules[n],n);return fakeModules[n];};
let events=[];
await runDeployment({load,root,target:'staging',action:'prepare'},e=>events.push(e));
let review={...events.find(e=>e.type==='deploy-review').review,expiresAt:Date.now()+10000};
await runDeployment({load,root,target:'staging',action:'deploy',expected:review},()=>{});
assert.equal(calls.at(-1).o.confirmTarget,'staging');assert.equal(calls.at(-1).o.yes,true);
await writeFile(join(root,'app/site.php'),'changed again');
await assert.rejects(()=>runDeployment({load,root,target:'staging',action:'deploy',expected:review},()=>{}));
review.source=await sourceDigest(root);currentRelease='other';await assert.rejects(()=>runDeployment({load,root,target:'staging',action:'deploy',expected:review},()=>{}));
review.currentRelease=currentRelease;review.configHash='wrong';await assert.rejects(()=>runDeployment({load,root,target:'staging',action:'deploy',expected:review},()=>{}));
failDoctor=true;events=[];assert.equal(await runDeployment({load,root,target:'staging',action:'prepare'},e=>events.push(e)),1);assert(!events.some(e=>e.type==='deploy-review'));failDoctor=false;
console.log('PASS worker prepare/build/deploy; source/config/current-release mismatch blocked; failed checks never grant approval');
const prod=structuredClone(cfg);prod.target='production';prod.app.url='https://www.example.com';prod.remote.appDir='/home/example/apps/live';prod.remote.docroot='/home/example/www.example.com/public_html';
await saveConfig(root,'production',prod);events=[];
await runDeployment({load,root,target:'production',action:'prepare-rollback'},e=>events.push(e));
const prodReview={...events.find(e=>e.type==='deploy-review').review,expiresAt:Date.now()+10000};
await assert.rejects(()=>runDeployment({load,root,target:'production',action:'rollback',expected:{...prodReview,expiresAt:1}},()=>{}));
await runDeployment({load,root,target:'production',action:'rollback',expected:prodReview},()=>{});
assert.equal(calls.at(-1).t,'production');assert.equal(calls.at(-1).o.confirmTarget,'production');assert.equal(calls.at(-1).action,'rollback');
events=[];await runDeployment({load,root,target:'production',action:'prepare'},e=>events.push(e));
await runDeployment({load,root,target:'production',action:'deploy',expected:{...events.find(e=>e.type==='deploy-review').review,expiresAt:Date.now()+10000}},()=>{});
assert.equal(calls.at(-1).t,'production');assert.equal(calls.at(-1).o.confirmTarget,'production');
console.log('PASS production deploy/rollback use exact explicit target; expired approval blocked');
const home=await mkdtemp(join(tmpdir(),'sgk-controller-test-'));let busy=false,lastChild,lastPayload;
const worker=payload=>{lastPayload=payload;lastChild=new EventEmitter();return lastChild;};
const options={home,worker,locked:()=>busy,setBusy:v=>busy=v,validProject:async x=>x};
const controller=await deploymentController(options);const p={id:'site1',root};
const invoke=(route,data={})=>controller.handle(p,'remote-'+route,{target:'staging',...data});
await assert.rejects(()=>invoke('deploy',{token:'bad',confirmTarget:'staging'}));assert.equal(busy,false);
await invoke('prepare');assert(busy);await assert.rejects(()=>invoke('save',{config:cfg}));
lastChild.emit('message',{type:'deploy-review',review:{mode:'deploy',configHash:digest(cfg),source:await sourceDigest(root),plan:{}}});lastChild.emit('close',0);
while(busy)await new Promise(r=>setTimeout(r,10));
const state=await invoke('state');assert(state.review.token);assert.equal(state.review.source,undefined);
await assert.rejects(()=>invoke('deploy',{token:state.review.token,confirmTarget:'production'}));
await invoke('deploy',{token:state.review.token,confirmTarget:'staging'});assert.equal(lastPayload.expected.mode,'deploy');lastChild.emit('close',0);while(busy)await new Promise(r=>setTimeout(r,10));
await assert.rejects(()=>invoke('deploy',{token:state.review.token,confirmTarget:'staging'}));
const again=await deploymentController(options);assert.equal((await again.handle(p,'remote-state',{target:'staging'})).history[0].status,'success');
console.log('PASS controller single-use confirmation, target mismatch/replay blocked, operation serialization and persisted success history');
console.log('No external server contacted. Deployment engine was faked only at the explicit load boundary.');
