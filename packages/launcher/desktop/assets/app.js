// 状態はサーバーの確認結果を使う。ポーリングでボタンやフォーカスを作り直さない。
const $ = (id) => document.getElementById(id);
const cards = new Map();
let loginData = null, loginSite = null, loginTimer, loginRequest = 0, loginBusy = false;
function clearLogin() {
  loginRequest++; clearTimeout(loginTimer); loginData = loginSite = null;
  $('login-password').value = $('login-email').value = ''; $('login-password').type = 'password';
  $('login-account').replaceChildren(); $('login-reset-link').removeAttribute('href'); $('login-reset-link').hidden = true;
  $('login-open').removeAttribute('href'); $('login-content').hidden = true; $('login-confirm').checked = false;
  $('login-reset').disabled = true; loginBusy = false;
}
function armLoginExpiry() {
  clearTimeout(loginTimer); loginTimer = setTimeout(() => { clearLogin(); $('login-notice').textContent = '情報を非表示にしました。閉じてから、もう一度開いてください'; }, 60000);
}
function selectLoginAccount() {
  const email = $('login-account').value; $('login-email').value = email;
  const initial = loginData?.initial?.email === email ? loginData.initial.password : '';
  $('login-password').value = initial; $('login-password').type = 'password';
  $('show-login-password').textContent = '表示'; $('show-login-password').setAttribute('aria-pressed','false');
  $('initial-section').hidden = !initial; $('initial-missing').hidden = Boolean(initial);
  $('login-confirm').checked = false; $('login-reset').disabled = true;
  $('login-reset-link').hidden = true; $('login-reset-link').removeAttribute('href');
}
async function openLogin(id) {
  clearLogin(); const request = loginRequest;
  loginSite = siteState?.projects.find(p => p.id === id);
  if (!loginSite) return;
  $('login-site').textContent = loginSite.name; $('login-notice').textContent = 'ログイン情報を確認しています';
  $('login-dialog').showModal();
  try {
    const result = await post('login-info', {id, target:'local'});
    if (request !== loginRequest || !$('login-dialog').open) return;
    loginData = result;
    for (const account of result.accounts) {const option = document.createElement('option');option.value = account.email;option.textContent = account.name+' — '+account.email;$('login-account').append(option);}
    if (!result.accounts.length) { $('login-notice').textContent = '有効な管理者が見つかりません。サイトのオーナーに確認してください';return; }
    if (result.initial) $('login-account').value = result.initial.email;
    selectLoginAccount(); $('login-open').href = result.loginUrl;
    $('login-content').hidden = false; $('login-notice').textContent = ''; armLoginExpiry();
  } catch (error) {if (request === loginRequest) $('login-notice').textContent = error.message;}
}
$('login-close').addEventListener('click', () => $('login-dialog').close());
$('login-dialog').addEventListener('close', clearLogin);
window.addEventListener('pagehide', clearLogin);
document.addEventListener('visibilitychange', () => {if(document.hidden){$('login-password').type='password';$('show-login-password').textContent='表示';$('show-login-password').setAttribute('aria-pressed','false');}});
$('login-account').addEventListener('change', selectLoginAccount);
$('show-login-password').addEventListener('click', () => {const show = $('login-password').type === 'password';$('login-password').type = show ? 'text' : 'password';$('show-login-password').textContent = show ? '隠す' : '表示';$('show-login-password').setAttribute('aria-pressed',String(show));});
for (const field of ['email','password']) $('copy-login-'+field).addEventListener('click', async () => {
  const value = $('login-'+field).value;
  try {await navigator.clipboard.writeText(value);$('login-notice').textContent = (field === 'email' ? 'メールアドレス' : 'パスワード')+'をコピーしました';}
  catch {$('login-notice').textContent = 'コピーできませんでした。入力欄を選択してコピーしてください';}
});
$('login-confirm').addEventListener('change', () => {$('login-reset').disabled = !$('login-confirm').checked || loginBusy;});
$('login-reset').addEventListener('click', async () => {
  if (!loginSite || !$('login-confirm').checked || loginBusy) return;
  loginBusy = true; $('login-reset').disabled = true;
  const request = loginRequest, popup = window.open('about:blank','_blank');if(popup) popup.opener = null;
  $('login-notice').textContent = '再設定画面を準備しています';
  try {
    const result = await post('login-reset', {id:loginSite.id, target:'local', email:$('login-account').value, confirmSite:loginSite.name});
    if (request !== loginRequest || !$('login-dialog').open) {popup?.close();return;}
    $('login-reset-link').href = result.url; $('login-reset-link').hidden = false;
    if(popup) popup.location.replace(result.url);
    $('login-notice').textContent = '開いた画面で新しいパスワードを設定してください。リンクは'+result.expiresMinutes+'分間、1回だけ使えます';
    $('login-confirm').checked = false; armLoginExpiry();
  } catch (error) {popup?.close();if(request === loginRequest) $('login-notice').textContent = error.message;}
  finally {if(request === loginRequest){loginBusy=false;$('login-reset').disabled=!$('login-confirm').checked;}}
});
let posting = false, ended = false, selected, environment='local', siteState, remoteState, remoteKey='', dirty=false, remoteLoading=false;
const navItems=new Map();
async function post(action, data = {}) {
  const response = await fetch(`/api/${action}`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'x-sitegrowkit-desktop': '1' }, body: JSON.stringify(data) });
  const result = await response.json();
  if (!response.ok) throw new Error(result.error || '操作できませんでした');
  return result;
}
async function act(action, id) {
  if (action === 'login-info') { void openLogin(id); return; }
  if (posting) return;
  posting = true; $('notice').textContent = '';
  // ユーザーのクリック中にタブを確保し、ポップアップ抑止を回避する。
  const popup = action === 'new' ? window.open('about:blank', '_blank') : null;
  if (popup) popup.opener = null;
  try {
    const result = await post(action, { id });
    if (action === 'new') {
      const link = $('wizard-link'); link.href = result.url; link.hidden = false;
      if (popup) popup.location.replace(result.url);
      $('notice').textContent = '別のタブでセットアップを進めてください。完了するとサイト一覧に追加されます';
    }
    if (action === 'folder' && !result.opened) $('notice').textContent = '保存先を開けませんでした。サイト名の下に表示されたフォルダを確認してください';
    if (action === 'quit') {
      ended = true; $('notice').textContent = 'アプリを終了しました。このタブは閉じてかまいません';
      document.querySelectorAll('button').forEach(b => b.disabled = true);
    }
    if (!ended) await refresh();
  } catch (error) { popup?.close(); $('notice').textContent = error.message; }
  finally { posting = false; }
}
for (const action of ['new', 'add', 'quit', 'finish-setup']) $(action).addEventListener('click', () => { void act(action); });
async function refresh() {
  if (ended) return;
  try {
    const response = await fetch('/api/state');
    if (!response.ok) throw new Error('session');
    const data = await response.json();
    siteState=data;
    window.SiteGrowKitWelcome.sync(data);
    if(!selected&&data.projects.length) selected=data.projects[0].id;
    $('empty').hidden = data.projects.length > 0;
    $('new').disabled = $('add').disabled = $('quit').disabled = data.busy;
    $('finish-setup').hidden = !data.wizardOpen; $('finish-setup').disabled = data.busy;
    if (!data.wizardOpen) $('wizard-link').hidden = true;
    for (const p of data.projects) {
      if(!navItems.has(p.id)) {const b=document.createElement('button');b.className='site-nav-item';b.addEventListener('click',()=>select(p.id,environment));navItems.set(p.id,b);$('site-nav').append(b);}
      const nav=navItems.get(p.id);nav.textContent=p.name;nav.dataset.state=p.status;nav.setAttribute('aria-current',String(selected===p.id));nav.hidden=!p.name.toLowerCase().includes($('search').value.toLowerCase());
      if (!cards.has(p.id)) {
        const card = $('card').content.firstElementChild.cloneNode(true);
        card.dataset.id = p.id; cards.set(p.id, card); $('projects').append(card);
        for (const button of card.querySelectorAll('button')) button.addEventListener('click', () => { void act(button.dataset.action, p.id); });
      }
      const card = cards.get(p.id);
      card.hidden=selected!==p.id;
      card.querySelector('h3').textContent = p.name;
      card.querySelector('.path').textContent = p.root;
      const status = card.querySelector('.status'); status.dataset.state = p.status;
      status.textContent = ({ running: '稼働中', stopped: '停止中', busy: '処理中', unknown: '未確認' })[p.status];
      let message = p.message;
      if (p.status === 'busy') {
        message += ` · ${Math.floor((Date.now() - p.startedAt) / 1000)}秒経過`;
        if (Date.now() - p.progressAt > 90000) message += '（この工程に時間がかかっています）';
      }
      card.querySelector('.message').textContent = message;
      const error = card.querySelector('.error'); error.hidden = !p.error;
      error.textContent = p.error ? `前の操作を完了できませんでした。Docker Desktopの起動とネット接続を確認して再試行してください。確認コード: ${p.error}` : '';
      card.querySelector('[data-action=start]').disabled = data.busy || data.checking || data.wizardOpen || p.status === 'running';
      card.querySelector('[data-action=login-info]').disabled = data.busy || data.checking || p.status !== 'running';
      card.querySelector('[data-action=stop]').disabled = data.busy || data.checking || data.wizardOpen || p.status === 'stopped';
      for (const name of ['admin', 'public']) {
        const link = card.querySelector(`[data-link=${name}]`); link.hidden = p.status !== 'running' || !p.url;
        if (p.url) link.href = p.url + (name === 'admin' ? 'admin' : '');
      }
      card.querySelector('.checked').textContent = p.checkedAt ? `最終確認 ${new Date(p.checkedAt).toLocaleTimeString('ja-JP')}` : '状態を確認中';
    }
    const current=data.projects.find(p=>p.id===selected);
    $('site-title').textContent=current?.name||'サイトを育てよう';
    $('site-description').textContent=current?'つくったあとも 大切に育てる':'小さなアイデアから あなたのサイトを育てよう';
    if(environment!=='local'&&selected) await refreshRemote();
  } catch {
    window.SiteGrowKitWelcome.error();
    $('notice').textContent = '操作画面への接続が切れました。SiteGrowKitアプリを開き直してください。実行中のサイトの状態は未確認です';
    document.querySelectorAll('button').forEach(b => b.disabled = true);
  }
}
void refresh(); setInterval(() => { void refresh(); }, 2000);

