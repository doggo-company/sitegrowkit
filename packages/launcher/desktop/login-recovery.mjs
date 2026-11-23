// 認証済みのローカル操作画面から、固定処理だけを実行する。
import { execFile, spawn } from 'node:child_process';
import { promisify } from 'node:util';
import { readFile, realpath, stat } from 'node:fs/promises';
import { join } from 'node:path';
const exec = promisify(execFile);
const errors = {
  local_only: 'この機能は、このパソコン内だけで動くローカルサイト専用です',
  reset_throttled: '再設定リンクは発行済みです。しばらく待ってからもう一度お試しください',
};
export function localRecoveryPort(config) {
  const ports = Object.values(config.services ?? {}).flatMap(s => s.ports ?? []);
  if (!ports.length || ports.some(p => !['127.0.0.1', '::1'].includes(p.host_ip)) ||
      Object.values(config.services ?? {}).some(s => s.network_mode === 'host')) throw new Error(errors.local_only);
  const port = Number(config.services?.web?.ports?.find(p => Number(p.target) === 80)?.published);
  if (!Number.isInteger(port) || port < 1024 || port > 65535) throw new Error(errors.local_only);
  return port;
}
async function initialCredential(root) {
  // 他の場所へのリンクや巨大なファイルは読まない。欠落時も再設定は使える。
  const file = join(root, '.agentcms/local/admin-credentials.json');
  try {
    if (await realpath(file) !== file || (await stat(file)).size > 4096) return null;
    const value = JSON.parse(await readFile(file, 'utf8'));
    return typeof value.email === 'string' && value.email.length <= 320 && typeof value.password === 'string' && value.password.length <= 1024 ? value : null;
  } catch { return null; }
}
async function invoke(root, payload) {
  const php = (await readFile(new URL('./login-recovery.php', import.meta.url), 'utf8')).replace(/^<\?php\s*/, '');
  return new Promise((resolve, reject) => {
    const child = spawn('docker', ['compose', 'exec', '-T', 'app', 'php', '-d', 'display_errors=0', '-r', php], { cwd: root, stdio: ['pipe', 'pipe', 'pipe'] });
    let output = '', failed = false;
    const timer = setTimeout(() => { failed = true; child.kill('SIGKILL'); }, 15000);
    child.stderr.resume(); child.stdin.on('error', () => {});
    child.stdout.on('data', data => { output += data; if (output.length > 128*1024) {failed = true;child.kill('SIGKILL');} });
    child.once('error', () => {clearTimeout(timer);reject(new Error('Dockerでローカルサイトを起動してからお試しください'));});
    child.once('close', code => {
      clearTimeout(timer);
      try {
        const value = JSON.parse(output);
        if (failed || code !== 0 || !value.ok) throw new Error(errors[value.error] ?? 'ログイン情報を確認できません。サイトの起動状態を確認してください');
        resolve(value.result);
      } catch (error) { reject(new Error(error instanceof SyntaxError ? 'ログイン情報を確認できません。サイトの起動状態を確認してください' : error.message)); }
    });
    // 秘密情報はプロセス引数ではなく標準入力で渡す。
    child.stdin.end(JSON.stringify(payload));
  });
}
export async function loginRecovery(root, action, data) {
  let config;
  try { config = JSON.parse((await exec('docker', ['compose', 'config', '--format', 'json'], { cwd: root, timeout: 8000, maxBuffer: 1024*1024 })).stdout); }
  catch { throw new Error('サイトの設定を確認できません。Docker Desktopを起動してください'); }
  const port = localRecoveryPort(config), base = `http://localhost:${port}`;
  if (action === 'info') {
    const initial = await initialCredential(root);
    const info = await invoke(root, { action, initial });
    return { accounts: info.accounts, initial: info.initialValid ? {email:initial.email,password:initial.password} : null, loginUrl: base+'/login' };
  }
  if (action !== 'reset' || typeof data.email !== 'string' || data.email.length > 320) throw new Error('管理者を選び直してください');
  const result = await invoke(root, { action, email: data.email });
  return { url: `${base}/reset-password/${encodeURIComponent(result.token)}?email=${encodeURIComponent(result.email)}`, expiresMinutes: result.expiresMinutes };
}
