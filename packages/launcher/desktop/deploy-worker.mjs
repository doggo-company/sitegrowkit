// 配布済みエンジンを利用する。公開実行前に設定とソースをもう一度照合する。
import { readConfig, digest, sourceDigest, acquireLock } from './deployment.mjs';
export async function runDeployment({ load, root, action, target, expected }, send) {
  const release=await acquireLock(root);
  try {
    process.chdir(root);
    const config=await readConfig(root,target);
    if(!config) throw new Error('公開先を保存してください');
    const {sanitizeLogLine}=await load('wizard/sanitize.js');
    const clean=v=>sanitizeLogLine(String(v)).slice(0,3000);
    const scrub=v=>JSON.parse(JSON.stringify(v,(_k,x)=>typeof x==='string'?clean(x):x));
    const {setUiSink}=await load('ui.js');
    setUiSink(e=>send({type:'deploy-progress',message:clean(e.kind==='fail'?`${e.failure.what}\n${e.failure.why}\n${e.failure.fix}`:e.text)}));
    const engine=await load('commands/deploy.js');
    if(action==='build') return await engine.deployStaging(target,{dryRun:true,yes:true,confirmTarget:target});
    const {SshExecutor}=await load('deploy/executor.js');
    const {xserverDoctor}=await load('deploy/providers/xserver.js');
    const exec=new SshExecutor(config);
    const {currentTarget,pickRollbackTarget}=await load('deploy/release.js');
    const current=()=>currentTarget({cfg:config,exec});
    async function rollbackTarget(cur) {
      const list=await exec.exec(`ls -1 '${config.remote.appDir}/releases' | sort -r | head -50`);
      if(list.code!==0) throw new Error('旧版の一覧を取得できません');
      const prev=pickRollbackTarget(list.stdout.split('\n').filter(Boolean),cur?.split('/').pop()??null);
      if(!prev||!/^[a-zA-Z0-9_-]+$/.test(prev)) throw new Error('切り戻せる旧版がありません');
      return prev;
    }
    if(action==='check'||action==='prepare'||action==='prepare-rollback') {
      send({type:'deploy-progress',message:'サーバーの接続と公開環境を確認中'});
      const {capabilities}=await xserverDoctor(exec,config,null);
      send({type:'deploy-checks',checks:scrub(capabilities.checks)});
      if(capabilities.checks.some(c=>c.status==='FAIL')) return 1;
      if(action==='check') return 0;
      const source=await sourceDigest(root);
      if(action==='prepare') {
        send({type:'deploy-progress',message:'公開用ファイルをこのパソコンで作成中'});
        const code=await engine.deployStaging(target,{dryRun:true,yes:true,confirmTarget:target});
        if(code!==0) return code;
      } else if(!capabilities.currentRelease) throw new Error('切り戻し先を確認できません。公開済みの環境を選択してください');
      const {buildPlan}=await load('deploy/plan.js');
      const plan=await buildPlan(root,config,exec,capabilities);
      if(source!==await sourceDigest(root)||digest(config)!==digest(await readConfig(root,target))) throw new Error('確認中にファイルまたは接続先が変わりました。もう一度確認してください');
      const rollbackRelease=action==='prepare-rollback'?await rollbackTarget(capabilities.currentRelease):null;
      send({type:'deploy-review',review:{plan:scrub(plan),configHash:digest(config),source,mode:action==='prepare'?'deploy':'rollback',currentRelease:capabilities.currentRelease,rollbackRelease}});
      return 0;
    }
    if(!['deploy','rollback'].includes(action)||!expected||expected.expiresAt<Date.now()||expected.mode!==action||expected.configHash!==digest(config)||expected.source!==await sourceDigest(root)) throw new Error('確認後に内容が変わりました。公開内容を確認し直してください');
    const cur=await current();
    if(cur!==expected.currentRelease || (action==='rollback'&&await rollbackTarget(cur)!==expected.rollbackRelease)) throw new Error('サーバーの公開版が変わりました。確認し直してください');
    // エンジン側でも非ステージング環境への明示確認を必須にする。
    return action==='deploy'?await engine.deployStaging(target,{yes:true,confirmTarget:target}):await engine.deployRollback(target,{yes:true,confirmTarget:target});
  } finally { await release(); }
}
