// GUI 専用入口。本体の検証後、コマンド入力を介さずサイト一覧を開く。
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { execFile } from 'node:child_process';
import { startDesktop } from './server.mjs';
const appDir = join(dirname(fileURLToPath(import.meta.url)), '../app');
// Finder 起動時にも同梱 Node と Docker を見つける。ホストの Node は不要。
process.env.PATH = [dirname(process.execPath), ...(process.platform === 'darwin' ? ['/Applications/Docker.app/Contents/Resources/bin', '/usr/local/bin', '/usr/bin', '/bin', '/usr/sbin', '/sbin'] : [process.env.PATH || '/usr/bin:/bin'])].join(':');
const load = (file) => import(pathToFileURL(join(appDir, 'dist', file)).href);
try {
  const { verifyIntegrity } = await load('wizard/integrity.js');
  const check = verifyIntegrity();
  if (!check.checked || !check.ok) throw new Error('同梱ファイルを確認できません。ZIPを展開し直してください');
  const desktop = await startDesktop({ appDir });
  const { openExternal } = await load('wizard/open.js');
  const result = await openExternal(desktop.url);
  if (!result.opened) {
    // 認証 URL はログに書かず、本人の画面へだけ表示する。
    const message = `ブラウザのアドレス欄へ貼り付けてください。\n${desktop.url}`;
    if (process.platform === 'darwin') execFile('/usr/bin/osascript', ['-e', 'on run argv\ndisplay dialog (item 1 of argv) with title "SiteGrowKit" default answer (item 2 of argv) buttons {"OK"} default button "OK"\nend run', message, desktop.url]);
    else execFile('powershell.exe', ['-NoProfile', '-Command', 'Add-Type -AssemblyName System.Windows.Forms; [System.Windows.Forms.MessageBox]::Show($env:SGK_MESSAGE, "SiteGrowKit")'], { env: { ...process.env, SGK_MESSAGE: message, WSLENV: `${process.env.WSLENV ? process.env.WSLENV + ':' : ''}SGK_MESSAGE` } });
    console.log('URLの案内を表示しました');
  } else {
    console.log('ブラウザでSiteGrowKitを開きました');
  }
  for (const signal of ['SIGINT', 'SIGTERM']) process.once(signal, () => { void desktop.close(); });
  await desktop.closed;
} catch {
  console.error('SiteGrowKitを起動できませんでした。配布フォルダを展開し直してください');
  if (process.platform === 'darwin') execFile('/usr/bin/osascript', ['-e', 'display alert "SiteGrowKit" message "起動できませんでした。配布フォルダを展開し直してください" as critical']);
  process.exitCode = 1;
}
