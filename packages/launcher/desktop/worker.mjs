// 検証済み本体を別プロセスで使い、準備中も操作画面の応答を保つ。
import { pathToFileURL } from 'node:url';
import { join } from 'node:path';
process.once('message', async ({ appDir, action, root, home, target, expected, fromWelcome }) => {
  const load = (file) => import(pathToFileURL(join(appDir, 'dist', file)).href);
  try {
    if (action.startsWith('remote:')) {
      const { runDeployment } = await import('./deploy-worker.mjs');
      // 大きい確認票もIPCの送信完了を待つ。終了直前の結果を取りこぼさない。
      let sending=Promise.resolve();
      const send=event=>{sending=sending.then(()=>new Promise(resolve=>{if(!process.connected)return resolve();process.send(event,()=>resolve());}));};
      try {
        const code = await runDeployment({ load, root, action: action.slice(7), target, expected }, send);
        await sending;
        process.exit(code);
      } catch(error) {
        const {sanitizeLogLine}=await load('wizard/sanitize.js');
        send({type:'deploy-progress',message:sanitizeLogLine(error.message).slice(0,3000)});
        await sending;
        process.exit(1);
      }
    }
    if (action === 'wizard') {
      const { startWizardServer } = await load('wizard/server.js');
      const server = await startWizardServer({ home });
      // Welcomeで説明済みの入口は繰り返さず、既存の詳細診断を経てサイト設定へ進む。
      // 作成途中のサイトがある場合は、その再開画面を維持する。
      if (fromWelcome && server.runner.snapshot().phase === 'WELCOME') {
        void server.runner.runEnvironmentCheck().then(() => {
          const s = server.runner.snapshot();
          if (s.phase === 'ENVIRONMENT' && s.environmentChecked && s.environmentOk && !s.failure) server.runner.enterProject();
        }).catch(() => { /* 診断画面で再確認できる。 */ });
      }
      process.send({ type: 'wizard', url: server.url });
      const update = () => {
        const s = server.runner.snapshot();
        process.send({ type: 'wizard-state', busy: s.busy, phase: s.phase,
          projectPath: s.ready?.projectPath });
      };
      server.runner.subscribe(update);
      update();
      await server.closed;
      process.exit(0);
    }
    if (!['dev', 'stop'].includes(action)) throw new Error('invalid_action');
    const engine = await load('commands/dev.js');
    const code = await engine[action]({ root, onEvent(event) {
      // 生ログや認証情報は親へ転送しない。進行段階のみを表示する。
      if (event.type === 'step') process.send({ type: 'step', id: event.id });
    } });
    process.exit(code);
  } catch (error) {
    process.send({ type: 'failed', code: error.failure?.code ?? 'operation_failed' });
    process.exit(1);
  }
});
