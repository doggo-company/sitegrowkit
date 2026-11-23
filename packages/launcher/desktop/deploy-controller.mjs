// 画面の確認票は一回限り・10分有効。実行結果は再起動後も確認できる。
import { readFile, writeFile, mkdir, rename } from 'node:fs/promises';
import { join, dirname } from 'node:path';
import { randomBytes } from 'node:crypto';
import { readConfig, saveConfig, targets, acquireLock } from './deployment.mjs';
export async function deploymentController({ home, worker, locked, setBusy, validProject }) {
  const states=new Map(), reviews=new Map();
  const historyFile=join(home,'.agentcms','desktop-deploy-history.json');
  let history=[];
  try{history=JSON.parse(await readFile(historyFile,'utf8')).slice(0,100).map(h=>h.status==='running'?{...h,status:'unknown',message:'前回の処理が中断されました。サーバーを確認してから再操作してください'}:h);}catch{/* 初回 */}
  for(const h of history){const k=h.siteId+':'+h.target;if(!states.has(k))states.set(k,{...h,events:h.events??[],checks:h.checks??[]});}
  let saving=Promise.resolve();
  function persist(){const data=JSON.stringify(history);saving=saving.catch(()=>{}).then(async()=>{await mkdir(dirname(historyFile),{recursive:true,mode:0o700});const tmp=historyFile+'.'+process.pid+'.tmp';await writeFile(tmp,data,{mode:0o600});await rename(tmp,historyFile);});return saving;}
  const key=(p,t)=>p.id+':'+t;
  async function handle(p,route,data) {
    const target=data.target;
    if(!targets.includes(target)) throw new Error('環境を選択してください');
    if(await validProject(p.root)!==p.root) throw new Error('サイトの保存先が見つかりません');
    const k=key(p,target);
    if(route==='remote-state') {
      const review=reviews.get(k);
      if(review&&review.expiresAt<Date.now()) reviews.delete(k);
      return {config:await readConfig(p.root,target),operation:states.get(k)??null,review:reviews.has(k)?{...reviews.get(k),source:undefined,configHash:undefined}:null,history:history.filter(h=>h.siteId===p.id&&h.target===target).slice(0,20)};
    }
    if(locked()) throw new Error('現在の処理が終わるまでお待ちください');
    setBusy(true);
    try {
      if(route==='remote-save') {
        const release=await acquireLock(p.root);
        try{const config=await saveConfig(p.root,target,data.config);reviews.delete(k);return {config};}finally{await release();}
      }
      const action=route.replace(/^remote-/,'');
      if(!['check','build','prepare','prepare-rollback','deploy','rollback'].includes(action)) throw new Error('操作が見つかりません');
      let expected;
      if(['deploy','rollback'].includes(action)) {
        expected=reviews.get(k);
        if(!expected||expected.expiresAt<Date.now()||expected.token!==data.token||expected.mode!==action||data.confirmTarget!==target) throw new Error('公開先と確認内容を確認し直してください');
      }
      reviews.delete(k);
      const op={id:randomBytes(12).toString('hex'),siteId:p.id,target,action,status:'running',startedAt:Date.now(),updatedAt:Date.now(),message:'処理を開始しています',events:[],checks:[]};
      states.set(k,op); history.unshift({...op,events:undefined,checks:undefined}); history=history.slice(0,100); await persist();
      const child=worker({action:'remote:'+action,root:p.root,target,expected});
      let pendingReview;
      child.on('message',e=>{
        if(e.type==='deploy-progress'){op.message=e.message;op.updatedAt=Date.now();op.events.push({at:op.updatedAt,message:e.message});op.events=op.events.slice(-80);const h=history.find(h=>h.id===op.id);if(h)Object.assign(h,{message:op.message,updatedAt:op.updatedAt,events:op.events});void persist();}
        if(e.type==='deploy-checks') op.checks=e.checks;
        if(e.type==='deploy-review') pendingReview=e.review;
      });
      child.once('error',()=>{op.message='実行プロセスを開始できませんでした';});
      child.once('close',code=>{
        op.status=code===0?'success':code===null?'unknown':'failed';op.finishedAt=Date.now();op.updatedAt=Date.now();
        if(code===0){op.message=({check:'接続確認が完了しました',build:'公開用ファイルの作成を確認しました',prepare:'公開内容を確認してください','prepare-rollback':'切り戻しの対象を確認してください',deploy:'公開と応答確認が完了しました',rollback:'旧版への切り戻しと応答確認が完了しました'})[action];
          if(pendingReview) reviews.set(k,{...pendingReview,token:randomBytes(32).toString('hex'),expiresAt:Date.now()+600000});
        }else op.message=code===null?'処理が中断されました。公開状態は未確認です。サーバーを確認してから再操作してください':'完了できませんでした。下の確認結果と詳細を確認してください';
        const entry=history.find(h=>h.id===op.id);if(entry)Object.assign(entry,{status:op.status,message:op.message,finishedAt:op.finishedAt,events:op.events,checks:op.checks});void persist().finally(()=>setBusy(false));
      });
      return {accepted:true};
    } catch(error) {
      const op=states.get(k);if(op?.status==='running'){op.status='failed';op.message='実行プロセスを開始できませんでした';const h=history.find(h=>h.id===op.id);if(h)Object.assign(h,{status:'failed',message:op.message});await persist();}
      throw error;
    } finally {
      if(states.get(k)?.status!=='running') setBusy(false);
    }
  }
  return {handle};
}
