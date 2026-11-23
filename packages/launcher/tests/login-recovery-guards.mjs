// 隔離QAのDockerで環境制限・対象権限・既存認証情報の保持を確認する。
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {spawnSync} from 'node:child_process';
import {resolve} from 'node:path';
const root=resolve(process.argv[2]);
assert(root.includes('/.codex/network-buyer-'),'隔離QAサイト以外では実施しません');
const php=(await readFile(new URL('../desktop/login-recovery.php',import.meta.url),'utf8')).replace(/^<\?php\s*/,'');
function run(code,payload={},env=[]){
 const r=spawnSync('docker',['compose','exec','-T',...env.flatMap(v=>['-e',v]),'app','php','-d','display_errors=0','-r',code],{cwd:root,input:JSON.stringify(payload),encoding:'utf8',timeout:30000});
 assert(!r.error,'Docker内の検証処理が完了する');
 try{return JSON.parse(r.stdout);}catch{throw new Error('検証応答を解析できません。応答の内容は非表示です');}
}
for(const env of [['APP_ENV=production'],['DB_HOST=remote.example.invalid'],['DB_CONNECTION=sqlite']]){
 const r=run(php,{action:'info'},env);assert(r.ok===false&&r.error==='local_only','ローカル以外を拒否する');
}
const bootstrap="require getcwd().'/vendor/autoload.php'; $app=require getcwd().'/bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();";
// 既存利用者には触れず、テスト専用アカウントを作成して最後に削除する。
const setup=bootstrap+`
 $ids=[];
 foreach ([['editor',true],['administrator',false],['administrator',true]] as $i=>$spec) {
  $email='sgk-recovery-qa-'.$i.'@example.invalid';
  if(App\\Models\\User::where('email',$email)->exists()) throw new RuntimeException('QA fixture already exists');
  $u=App\\Models\\User::create(['name'=>'Recovery QA','email'=>$email,'password'=>bin2hex(random_bytes(32))]);
  $u->forceFill(['role'=>$spec[0],'is_active'=>$spec[1]])->save();
  if($i===2){$u->forceFill(['two_factor_secret'=>encrypt('QA_ONLY'),'two_factor_recovery_codes'=>encrypt('[]'),'two_factor_confirmed_at'=>now()])->save();}
  $ids[]=$u->id;
 }
 echo json_encode(['ids'=>$ids]);`;
const ids=run(setup).ids;
const snapshot=bootstrap+`
 $u=App\\Models\\User::findOrFail(${Number(ids[2])});
 echo json_encode(['digest'=>hash('sha256',json_encode($u->only(['role','is_active','password','two_factor_secret','two_factor_recovery_codes','two_factor_confirmed_at'])))]);`;
try{
 const before=run(snapshot).digest;
 const info=run(php,{action:'info'});assert(info.ok);
 assert(!info.result.accounts.some(a=>a.id===ids[0]||a.id===ids[1]),'編集者・無効な管理者を表示しない');
 for(const i of [0,1]){const r=run(php,{action:'reset',email:'sgk-recovery-qa-'+i+'@example.invalid'});assert(r.ok===false,'対象外の権限を復旧しない');}
 const good=run(php,{action:'reset',email:'sgk-recovery-qa-2@example.invalid'});assert(good.ok&&typeof good.result.token==='string');
 assert(run(snapshot).digest===before,'リンク発行でパスワード・権限・2FAを変更しない');
 const throttle=run(php,{action:'reset',email:'sgk-recovery-qa-2@example.invalid'});assert(throttle.ok===false&&throttle.error==='reset_throttled');
 console.log(JSON.stringify({passed:true,tests:['runtime environment/database guards','inactive/editor excluded','administrator token issued','password/role/2FA retained','token throttling']}));
}finally{
 run(bootstrap+`foreach(App\\Models\\User::whereIn('id',${JSON.stringify(ids)})->get() as $u){Illuminate\\Support\\Facades\\Password::broker()->deleteToken($u);$u->forceDelete();} echo json_encode(['cleaned'=>true]);`);
}
