import { readFileSync } from "node:fs";
import { join } from "node:path";
import { CREDENTIALS_REL } from "../credentials.js";
import { run } from "../run.js";
import { composeUserArgs } from "../runtime-user.js";
/** project の `.agentcms/local/admin-credentials.json` を読む。形が違えば null */
export function readAdminCredential(projectPath) {
    if (projectPath === "")
        return null;
    try {
        const parsed = JSON.parse(readFileSync(join(projectPath, CREDENTIALS_REL), "utf8"));
        if (typeof parsed !== "object" || parsed === null)
            return null;
        const { email, password } = parsed;
        if (typeof email !== "string" || typeof password !== "string")
            return null;
        if (email === "" || password === "")
            return null;
        return { email, password };
    }
    catch {
        return null;
    }
}
/** 1 回きりのリンクを出す artisan コマンド（値は独立した引数・shell を通さない） */
export function loginLinkArgs(projectPath) {
    return [
        "compose",
        "exec",
        "-T",
        ...composeUserArgs(projectPath),
        "app",
        "php",
        "artisan",
        "agentcms:login-link",
    ];
}
const LINK_RE = /^https?:\/\/\S+\/login\/once\/[A-Za-z0-9]{64}$/;
/**
 * コマンドの出力から URL を取り出す。
 *
 * **完了画面の管理画面と同じ場所（origin）のものだけ**を返す。
 * 出力が何であれ、それ以外の URL をブラウザで開かせない。
 */
export function parseLoginLink(stdout, loginUrl) {
    let expected;
    try {
        expected = new URL(loginUrl).origin;
    }
    catch {
        return null;
    }
    const lines = stdout
        .split(/\r?\n/)
        .map((line) => line.trim())
        .filter((line) => line !== "");
    const last = lines.at(-1) ?? "";
    if (!LINK_RE.test(last))
        return null;
    try {
        return new URL(last).origin === expected ? last : null;
    }
    catch {
        return null;
    }
}
/** 既定の実行。**echo も行コールバックも付けない**（詳細ログ・端末に URL を残さない） */
const quietRun = (cmd, args, cwd) => run(cmd, args, { cwd, echo: false });
export async function issueLoginLink(projectPath, loginUrl, runner = quietRun) {
    if (projectPath === "" || loginUrl === "")
        return null;
    try {
        const result = await runner("docker", loginLinkArgs(projectPath), projectPath);
        if (result.code !== 0)
            return null;
        return parseLoginLink(result.stdout, loginUrl);
    }
    catch {
        return null;
    }
}
