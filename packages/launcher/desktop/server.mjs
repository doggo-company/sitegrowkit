// ローカル専用のサイト一覧。任意コマンド・任意ファイル配信は公開しない。
import { createServer } from 'node:http';
import { randomBytes, createHash, timingSafeEqual } from 'node:crypto';
import { readFile, writeFile, mkdir, rename, readdir, realpath, stat } from 'node:fs/promises';
import { fork, execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { homedir } from 'node:os';
import { dirname, join, basename, isAbsolute } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { deploymentController } from './deploy-controller.mjs';
import { serviceContainers } from './container-state.mjs';
import { loginRecovery } from './login-recovery.mjs';
import { welcomeState, checkWelcomeEnvironment } from './welcome.mjs';
const here = dirname(fileURLToPath(import.meta.url));
const exec = promisify(execFile);
const steps = {
  'dev.started': '起動の準備', 'dev.docker.started': 'サイトの土台を起動中',
  'dev.docker.completed': 'サイトの土台を起動しました',
  'dev.dependencies.started': '必要なファイルを準備中',
  'dev.dependencies.completed': '必要なファイルを準備しました',
  'dev.database.started': 'データを準備中', 'dev.database.completed': 'データを準備しました',
  'dev.build.started': '画面を組み立て中', 'dev.build.completed': '画面を組み立てました',
  'dev.health.started': 'サイトの応答を確認中', 'dev.ready': 'サイトの起動を確認しました',
};
const equal = (a, b) => typeof a === 'string' && Buffer.byteLength(a) === Buffer.byteLength(b) && timingSafeEqual(Buffer.from(a), Buffer.from(b));
const idFor = (path) => createHash('sha256').update(path).digest('hex').slice(0, 24);
const json = (res, status, data) => { res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8' }); res.end(JSON.stringify(data)); };

export async function startDesktop({ appDir = join(here, '../app'), home = homedir(), pickFolder: suppliedPicker, openExternal: suppliedOpener, checkEnvironment = checkWelcomeEnvironment } = {}) {
  const load = (file) => import(pathToFileURL(join(appDir, 'dist', file)).href);
  const { pickFolder } = suppliedPicker ? { pickFolder: suppliedPicker } : await load('wizard/picker.js');
  const { openExternal } = suppliedOpener ? { openExternal: suppliedOpener } : await load('wizard/open.js');
  const { readSetupState } = await load('wizard/state.js');
  const { readEnvValue } = await load('credentials.js');
  const token = randomBytes(32).toString('hex');
  let exchanged = false, origin, cookieName, closing = false, busy = false, wizard, wizardBusy = false;
  let lastRequest = Date.now(), lastScan = 0, scan;
  const projects = new Map();
  const registry = join(home, '.agentcms', 'desktop-projects.json');
  const welcome = await welcomeState(home);
  let environmentCheck;
  const children = new Set();
  let persistence = Promise.resolve();

  async function validProject(path) {
    if (typeof path !== 'string' || !isAbsolute(path) || path.includes('\0')) return null;
    try {
      const canonical = await realpath(path);
      for (const file of ['artisan', 'docker-compose.yml', '.agentcms/architecture.md']) {
        if (!(await stat(join(canonical, file))).isFile()) return null;
      }
      return canonical;
    } catch { return null; }
  }
  async function add(path, persist = true) {
    const root = await validProject(path);
    if (!root) return false;
    const id = idFor(root);
    if (!projects.has(id)) projects.set(id, { id, root, name: basename(root), status: 'unknown', message: '状態を確認しています', checkedAt: null });
    if (persist) {
      // 同時の一覧更新も直列保存し、一時ファイルの競合を防ぐ。
      const paths = [...projects.values()].map(p => p.root);
      persistence = persistence.catch(() => {}).then(async () => {
        await mkdir(dirname(registry), { recursive: true, mode: 0o700 });
        const tmp = `${registry}.${process.pid}.tmp`;
        await writeFile(tmp, JSON.stringify(paths), { mode: 0o600 });
        await rename(tmp, registry);
      });
      await persistence;
    }
    return true;
  }
  try {
    const paths = JSON.parse(await readFile(registry, 'utf8'));
    if (Array.isArray(paths)) for (const path of paths.slice(0, 100)) await add(path, false);
  } catch { /* 初回は空の一覧から始める。 */ }
  async function discover() {
    const saved = readSetupState(home);
    if (saved?.stage === 'ready') await add(saved.projectPath, false);
    // 標準の保存先直下だけを見る。PC 全体を走査しない。
    const base = join(home, 'SiteGrowKit Projects');
    try { for (const entry of (await readdir(base, { withFileTypes: true })).slice(0, 100)) if (entry.isDirectory()) await add(join(base, entry.name), false); } catch { /* 未作成 */ }
  }
  await discover();
  async function check(p) {
    if (p.status === 'busy') return;
    try {
      if (await validProject(p.root) !== p.root) throw new Error('missing');
      const { stdout } = await exec('docker', ['compose', 'ps', '--all', '--format', 'json'], { cwd: p.root, timeout: 8000, maxBuffer: 1024 * 1024 });
      const containers = serviceContainers(stdout);
      // 対象 Compose の実際の状態を使う。無応答を「停止」と決めつけない。
      if (!containers.length || containers.every(c => ['exited', 'created', 'dead'].includes(c.State))) {
        p.status = 'stopped'; p.message = '停止中'; delete p.url;
      } else {
        const env = await readFile(join(p.root, '.env'), 'utf8');
        const port = Number(readEnvValue(env, 'APP_PORT') ?? '8180');
        if (!Number.isInteger(port) || port < 1024 || port > 65535) throw new Error('port');
        const url = `http://localhost:${port}/`;
        const response = await fetch(url, { signal: AbortSignal.timeout(3000), redirect: 'manual' });
        await response.body?.cancel();
        if (response.status !== 200 || containers.some(c => c.State !== 'running' || c.Health === 'unhealthy')) throw new Error('unhealthy');
        p.status = 'running'; p.message = '稼働中'; p.url = url;
      }
    } catch {
      p.status = 'unknown'; p.message = '状態を確認できません。Docker Desktopと保存先を確認してください'; delete p.url;
    }
    p.checkedAt = Date.now();
  }
  async function refresh() {
    if (scan) return scan;
    scan = (async () => {
      await discover();
      const queue = [...projects.values()];
      // サイト数が増えてもDocker照会を一斉に起動しない。
      await Promise.all(Array.from({ length: 4 }, async () => { while (queue.length) await check(queue.shift()); }));
      lastScan = Date.now();
    })().finally(() => { scan = null; });
    return scan;
  }
  const view = () => ({ projects: [...projects.values()].map(p => ({ ...p, error: p.error })), busy: busy || wizardBusy, checking: Boolean(scan), wizardOpen: Boolean(wizard), welcomeCompleted: welcome.completed });
  function worker(payload) {
    const child = fork(join(here, 'worker.mjs'), [], { execPath: process.execPath, stdio: ['ignore', 'pipe', 'pipe', 'ipc'], env: process.env });
    children.add(child);
    // 本体の生ログを永続化しない。認証情報や案件データの流出を避ける。
    child.stdout.resume(); child.stderr.resume();
    child.once('exit', () => children.delete(child));
    child.send({ appDir, home, ...payload });
    return child;
  }
  async function operate(p, action) {
    if (busy || wizard || wizardBusy || scan) return false;
    busy = true;
    try {
      if (await validProject(p.root) !== p.root) throw new Error('missing');
      p.status = 'busy'; p.message = action === 'dev' ? '起動の準備' : 'サイトを停止中'; p.error = null;
      p.startedAt = Date.now(); p.progressAt = Date.now();
      const child = worker({ action, root: p.root });
      child.on('message', event => {
        if (event.type === 'step' && steps[event.id]) { p.message = steps[event.id]; p.progressAt = Date.now(); }
        if (event.type === 'failed') p.error = String(event.code).replace(/[^a-z0-9_.-]/gi, '').slice(0, 100);
      });
      const finished = async (code) => {
        p.status = 'unknown';
        if (code !== 0) p.error ||= 'operation_failed';
        await check(p);
        busy = false;
      };
      child.once('error', () => { p.error = 'launcher_failed'; });
      child.once('close', code => { void finished(code); });
      return true;
    } catch { busy = false; p.status = 'unknown'; p.error = 'project_unavailable'; return false; }
  }
  async function createWizard(fromWelcome = false) {
    if (wizard) return wizard.url;
    if (busy || wizardBusy) throw new Error('busy');
    wizardBusy = true;
    return new Promise((resolve, reject) => {
      const child = worker({ action: 'wizard', fromWelcome });
      const timer = setTimeout(() => reject(new Error('wizard_timeout')), 15000);
      child.on('message', event => {
        if (event.type === 'wizard') {
          wizard = { child, url: event.url }; clearTimeout(timer); resolve(event.url);
        }
        if (event.type === 'wizard-state') {
          wizardBusy = Boolean(event.busy);
          if (event.phase === 'READY' && event.projectPath) void add(event.projectPath).then(() => { lastScan = 0; });
        }
      });
      child.once('error', () => { clearTimeout(timer); reject(new Error('wizard_failed')); });
      child.once('exit', () => { clearTimeout(timer); wizard = null; wizardBusy = false; reject(new Error('wizard_closed')); });
    });
  }
  const deployment = await deploymentController({home,worker,validProject,locked:()=>busy||wizardBusy||Boolean(wizard),setBusy:value=>{busy=value;}});
  const server = createServer((req, res) => { void handle(req, res).catch(() => { if (!res.headersSent) json(res, 500, { error: '操作できませんでした。もう一度お試しください' }); else res.end(); }); });
  async function handle(req, res) {
    res.setHeader('Cache-Control', 'no-store'); res.setHeader('Referrer-Policy', 'no-referrer');
    res.setHeader('X-Content-Type-Options', 'nosniff'); res.setHeader('X-Frame-Options', 'DENY');
    res.setHeader('Content-Security-Policy', "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'");
    if (req.headers.host !== new URL(origin).host) return json(res, 403, { error: 'アクセスできません' });
    const url = new URL(req.url, origin);
    if (req.method === 'GET' && url.pathname === '/' && !exchanged && equal(url.searchParams.get('t'), token)) {
      exchanged = true;
      res.writeHead(303, { Location: '/', 'Set-Cookie': `${cookieName}=${token}; HttpOnly; SameSite=Strict; Path=/` }); return res.end();
    }
    const cookies = Object.fromEntries((req.headers.cookie ?? '').split(';').map(s => { const i = s.indexOf('='); return [s.slice(0, i).trim(), s.slice(i + 1)]; }));
    if (!equal(cookies[cookieName], token)) return json(res, 403, { error: 'SiteGrowKitアプリから開き直してください' });
    lastRequest = Date.now();
    if (req.method === 'GET') {
      if (url.pathname === '/api/state') { if (Date.now() - lastScan > 10000 && !busy) void refresh(); return json(res, 200, view()); }
      const assets = { '/': ['index.html', 'text/html'], '/app.js': ['app.js', 'text/javascript'], '/welcome.js': ['welcome.js', 'text/javascript'], '/style.css': ['style.css', 'text/css'], '/logo.png': ['logo.png', 'image/png'], '/mark.png': ['mark.png', 'image/png'] };
      const asset = assets[url.pathname];
      if (!asset) return json(res, 404, { error: '見つかりません' });
      res.setHeader('Content-Type', `${asset[1]}; charset=utf-8`); return res.end(await readFile(join(here, 'assets', asset[0])));
    }
    if (req.method !== 'POST' || req.headers.origin !== origin || req.headers['x-sitegrowkit-desktop'] !== '1' || req.headers['content-type'] !== 'application/json') return json(res, 403, { error: 'アクセスできません' });
    let body = ''; for await (const part of req) { body += part; if (body.length > 4096) return json(res, 413, { error: '入力が長すぎます' }); }
    let data; try { data = JSON.parse(body); } catch { return json(res, 400, { error: '入力を確認してください' }); }
    // 初回案内も既存のローカル認証・Origin検証の内側で扱う。
    if (url.pathname === '/api/welcome-check') {
      if (!environmentCheck) environmentCheck = checkEnvironment(home).finally(() => {environmentCheck = null;});
      return json(res, 200, await environmentCheck);
    }
    if (url.pathname === '/api/welcome-complete') {
      try {await welcome.complete();return json(res, 200, {completed:true});}
      catch {return json(res, 500, {error:'初回案内の設定を保存できません。ホームフォルダへの書き込み権限を確認してください'});}
    }
    if (url.pathname === '/api/new') return json(res, 200, { url: await createWizard(data?.fromWelcome === true) });
    if (url.pathname === '/api/finish-setup') {
      if (wizardBusy) return json(res, 409, { error: 'サイトの準備中です。完了までお待ちください' });
      wizard?.child.kill('SIGTERM'); wizard = null;
      lastScan = 0; return json(res, 200, { ok: true });
    }
    if (url.pathname === '/api/add') {
      if (busy || wizardBusy) return json(res, 409, { error: '処理の完了をお待ちください' });
      const picked = await pickFolder();
      if (picked.canceled) return json(res, 200, { canceled: true });
      if (!picked.ok || !await add(picked.path)) return json(res, 400, { error: '作成済みのSiteGrowKitサイトのフォルダを選んでください' });
      lastScan = 0; return json(res, 200, { ok: true });
    }
    if (url.pathname === '/api/quit') {
      if (busy || wizardBusy) return json(res, 409, { error: '処理中は終了できません。完了までお待ちください' });
      json(res, 200, { ok: true }); setTimeout(() => { void close(); }, 50); return;
    }
    const p = projects.get(data?.id);
    if (!p) return json(res, 404, { error: 'サイトが見つかりません' });
    // 秘密情報は通常の状態配信に混ぜず、本人が操作したPOSTだけに返す。
    if (url.pathname === '/api/login-info' || url.pathname === '/api/login-reset') {
      if (data.target !== 'local') return json(res, 403, { error: 'ローカルサイト専用の操作です' });
      if (busy || wizardBusy || wizard || scan || p.status !== 'running') return json(res, 409, { error: 'サイトが稼働中になり、他の処理が終わってからお試しください' });
      if (url.pathname === '/api/login-reset' && data.confirmSite !== p.name) return json(res, 400, { error: '対象サイトを確認してください' });
      busy = true;
      try {
        if (await validProject(p.root) !== p.root) throw new Error('保存先を確認してください');
        const result = await loginRecovery(p.root, url.pathname.endsWith('-info') ? 'info' : 'reset', data);
        return json(res, 200, result);
      } catch (error) { return json(res, 409, { error: error.message }); }
      finally { busy = false; }
    }
    if (url.pathname.startsWith('/api/remote-')) {
      try {const result=await deployment.handle(p,url.pathname.slice(5),data);return json(res,result.accepted?202:200,result);}
      catch(error){return json(res,409,{error:error.message});}
    }
    if (url.pathname === '/api/start' || url.pathname === '/api/stop') {
      const ok = await operate(p, url.pathname === '/api/start' ? 'dev' : 'stop');
      return json(res, ok ? 202 : 409, ok ? { ok: true } : { error: '状態確認または他の処理が終わってからお試しください' });
    }
    if (url.pathname === '/api/folder') {
      if (await validProject(p.root) !== p.root) return json(res, 404, { error: '保存先が見つかりません' });
      return json(res, 200, { opened: (await openExternal(p.root)).opened });
    }
    return json(res, 404, { error: '見つかりません' });
  }
  server.requestTimeout = 15000; server.headersTimeout = 10000;
  await new Promise((resolve, reject) => { server.once('error', reject); server.listen(0, '127.0.0.1', resolve); });
  origin = `http://127.0.0.1:${server.address().port}`;
  cookieName = `sitegrowkit_desktop_${server.address().port}`;
  const closed = new Promise(resolve => server.once('close', resolve));
  const close = async () => {
    if (closing || busy || wizardBusy) return;
    closing = true; clearInterval(idle);
    for (const child of children) child.kill('SIGTERM');
    server.close(); server.closeIdleConnections(); await closed;
  };
  const idle = setInterval(() => { if (Date.now() - lastRequest > 30 * 60000) void close(); }, 15000);
  idle.unref();
  void refresh();
  return { url: `${origin}/?t=${token}`, origin, close, closed, refresh };
}