// 環境ごとのフォームはポーリングで再生成しない。入力中・閲覧中の位置を保持する。
function select(id,env) {
  if(dirty && (id!==selected||env!==environment)) { $('remote-notice').textContent='編集中の接続設定を保存してから切り替えてください';return; }
  selected=id;environment=env;remoteKey='';remoteState=null;
  form.hidden=env!=='local';
  $('local-panel').hidden=env!=='local';$('remote-panel').hidden=env==='local';
  for(const b of document.querySelectorAll('[data-env]')) b.setAttribute('aria-pressed',String(b.dataset.env===env));
  $('review').hidden=true;$('operation').hidden=true;$('remote-notice').textContent='';
  void refresh();
}
for(const b of document.querySelectorAll('[data-env]')) b.addEventListener('click',()=>select(selected,b.dataset.env));
$('search').addEventListener('input',()=>{for(const p of siteState?.projects||[])navItems.get(p.id).hidden=!p.name.toLowerCase().includes($('search').value.toLowerCase());});
const form=$('connection-form');
// 保存せず戻す操作を用意し、入力ミスで環境切替ができなくならないようにする。
const cancel=document.createElement('button');cancel.type='button';cancel.textContent='変更を取り消す';cancel.className='quiet';$('save-connection').after(cancel);
cancel.addEventListener('click',()=>{fillConfig(remoteState?.config);dirty=false;$('remote-notice').textContent='未保存の変更を取り消しました';toggleRemote();});
form.addEventListener('input',()=>{dirty=true;toggleRemote();});
function fillConfig(c) {
  form.reset();if(!c)return;
  const values={...c.ssh,...c.remote,...c.app,keepReleases:c.keepReleases};
  for(const [k,v] of Object.entries(values)) if(form.elements.namedItem(k)) form.elements.namedItem(k).value=v??'';
}
form.addEventListener('submit',async event=>{
  event.preventDefault();
  const f=Object.fromEntries(new FormData(form));
  const config={provider:'xserver',target:environment,ssh:{host:f.host.trim(),port:Number(f.port),user:f.user.trim(),identityFile:f.identityFile.trim()},remote:{appDir:f.appDir.trim(),docroot:f.docroot.trim(),phpBin:f.phpBin.trim(),dbHost:f.dbHost.trim()},app:{url:f.url.trim(),healthPath:f.healthPath.trim()},keepReleases:Number(f.keepReleases),...(remoteState?.config?.api?{api:remoteState.config.api}:{})};
  try {await post('remote-save',{id:selected,target:environment,config});dirty=false;remoteKey='';$('remote-notice').textContent='接続設定を保存しました';$('connection').open=false;await refreshRemote();}catch(e){$('remote-notice').textContent=e.message;}
});
function toggleRemote() {
  const locked=Boolean(siteState?.busy)||posting||ended;
  for(const b of document.querySelectorAll('[data-remote]'))b.disabled=locked||dirty||!remoteState?.config;
  $('save-connection').disabled=locked;
  $('execute-deploy').disabled=locked||dirty||!remoteState?.review||!$('consent').checked||remoteState.review.expiresAt<Date.now();
}
async function remoteAction(action,extra={}) {
  if(posting)return;posting=true;toggleRemote();$('remote-notice').textContent='';
  try{await post('remote-'+action,{id:selected,target:environment,...extra});$('review').hidden=true;$('consent').checked=false;await refreshRemote();$('operation').scrollIntoView({behavior:'smooth',block:'nearest'});}
  catch(e){$('remote-notice').textContent=e.message;}finally{posting=false;void refresh();}
}
for(const b of document.querySelectorAll('[data-remote]'))b.addEventListener('click',()=>remoteAction(b.dataset.remote));
$('consent').addEventListener('change',toggleRemote);
$('execute-deploy').addEventListener('click',()=>{const r=remoteState?.review;if(r)void remoteAction(r.mode,{token:r.token,confirmTarget:environment});});
function appendText(parent,tag,text,className) {const e=document.createElement(tag);e.textContent=text;if(className)e.className=className;parent.append(e);return e;}
let renderedReview='',renderedChecks='',renderedHistory='';
async function refreshRemote() {
  if(remoteLoading||environment==='local'||!selected)return;
  remoteLoading=true;const key=selected+':'+environment;
  try{
    const data=await post('remote-state',{id:selected,target:environment});
    if(key!==selected+':'+environment)return;
    remoteState=data;
    const label=environment==='production'?'本番':'ステージング';
    $('remote-title').textContent=label;
    $('remote-description').textContent=environment==='production'?'お客様が見るサイトです。公開先と変更内容を確かめてから公開します。':'本番に出す前に、表示や操作を確認する環境です。';
    if(remoteKey!==key){fillConfig(data.config);remoteKey=key;form.hidden=false;$('connection').open=!data.config;renderedReview='';renderedChecks='';renderedHistory='';}
    $('configured').textContent=data.config?'設定済み':'未設定';
    $('remote-url').hidden=!data.config;if(data.config)$('remote-url').href=data.config.app.url;
    const op=data.operation;$('operation').hidden=!op;
    if(op){$('operation').dataset.status=op.status;$('operation-title').textContent=({running:'処理中',success:'完了',failed:'要確認',unknown:'未確認'})[op.status];$('operation-message').textContent=op.message;
      $('operation-time').textContent=`開始 ${new Date(op.startedAt).toLocaleString('ja-JP')} · ${Math.floor(((op.finishedAt||Date.now())-op.startedAt)/1000)}秒${op.status==='running'&&Date.now()-op.updatedAt>90000?' · この工程に時間がかかっています。アプリは終了せずお待ちください':''}`;
      $('operation-log').textContent=op.events.map(e=>new Date(e.at).toLocaleTimeString('ja-JP')+' '+e.message).join('\n');
      const checkKey=JSON.stringify(op.checks);if(checkKey!==renderedChecks){renderedChecks=checkKey;$('checks').replaceChildren();for(const c of op.checks){const item=appendText($('checks'),'div','', 'check');appendText(item,'strong',`${c.status} · ${c.name}`);if(c.what)appendText(item,'p',c.what);if(c.why)appendText(item,'p',c.why);if(c.fix)appendText(item,'p',c.fix);}}
    }
    const r=data.review;$('review').hidden=!r;
    if(r&&renderedReview!==r.token){renderedReview=r.token;$('consent').checked=false;$('review-content').replaceChildren();const p=r.plan;
      $('review-title').textContent=r.mode==='rollback'?'旧版への切り戻しを確認':'公開前の確認';$('execute-deploy').textContent=r.mode==='rollback'?`${label}を旧版へ切り戻す`:`${label}へ公開する`;
      for(const [name,value] of [['公開先',`${label} · ${data.config.app.url}`],['接続先',`${data.config.ssh.user}@${data.config.ssh.host}:${data.config.ssh.port}`],['アプリ保存先',data.config.remote.appDir],['公開フォルダ',data.config.remote.docroot],['現在の版',r.currentRelease||'初回公開']]){appendText($('review-content'),'strong',name);appendText($('review-content'),'p',value);}
      if(r.mode==='deploy'){appendText($('review-content'),'p',`ファイル数の目安 ${p.artifact.fileCount.toLocaleString()} · サイズの目安 ${(p.artifact.sizeBytes/1024/1024).toFixed(1)} MB`);
        appendText($('review-content'),'strong','データベースの変更');appendText($('review-content'),'p',p.database.source);appendText($('review-content'),'pre',p.database.pendingMigrations.join('\n')||'保留中の変更は検出されていません');if(p.database.destructive)appendText($('review-content'),'p','削除を伴う変更があります。影響を確認してください','error');
        appendText($('review-content'),'p',p.backup.database);appendText($('review-content'),'p',p.backup.rollbackPoint);for(const line of [...p.docrootActions,...p.symlinkChanges,...p.cronChanges])appendText($('review-content'),'p',line);
      }else appendText($('review-content'),'p',`切り戻し先 ${r.rollbackRelease} · DBの復元は行いません。`);
      appendText($('review-content'),'p','公開後に応答を確認します。DB変更は自動的に元へ戻りません。');
    }
    const h=JSON.stringify(data.history);if(renderedHistory!==h){renderedHistory=h;$('history-list').replaceChildren();for(const e of data.history)appendText($('history-list'),'li',`${new Date(e.startedAt).toLocaleString('ja-JP')} · ${({check:'接続確認',build:'ビルド',prepare:'公開前確認','prepare-rollback':'切り戻し前確認',deploy:'公開',rollback:'切り戻し'})[e.action]||e.action} · ${({running:'処理中',success:'完了',failed:'要確認',unknown:'中断・未確認'})[e.status]}`);if(!data.history.length)appendText($('history-list'),'li','まだ操作履歴はありません');}
    toggleRemote();
  }catch(e){$('remote-notice').textContent=e.message;}finally{remoteLoading=false;}
}
