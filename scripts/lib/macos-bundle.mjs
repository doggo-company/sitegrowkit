import {existsSync,readlinkSync,readdirSync,realpathSync,symlinkSync,unlinkSync} from 'node:fs';
import {dirname,isAbsolute,join,relative,resolve,sep} from 'node:path';

// 旧GUI版が含む既知の開発環境へのリンクを、同梱ファイルへの相対リンクへ直す。
const bundledLinks={
  'Contents/Resources/app/node_modules/.bin/he':'../he/bin/he',
  'Contents/Resources/app/node_modules/.bin/agentcms':'../../dist/index.js',
  'Contents/Resources/app/node_modules/.bin/create-agent-cms':'../../dist/create-entry.js',
  'Contents/Resources/runtime/bin/npm':'../lib/node_modules/npm/bin/npm-cli.js',
  'Contents/Resources/runtime/bin/npx':'../lib/node_modules/npm/bin/npx-cli.js',
  'Contents/Resources/runtime/bin/corepack':'../lib/node_modules/corepack/dist/corepack.js',
};
export function normalizeBundleLinks(bundle){
  const root=realpathSync(bundle),changed=[];
  const walk=dir=>{
    for(const item of readdirSync(dir,{withFileTypes:true})){
      const file=join(dir,item.name),name=relative(root,file);
      if(item.isDirectory())walk(file);
      else if(item.isSymbolicLink()){
        const replacement=bundledLinks[name];
        if(replacement&&readlinkSync(file)!==replacement){
          if(!existsSync(resolve(dirname(file),replacement)))throw Error('同梱リンク先がありません: '+name);
          unlinkSync(file);symlinkSync(replacement,file);changed.push(name);
        }
        if(isAbsolute(readlinkSync(file)))throw Error('絶対リンクを配布できません: '+name);
        const target=realpathSync(file);
        if(!target.startsWith(root+sep))throw Error('アプリ外へのリンクを配布できません: '+name);
      }
    }
  };
  walk(root);return changed;
}
