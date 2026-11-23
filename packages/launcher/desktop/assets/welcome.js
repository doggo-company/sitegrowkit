// 案内の進行はポーリングで巻き戻さない。完了はサーバーに保存する。
(() => {
 const el=id=>document.getElementById(id);
 let initialized=false,working=false,checking=false,ready=false;
 function controls(){
  for(const id of ['welcome-start','welcome-create','welcome-add','welcome-skip','welcome-back','welcome-check-back']) el(id).disabled=working;
  el('welcome-recheck').disabled=checking||working;el('welcome-next').disabled=!ready||checking||working;
 }
 function step(index,focus=true){
  el('welcome').hidden=false;el('workspace').hidden=true;document.body.classList.add('is-welcome');
  document.title='ようこそ — SiteGrowKit';el('welcome-reopen').hidden=true;
  ['welcome-intro','welcome-environment','welcome-choose'].forEach((id,i)=>{el(id).hidden=i!==index;});
  document.querySelectorAll('[data-welcome-step]').forEach((item,i)=>{if(i===index)item.setAttribute('aria-current','step');else item.removeAttribute('aria-current');});
  if(focus)el(['welcome-title','welcome-environment-title','welcome-choose-title'][index]).focus();
  window.scrollTo(0,0);
 }
 function workspace(focus=true){
  el('welcome').hidden=true;el('workspace').hidden=false;document.body.classList.remove('is-welcome');
  document.title='サイト一覧 — SiteGrowKit';el('welcome-reopen').hidden=false;
  if(focus){el('site-title').tabIndex=-1;el('site-title').focus();}window.scrollTo(0,0);
 }
 async function check(){
  if(checking)return;checking=true;ready=false;controls();
  el('welcome-status').textContent='準備を確認しています';el('welcome-checks').replaceChildren();
  try {
   const result=await post('welcome-check');ready=result.ready;
   for(const item of result.checks){
    const row=document.createElement('div');row.className='welcome__check';row.dataset.status=item.status;
    const badge=document.createElement('span');badge.className='welcome__badge';badge.textContent=item.status==='pass'?'✓':'!';badge.setAttribute('aria-label',item.status==='pass'?'確認済み':'確認が必要');
    const text=document.createElement('div'),title=document.createElement('h2'),message=document.createElement('p');
    title.textContent=item.title;message.textContent=item.message;text.append(title,message);row.append(badge,text);el('welcome-checks').append(row);
   }
   el('welcome-status').textContent=ready?'準備が整いました 次へ進めます':'案内に沿って準備し「もう一度確認」を押してください';
  }catch{el('welcome-status').textContent='準備を確認できませんでした。接続を確かめて、もう一度お試しください';}
  finally{checking=false;controls();}
 }
 async function complete(){await post('welcome-complete');workspace();}
 el('welcome-start').addEventListener('click',()=>{step(1);void check();});
 el('welcome-recheck').addEventListener('click',()=>{void check();});
 el('welcome-next').addEventListener('click',()=>{if(ready&&!checking){el('welcome-status').textContent='';step(2);}});
 el('welcome-back').addEventListener('click',()=>{step(0);});
 el('welcome-check-back').addEventListener('click',()=>{step(1);});
 el('welcome-reopen').addEventListener('click',()=>{el('welcome-status').textContent='';step(0);});
 el('welcome-skip').addEventListener('click',async()=>{
  if(working)return;working=true;controls();
  try{await complete();}catch(error){el('welcome-status').textContent=error.message;}
  finally{working=false;controls();}
 });
 for(const [id,action] of [['welcome-create','new'],['welcome-add','add']])el(id).addEventListener('click',async()=>{
  if(working)return;working=true;controls();
  const popup=action==='new'?window.open('about:blank','_blank'):null;if(popup)popup.opener=null;
  let navigated=false;
  el('welcome-status').textContent=action==='new'?'サイトの設定画面を開いています':'追加するフォルダを選んでください';
  try{
   const result=await post(action,{fromWelcome:true});
   if(result.canceled){el('welcome-status').textContent='追加をキャンセルしました。いつでも選び直せます';return;}
   if(action==='new'){
    el('wizard-link').href=result.url;el('wizard-link').hidden=false;
    if(popup){popup.location.replace(result.url);navigated=true;}
   }
   await complete();await refresh();
   if(action==='new')el('notice').textContent='別のタブでサイトを設定してください。開かなかった場合は「セットアップ画面を開く」を押してください';
  }catch(error){if(!navigated)popup?.close();el('welcome-status').textContent=error.message;}
  finally{working=false;controls();}
 });
 window.SiteGrowKitWelcome={sync(data){
  el('welcome-reopen').disabled=data.busy||data.wizardOpen;
  if(initialized)return;initialized=true;el('app-loading').hidden=true;
  if(data.welcomeCompleted)workspace(false);else step(0,false);
 },error(){
  if(!initialized)el('app-loading').textContent='接続できませんでした。SiteGrowKitアプリを開き直してください';
  else if(!el('welcome').hidden)el('welcome-status').textContent='操作画面への接続が切れました。アプリを開き直してください';
 }};
})();
