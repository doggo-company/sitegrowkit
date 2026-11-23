# 検証済みWindows配布物を元に、ローカル限定テンプレートと現行GUIを別名で配布する。
from pathlib import Path
import hashlib,json,zipfile
root=Path(__file__).resolve().parent.parent
source=root/'release/sitegrowkit-desktop-2.1-0.9.4-rc5-windows-x64.zip'
target=root/'release/sitegrowkit-desktop-2.6-0.9.4-rc5-windows-x64.zip'
assert not target.exists(), '既存配布物は上書きしません'
expected=json.loads((root/'docs/release-20260909/desktop21-delivery.json').read_text())
sha=next(f['sha256'] for f in expected['files'] if f['file']==source.name)
assert hashlib.sha256(source.read_bytes()).hexdigest()==sha
with zipfile.ZipFile(source) as z:
 manifest=json.loads(z.read('integrity.json'))
 for entry in manifest['files']:assert hashlib.sha256(z.read(entry['path'])).hexdigest()==entry['sha256']
 changes={'app/template/docker-compose.yml':(root/'template/docker-compose.yml').read_bytes(),'README_FIRST.md':(root/'packages/launcher/README_FIRST.md').read_bytes(),'README_FIRST.html':(root/'release/README_FIRST-0.9.4-rc5.html').read_bytes()}
 for p in (root/'packages/launcher/desktop').rglob('*'):
  if p.is_file():changes['desktop/'+p.relative_to(root/'packages/launcher/desktop').as_posix()]=p.read_bytes()
 changes['desktop/assets/index.html']=changes['desktop/assets/index.html'].replace(b'Desktop 2.1',b'Desktop 2.6')
 for path,data in changes.items():
  if not (path.startswith('desktop/') or path=='app/template/docker-compose.yml'):continue
  entry=next((e for e in manifest['files'] if e['path']==path),None)
  if entry is None:entry={'path':path};manifest['files'].append(entry)
  entry.update(sha256=hashlib.sha256(data).hexdigest(),size=len(data))
 changes['integrity.json']=(json.dumps(manifest,indent=2)+'\n').encode()
 with zipfile.ZipFile(target,'w',zipfile.ZIP_DEFLATED) as out:
  old=set(z.namelist())
  for info in z.infolist():out.writestr(info,changes.get(info.filename,z.read(info.filename)))
  for path,data in changes.items():
   if path not in old:out.writestr(path,data)
with zipfile.ZipFile(target) as z:
 assert z.testzip() is None
 for e in manifest['files']:assert hashlib.sha256(z.read(e['path'])).hexdigest()==e['sha256']
summary={'file':target.name,'sha256':hashlib.sha256(target.read_bytes()).hexdigest(),'integrityFiles':len(manifest['files']),'windowsExecution':'not rerun; launcher executable unchanged','changes':list(changes)}
(root/'docs/release-20260909/desktop26-windows-network-fix.json').write_text(json.dumps(summary,indent=2)+'\n')
Path(str(target)+'.sha256').write_text(summary['sha256']+'  '+target.name+'\n')
print(json.dumps(summary))
