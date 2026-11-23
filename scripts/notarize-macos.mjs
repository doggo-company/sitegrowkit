#!/usr/bin/env node
// 検証済み Desktop 2.1 を別名で署名・公証する。認証情報は Keychain から取得する。
// prepare → submit-app → staple-app → package → submit-dmg → finish の順に実行する。
import {execFileSync} from 'node:child_process';
import {createHash} from 'node:crypto';
import {existsSync,readFileSync,writeFileSync,mkdirSync,cpSync,readdirSync,statSync} from 'node:fs';
import {dirname,join,resolve} from 'node:path';
import {fileURLToPath} from 'node:url';
import {normalizeBundleLinks} from './lib/macos-bundle.mjs';
const root=resolve(dirname(fileURLToPath(import.meta.url)),'..');
const release=join(root,'release');
const stem='sitegrowkit-desktop-2.6-0.9.4-rc5-macos-arm64';
const stage=join(release,stem), app=join(stage,'SiteGrowKit.app');
const resources=join(app,'Contents/Resources');
const stateDir=join(release,stem+'-notary');
// 署名の名前と notarytool の keychain profile は、署名する人の手元の値を環境変数で渡す（リポには置かない）
const identity=process.env.SGK_SIGN_IDENTITY;
const profile=process.env.SGK_NOTARY_PROFILE;
if(!identity||!profile){console.error('SGK_SIGN_IDENTITY（例: Developer ID Application: <名前> (<Team ID>)）と SGK_NOTARY_PROFILE を指定してください');process.exit(2);}
const run=(cmd,args)=>execFileSync(cmd,args,{cwd:root,encoding:'utf8',maxBuffer:4*1024*1024});
const hash=file=>createHash('sha256').update(readFileSync(file)).digest('hex');
const save=(name,value)=>writeFileSync(join(stateDir,name),JSON.stringify(value,null,2)+'\n');
const read=name=>JSON.parse(readFileSync(join(stateDir,name),'utf8'));
function verifyIntegrity(){
 const manifest=JSON.parse(readFileSync(join(resources,'integrity.json'),'utf8'));
 for(const file of manifest.files)if(hash(join(resources,file.path))!==file.sha256)throw Error('同梱ファイル不一致: '+file.path);
 return manifest;
}
function submit(file,name){
 // 再試行で重複申請しない。既存IDがあるときは info / log で結果を取得する。
 if(existsSync(join(stateDir,name)))throw Error('申請記録が既にあります: '+name);
 const result=JSON.parse(run('xcrun',['notarytool','submit',file,'--keychain-profile',profile,'--output-format','json']));
 save(name,result);console.log(JSON.stringify(result));
}
function accepted(name){
 const {id}=read(name);
 const result=JSON.parse(run('xcrun',['notarytool','info',id,'--keychain-profile',profile,'--output-format','json']));
 save(name.replace('.json','-status.json'),result);
 if(result.status!=='Accepted')throw Error('公証は未完了: '+result.status+' / '+id);
 const log=JSON.parse(run('xcrun',['notarytool','log',id,'--keychain-profile',profile]));
 save(name.replace('.json','-log.json'),log);
 if(log.issues?.length)throw Error('公証ログの指摘を確認してください');
 return result;
}
const phase=process.argv[2];
if(phase==='prepare'){
 if(existsSync(stage)||existsSync(stateDir))throw Error('作業先が既にあります。既存の配布物を上書きしません');
 const source='sitegrowkit-desktop-2.1-0.9.4-rc5-macos-arm64.zip';
 const expected=JSON.parse(readFileSync(join(root,'docs/release-20260909/desktop21-delivery.json'),'utf8')).files.find(f=>f.file===source);
 if(!expected||hash(join(release,source))!==expected.sha256)throw Error('元の検証済みZIPが一致しません');
 mkdirSync(stage);mkdirSync(stateDir);
 run('ditto',['-x','-k',join(release,source),stage]);
 const manifest=verifyIntegrity();
 // ローカル接続限定の修正だけを同梱テンプレートに適用し、追加の整合性対象にする。
 const composePath='app/template/docker-compose.yml';
 cpSync(join(root,'template/docker-compose.yml'),join(resources,composePath));
 manifest.files.push({path:composePath,sha256:hash(join(resources,composePath)),size:statSync(join(resources,composePath)).size});
 const repairedLinks=normalizeBundleLinks(app);
 // 署名対象のGUIは現在のソースから同梱し、CMS本体は検証済みZIPのまま保持する。
 cpSync(join(root,'packages/launcher/desktop'),join(resources,'desktop'),{recursive:true,verbatimSymlinks:true});
 // スクリプト形式の入口を、署名と hardened runtime に対応するネイティブ実行ファイルへ置換する。
 run('xcrun',['swiftc','-O','-target','arm64-apple-macosx13.0',join(root,'packages/launcher/macos/Launcher.swift'),'-o',join(app,'Contents/MacOS/agent-cms-kit')]);
 // Finder と Dock のアイコンを署名前に組み込む。
 cpSync(join(root,'packages/launcher/macos/SiteGrowKit.icns'),join(resources,'SiteGrowKit.icns'));
 const plist=join(app,'Contents/Info.plist');
 run('/usr/libexec/PlistBuddy',['-c','Add :CFBundleIconFile string SiteGrowKit.icns',plist]);
 run('/usr/libexec/PlistBuddy',['-c','Set :CFBundleVersion 2.6.0',plist]);
 run('/usr/libexec/PlistBuddy',['-c','Set :CFBundleShortVersionString 2.6.0',plist]);
 const index=join(resources,'desktop/assets/index.html');
 writeFileSync(index,readFileSync(index,'utf8').replace('Desktop 2.1','Desktop 2.6'));
 const node=join(resources,'runtime/bin/node');
 run('codesign',['--force','--options','runtime','--timestamp','--entitlements',join(root,'packages/launcher/macos/node-entitlements.plist'),'--sign',identity,node]);
 // 署名で変化する Node とGUIを再計算し、CMS 本体のハッシュは保持する。
 const collect=dir=>readdirSync(dir,{withFileTypes:true}).flatMap(item=>item.isDirectory()?collect(join(dir,item.name)):[join(dir,item.name)]);
 for(const file of collect(join(resources,'desktop'))){
  const path=file.slice(resources.length+1);
  if(!manifest.files.some(entry=>entry.path===path))manifest.files.push({path});
 }
 for(const file of manifest.files){
  if(file.path==='runtime/bin/node'||file.path.startsWith('desktop/')){
   file.sha256=hash(join(resources,file.path));file.size=statSync(join(resources,file.path)).size;
  }
 }
 writeFileSync(join(resources,'integrity.json'),JSON.stringify(manifest,null,2)+'\n');
 verifyIntegrity();
 run('codesign',['--force','--options','runtime','--timestamp','--sign',identity,app]);
 console.log(run('codesign',['--verify','--deep','--strict','--verbose=2',app]));
 console.log(run(node,['-e','const assert=require("node:assert/strict");let n=0;for(let i=0;i<100000;i++)n+=i;assert.equal(n,4999950000);assert.equal(new Function("return 42")(),42);console.log("Signed Node JIT OK")']));
 run('ditto',['-c','-k','--keepParent',app,join(stateDir,'app-submit.zip')]);
 save('source.json',{file:source,sha256:expected.sha256,identity,desktopVersion:'2.6',coreVersion:'0.9.4-rc5',integrityFiles:manifest.files.length,repairedLinks});
 console.log('署名済みアプリを準備しました');
}else if(phase==='submit-app')submit(join(stateDir,'app-submit.zip'),'app-submission.json');
else if(phase==='staple-app'){
 accepted('app-submission.json');
 console.log(run('xcrun',['stapler','staple',app]));
 console.log(run('xcrun',['stapler','validate',app]));
 console.log(run('spctl',['--assess','--type','execute','--verbose=2',app]));
}else if(phase==='package'){
 accepted('app-submission.json');verifyIntegrity();
 console.log(run('xcrun',['stapler','validate',app]));
 const dmg=join(release,stem+'.dmg');
 if(existsSync(dmg))throw Error('DMG が既にあります');
 // 配布ルートの案内は、確認済みの最新ガイドを呼出元が配置してから実行する。
 const imageRoot=join(stateDir,'image');mkdirSync(imageRoot);
 run('ditto',[app,join(imageRoot,'SiteGrowKit.app')]);
 cpSync(join(stage,'README_FIRST.html'),join(imageRoot,'使い方ガイド.html'));
 run('ln',['-s','/Applications',join(imageRoot,'Applications')]);
 // マウントしたディスクにも同じブランドアイコンを表示する。
 cpSync(join(resources,'SiteGrowKit.icns'),join(imageRoot,'.VolumeIcon.icns'));
 run('xcrun',['SetFile','-a','C',imageRoot]);
 run('hdiutil',['create','-volname','SiteGrowKit','-srcfolder',imageRoot,'-format','UDZO',dmg]);
 run('codesign',['--force','--timestamp','--sign',identity,dmg]);
 console.log('署名済み DMG を作成しました');
}else if(phase==='submit-dmg')submit(join(release,stem+'.dmg'),'dmg-submission.json');
else if(phase==='finish'){
 const application=accepted('app-submission.json'),diskImage=accepted('dmg-submission.json');
 const dmg=join(release,stem+'.dmg'),zip=join(release,stem+'.zip');
 if(existsSync(zip))throw Error('ZIP が既にあります');
 console.log(run('xcrun',['stapler','staple',dmg]));
 console.log(run('xcrun',['stapler','validate',dmg]));
 console.log(run('spctl',['--assess','--type','open','--context','context:primary-signature','--verbose=2',dmg]));
 console.log(run('codesign',['--verify','--deep','--strict',app]));verifyIntegrity();
 run('ditto',['-c','-k','--keepParent',stage,zip]);
 const files=[zip,dmg].map(file=>({file:file.split('/').pop(),sha256:hash(file),bytes:statSync(file).size}));
 for(const file of files)writeFileSync(join(release,file.file+'.sha256'),file.sha256+'  '+file.file+'\n');
 const report={...read('source.json'),application,diskImage,files,stapler:'validated app and DMG',gatekeeper:'accepted app and DMG'};
 writeFileSync(join(root,'docs/release-20260909/desktop26-notarization.json'),JSON.stringify(report,null,2)+'\n');
 console.log(JSON.stringify(report));
}else throw Error('phase: prepare | submit-app | staple-app | package | submit-dmg | finish');
