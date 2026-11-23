// 公開先は固定の2環境だけ。設定・確認票・実行を結びつけ、任意コマンドを受け付けない。
import { readFile, writeFile, mkdir, rename, realpath, lstat, readdir, open, unlink } from 'node:fs/promises';
import { createHash, randomBytes } from 'node:crypto';
import { join, isAbsolute } from 'node:path';
export const targets = ['staging', 'production'];
const bad = (message) => { throw new Error(message); };
export function validateConfig(raw, target) {
  if (!targets.includes(target) || raw?.target !== target || raw?.provider !== 'xserver') bad('公開先と設定の環境が一致しません');
  const text = (v, regex, label) => { if (typeof v !== 'string' || v.length > 500 || !regex.test(v)) bad(`${label}を確認してください`); return v; };
  const host = v => text(v, /^[a-zA-Z0-9][a-zA-Z0-9.-]*$/, 'ホスト名');
  const path = (v, label) => { text(v, /^\/[a-zA-Z0-9_./-]+$/, label); if(v.includes('//') || v.split('/').some(x=>x==='.'||x==='..') || v==='/' || v.endsWith('/')) bad(`${label}は末尾に / のない絶対パスにしてください`); return v; };
  const ssh = { host: host(raw.ssh?.host), user: text(raw.ssh?.user,/^[a-zA-Z0-9_][a-zA-Z0-9_-]*$/,'SSHユーザー'), port: Number(raw.ssh?.port) };
  if (!Number.isInteger(ssh.port) || ssh.port<1 || ssh.port>65535) bad('SSHポートを確認してください');
  if (raw.ssh.identityFile) {
    const key=raw.ssh.identityFile;
    if(typeof key!=='string'||key.length>500||/[\x00-\x1f]/.test(key)||!(isAbsolute(key)||key.startsWith('~/'))) bad('秘密鍵は絶対パスまたは ~/ から指定してください');
    ssh.identityFile=key;
  }
  const remote={appDir:path(raw.remote?.appDir,'アプリ保存先'), docroot:path(raw.remote?.docroot,'公開フォルダ'), phpBin:raw.remote?.phpBin==='auto'?'auto':path(raw.remote?.phpBin,'PHPのパス')};
  if(remote.appDir.startsWith(remote.docroot) || remote.docroot.startsWith(remote.appDir)) bad('アプリ保存先と公開フォルダは別々の場所にしてください');
  if(raw.remote.dbHost) remote.dbHost=host(raw.remote.dbHost);
  let url; try { url=new URL(raw.app?.url); } catch { bad('公開URLを確認してください'); }
  if(url.protocol!=='https:' || url.username || url.password || url.search || url.hash || url.pathname!=='/' || url.port) bad('公開URLは https://ドメイン名 の形式にしてください');
  host(url.hostname);
  const healthPath=text(raw.app?.healthPath,/^\/[a-zA-Z0-9_/-]*$/,'応答確認のパス');
  if(healthPath.includes('//')) bad('応答確認のパスを確認してください');
  const keepReleases=Number(raw.keepReleases??3);
  if(!Number.isInteger(keepReleases)||keepReleases<2||keepReleases>20) bad('保存する旧版の数は2〜20にしてください');
  const config={provider:'xserver',target,ssh,remote,app:{url:url.origin,healthPath},keepReleases};
  // 既存のAPI補助設定だけを保持し、秘密値や未知のキーは引き継がない。
  if(raw.api) { config.api={}; for(const k of ['servername','dbSuffix','parentDomain']) if(raw.api[k]) config.api[k]=text(raw.api[k],/^[a-zA-Z0-9_][a-zA-Z0-9_.-]*$/,'API補助設定'); }
  return config;
}
async function directory(root) {
  const canonical=await realpath(root);
  for(const part of [join(canonical,'.agentcms'),join(canonical,'.agentcms','deploy')]) {
    try{if((await lstat(part)).isSymbolicLink())bad('設定フォルダにシンボリックリンクは使用できません');}catch(e){if(e.code!=='ENOENT')throw e;}
  }
  const dir=join(canonical,'.agentcms','deploy'); await mkdir(dir,{recursive:true,mode:0o700});
  if(await realpath(dir)!==dir) bad('設定フォルダにシンボリックリンクは使用できません');
  return dir;
}
export async function readConfig(root,target) {
  if(!targets.includes(target)) bad('環境を選択してください');
  const file=join(await directory(root),`${target}.json`);
  try { if((await lstat(file)).isSymbolicLink()) bad('設定ファイルにシンボリックリンクは使用できません'); return validateConfig(JSON.parse(await readFile(file,'utf8')),target); }
  catch(e) { if(e.code==='ENOENT') return null; if(e instanceof SyntaxError)bad('公開先の設定ファイルを読み込めません。JSON形式を確認してください');throw e; }
}
export async function saveConfig(root,target,raw) {
  const config=validateConfig(raw,target);
  const other=await readConfig(root,target==='staging'?'production':'staging');
  if(other && (other.app.url===config.app.url || (other.ssh.host===config.ssh.host && ['appDir','docroot'].some(k=>['appDir','docroot'].some(j=>other.remote[k].startsWith(config.remote[j])||config.remote[j].startsWith(other.remote[k])))))) bad('ステージングと本番には別のURL・配置先を指定してください');
  const file=join(await directory(root),`${target}.json`), tmp=`${file}.${randomBytes(8).toString('hex')}.tmp`;
  await writeFile(tmp,JSON.stringify(config,null,2)+'\n',{mode:0o600,flag:'wx'}); await rename(tmp,file); return config;
}
export const digest = value => createHash('sha256').update(JSON.stringify(value)).digest('hex');
// ビルドで変わる成果物を除き、確認後のソース変更を検知する。リンク経由の外部読取は拒否。
export async function sourceDigest(root) {
  const hash=createHash('sha256');
  const roots=['artisan','composer.json','composer.lock','package.json','package-lock.json','vite.config.js','vite.config.ts','app','bootstrap','config','content','core','database','modules','public','resources','routes'];
  async function walk(rel) {
    if(/(^|\/)node_modules(\/|$)|^public\/(build|storage|hot)(\/|$)|^bootstrap\/cache(\/|$)|(^|\/)\.git(\/|$)/.test(rel)) return;
    const file=join(root,rel); let s; try{s=await lstat(file);}catch(e){if(e.code==='ENOENT')return;throw e;}
    if(s.isSymbolicLink()) bad('公開対象にシンボリックリンクがあります。通常のファイルにして再確認してください');
    if(s.isDirectory()) for(const name of (await readdir(file)).sort()) await walk(`${rel}/${name}`);
    else if(s.isFile()) {hash.update(rel);hash.update('\0');hash.update(await readFile(file));hash.update('\0');}
  }
  for(const rel of roots) await walk(rel); return hash.digest('hex');
}
// 複数のアプリから同じサイトを公開しても、処理が重ならないようにする。
export async function acquireLock(root) {
  const file=join(await directory(root),'desktop-operation.lock');
  for(let attempt=0;attempt<2;attempt++) {
    try {const f=await open(file,'wx',0o600);await f.writeFile(String(process.pid));await f.close();return ()=>unlink(file).catch(()=>{});}
    catch(e) {if(e.code!=='EEXIST')throw e;const pid=Number(await readFile(file,'utf8'));if(!Number.isInteger(pid)||pid<=0)bad('公開処理のロックを確認できません');try{process.kill(pid,0);}catch(err){if(err.code==='ESRCH'){await unlink(file);continue;}}bad('別のSiteGrowKitでこのサイトを処理中です');}
  }
  bad('公開処理のロックを取得できません');
}
