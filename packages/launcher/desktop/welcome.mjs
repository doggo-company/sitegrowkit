// 初回案内の状態はブラウザではなく利用者のホームへ保存する。
import {readFile,writeFile,mkdir,rename,statfs,mkdtemp,rm} from 'node:fs/promises';
import {join,dirname} from 'node:path';
import {randomUUID} from 'node:crypto';
import {execFile} from 'node:child_process';
import {promisify} from 'node:util';
const exec=promisify(execFile);
export async function welcomeState(home) {
 const file=join(home,'.agentcms','desktop-welcome.json');
 let completed=false;
 try {const value=JSON.parse(await readFile(file,'utf8'));completed=value.version===1&&typeof value.completedAt==='string';} catch { /* 初回・壊れた記録は案内を表示する。 */ }
 return {get completed(){return completed;},async complete(){
  await mkdir(dirname(file),{recursive:true,mode:0o700});
  const tmp=file+'.'+randomUUID()+'.tmp';
  try {await writeFile(tmp,JSON.stringify({version:1,completedAt:new Date().toISOString()})+'\n',{mode:0o600});await rename(tmp,file);completed=true;}
  finally {await rm(tmp,{force:true}).catch(()=>{});}
 }};
}
export async function checkWelcomeEnvironment(home) {
 // 固定コマンドだけを実行し、診断中もHTTPサーバーを止めない。生の出力は返さない。
 const results=await Promise.allSettled([
  exec('docker',['info','--format','{{.ServerVersion}}'],{timeout:10000,maxBuffer:65536}),
  exec('docker',['compose','version','--short'],{timeout:10000,maxBuffer:65536}),
  (async()=>{const info=await statfs(home);const temp=await mkdtemp(join(home,'.sitegrowkit-check-'));await rm(temp,{recursive:true});return Number(info.bavail)*Number(info.bsize);})(),
 ]);
 const [docker,compose,storage]=results;
 const checks=[
  {id:'docker',title:'サイトを動かす準備',status:docker.status==='fulfilled'?'pass':'fail',message:docker.status==='fulfilled'?'Dockerに接続できました':'Docker Desktopを起動してください。未導入の場合は、下の案内からインストールできます。WindowsではWSL Integrationも有効にしてください。'},
  {id:'compose',title:'サイトの起動機能',status:compose.status==='fulfilled'?'pass':'fail',message:compose.status==='fulfilled'?'必要な起動機能がそろっています':'Docker Desktopを更新し、WindowsではUbuntuとのWSL Integrationを有効にしてください。'},
  {id:'storage',title:'保存先と空き容量',status:storage.status==='fulfilled'&&storage.value>=10*1024**3?'pass':'fail',message:storage.status==='fulfilled'?(storage.value>=10*1024**3?'保存先に書き込めます。空き容量は'+(storage.value/1024**3).toFixed(1)+' GBです':'ホームの保存先に10 GB以上の空き容量を確保してください'):'ホームフォルダに書き込めることを確認してください'},
 ];
 return {checks,ready:checks.every(c=>c.status==='pass')};
}
