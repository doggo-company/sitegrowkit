import { existsSync } from "node:fs";
import { platform } from "node:os";
import { isAbsolute } from "node:path";
import { run } from "../run.js";
import { isWsl } from "../runtime-user.js";
/**
 * OS のブラウザ / ファイラで開く（Phase 8.5-B / 8.5-C.3）。
 *
 * 開ける対象は **ローカルの http URL** と **実在する絶対パス** に限定する。
 * 実行は shell を介さず「実行ファイル + 引数配列」のみ。
 *
 * WSL2 では `wslview`（wslu パッケージ）が入っていない環境があるため、
 * **単一 opener に依存せず候補を順に試す**。すべて失敗しても
 * **例外を外へ出さない**（Wizard server を巻き添えで落とさないため — F-W1）。
 */
/** localhost 宛の http URL のみ許可（任意 URL のオープナーにしない） */
export function isLocalHttpUrl(value) {
    let url;
    try {
        url = new URL(value);
    }
    catch {
        return false;
    }
    if (url.protocol !== "http:")
        return false;
    return (url.hostname === "localhost" ||
        url.hostname === "127.0.0.1" ||
        url.hostname === "[::1]");
}
export function isOpenablePath(value) {
    return isAbsolute(value) && !value.includes("\0") && existsSync(value);
}
/** URL を環境変数で渡すときの名前（コマンド文字列へ連結しないため） */
export const OPEN_TARGET_ENV = "AGENTCMS_OPEN_TARGET";
/**
 * ブラウザ / ファイラの候補（先頭から順に試す）。
 *
 * WSL2:
 *  1. `wslview` — あれば最も素直（既定ブラウザで開く）
 *  2. `powershell.exe Start-Process` — URL は **環境変数**で渡す（文字列連結しない）
 *  3. `explorer.exe` — 最後の手段。成功しても非 0 を返すため終了コードで判定できない
 *
 * **explorer.exe を先頭に置かない理由（Phase 8.5-D.8 / F-D6・実測）**:
 * `explorer.exe` は引数に `?` を含む URL を URL として解釈できず、
 * 既定フォルダの Explorer ウィンドウを開いてしまう。
 * Setup Wizard の URL は一度きりの token を `?t=` で渡すため必ずこれに該当する。
 * さらに `ignoreExitCode` のため **失敗しても成功として報告**され、
 * 利用者には「ブラウザで開きました」と出るのに何も開かない状態になっていた。
 */
export function openerCandidates() {
    if (platform() === "darwin")
        return [{ cmd: "open", args: [] }];
    if (isWsl()) {
        return [
            { cmd: "wslview", args: [] },
            {
                cmd: "powershell.exe",
                args: [
                    "-NoProfile",
                    "-NonInteractive",
                    "-Command",
                    `Start-Process $env:${OPEN_TARGET_ENV}`,
                ],
                targetVia: "env",
            },
            { cmd: "explorer.exe", args: [], ignoreExitCode: true },
        ];
    }
    if (platform() === "linux")
        return [{ cmd: "xdg-open", args: [] }];
    if (platform() === "win32") {
        return [
            { cmd: "explorer.exe", args: [], ignoreExitCode: true },
            {
                cmd: "powershell.exe",
                args: [
                    "-NoProfile",
                    "-NonInteractive",
                    "-Command",
                    `Start-Process $env:${OPEN_TARGET_ENV}`,
                ],
                targetVia: "env",
            },
        ];
    }
    return [];
}
/**
 * OS の既定アプリで開く。
 * **例外を投げない** — 開けなかったことは戻り値で表す（F-W1）。
 */
export async function openExternal(target) {
    if (!isLocalHttpUrl(target) && !isOpenablePath(target)) {
        return { opened: false, attempts: [], rejected: true };
    }
    const attempts = [];
    for (const opener of openerCandidates()) {
        // 未導入（ENOENT）かどうかは spawn の結果で判断する。
        // 事前チェックのために別コマンドを実行すると副作用の恐れがあるため行わない
        const outcome = await attempt(opener, target);
        attempts.push({ cmd: opener.cmd, outcome });
        if (outcome === "opened")
            return { opened: true, attempts };
    }
    return { opened: false, attempts };
}
/**
 * WSL の環境変数は **既定では Windows プロセスへ渡らない**。
 * 渡す変数名を `WSLENV` に列挙して初めて引き継がれる（実測で確定 — F-D6）。
 * 既存の `WSLENV` は壊さずに追記する。
 */
function envForTarget(target) {
    const existing = process.env.WSLENV;
    const names = existing ? `${existing}:${OPEN_TARGET_ENV}` : OPEN_TARGET_ENV;
    return { [OPEN_TARGET_ENV]: target, WSLENV: names };
}
async function attempt(opener, target) {
    const viaEnv = opener.targetVia === "env";
    try {
        const result = await run(opener.cmd, viaEnv ? opener.args : [...opener.args, target], viaEnv ? { env: envForTarget(target) } : {});
        if (opener.ignoreExitCode)
            return "opened";
        return result.code === 0 ? "opened" : "failed";
    }
    catch (error) {
        // spawn ENOENT（未導入）とその他の失敗を区別して返す。**外へは投げない**
        const code = error.code;
        return code === "ENOENT" ? "not-found" : "failed";
    }
}
