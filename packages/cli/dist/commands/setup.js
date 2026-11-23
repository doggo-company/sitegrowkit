import { fail, heading, info, ok, warn } from "../ui.js";
import { integrityFailureMessage, verifyIntegrity, } from "../wizard/integrity.js";
import { openExternal } from "../wizard/open.js";
import { startWizardServer } from "../wizard/server.js";
/**
 * `agentcms setup` — ブラウザで動く Setup Wizard を起動する（Phase 8.5-B）。
 *
 * ターミナルは「起動するだけ」の役割に留める。以降の操作はすべてブラウザ側で行う。
 * URL には一度きりの token が含まれるため、自動で開けたときは端末へ表示しない。
 */
export async function setup() {
    heading("SiteGrowKit セットアップ");
    // D7: 配布物の自己検査。改変・欠損があれば起動しない
    // （利用者に shasum 等を打たせないため自動で行う）
    const integrity = verifyIntegrity();
    if (integrity.checked && !integrity.ok) {
        const { title, message } = integrityFailureMessage();
        fail({
            code: "integrity.failed",
            what: title,
            why: message,
            fix: "配布物を入手元から取得し直し、展開し直してください",
            title,
            message,
        });
        // 技術的な詳細は「詳細」側の情報として最後に出す
        for (const problem of integrity.problems.slice(0, 10)) {
            info(`  ${problem.kind}: ${problem.path}`);
        }
        return 1;
    }
    if (integrity.checked)
        ok("ファイルを確認しました");
    const server = await startWizardServer();
    // ブラウザ起動の失敗は Wizard server を巻き添えにしない（F-W1）。
    // openExternal は例外を投げず、結果を戻り値で返す
    const result = await openExternal(server.url);
    if (result.opened) {
        ok("ブラウザでセットアップ画面を開きました");
        info("この画面（ターミナル）はそのまま閉じずにお待ちください");
    }
    else {
        warn("セットアップ画面を自動で開けませんでした。");
        info("次の URL をブラウザのアドレス欄に貼り付けて開いてください:");
        // 自動で開けなかった場合のみ、利用者が到達する唯一の手段として表示する
        console.log(`\n  ${server.url}\n`);
        // どの opener を試したかは調査用に残す（Primary UX には出さない）
        const tried = result.attempts
            .map((a) => `${a.cmd}: ${a.outcome}`)
            .join(" / ");
        if (tried)
            info(`（試したブラウザ起動方法 — ${tried}）`);
    }
    const shutdown = () => {
        void server.close();
    };
    process.once("SIGINT", shutdown);
    process.once("SIGTERM", shutdown);
    await server.closed;
    return 0;
}
