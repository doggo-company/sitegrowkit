import { readFileSync } from "node:fs";
import { join } from "node:path";

import { CREDENTIALS_REL } from "../credentials.js";
import { type RunResult, run } from "../run.js";
import { composeUserArgs } from "../runtime-user.js";

/**
 * 完了画面から管理画面へ入るための 2 つの口（UX 一周 3 周目 N1）。
 *
 * 2 周目は、ログインのメールアドレスとパスワードが画面のどこにも出ず、
 * Finder で隠れたフォルダの JSON を開かないと入れなかった。
 *
 * 1. **メールアドレスとパスワードを画面に出す**（コピーボタン付き）
 * 2. **「管理画面を開く」を 1 回きりのログインリンクにする**（期限 5 分・ローカルのみ）
 *
 * ## 秘密の扱い
 *
 * - 値は **snapshot に入れない**。snapshot は SSE で流れ、再開状態の保存にも関わるため。
 *   READY のときだけ、session 付きの GET 1 本（`/api/credentials`）で渡す
 * - 値も 1 回きりの URL も **詳細ログへ流さない**（`run` に onStdout を渡さず、echo もしない）
 * - 出すのはこの PC の 127.0.0.1 で動いているセットアップ画面だけ
 */

export interface AdminCredential {
  email: string;
  password: string;
}

/** project の `.agentcms/local/admin-credentials.json` を読む。形が違えば null */
export function readAdminCredential(
  projectPath: string,
): AdminCredential | null {
  if (projectPath === "") return null;
  try {
    const parsed: unknown = JSON.parse(
      readFileSync(join(projectPath, CREDENTIALS_REL), "utf8"),
    );
    if (typeof parsed !== "object" || parsed === null) return null;
    const { email, password } = parsed as Record<string, unknown>;
    if (typeof email !== "string" || typeof password !== "string") return null;
    if (email === "" || password === "") return null;
    return { email, password };
  } catch {
    return null;
  }
}

/** 1 回きりのリンクを出す artisan コマンド（値は独立した引数・shell を通さない） */
export function loginLinkArgs(projectPath: string): string[] {
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
export function parseLoginLink(
  stdout: string,
  loginUrl: string,
): string | null {
  let expected: string;
  try {
    expected = new URL(loginUrl).origin;
  } catch {
    return null;
  }
  const lines = stdout
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter((line) => line !== "");
  const last = lines.at(-1) ?? "";
  if (!LINK_RE.test(last)) return null;
  try {
    return new URL(last).origin === expected ? last : null;
  } catch {
    return null;
  }
}

export type CommandRunner = (
  cmd: string,
  args: string[],
  cwd: string,
) => Promise<RunResult>;

/** 既定の実行。**echo も行コールバックも付けない**（詳細ログ・端末に URL を残さない） */
const quietRun: CommandRunner = (cmd, args, cwd) =>
  run(cmd, args, { cwd, echo: false });

export async function issueLoginLink(
  projectPath: string,
  loginUrl: string,
  runner: CommandRunner = quietRun,
): Promise<string | null> {
  if (projectPath === "" || loginUrl === "") return null;
  try {
    const result = await runner(
      "docker",
      loginLinkArgs(projectPath),
      projectPath,
    );
    if (result.code !== 0) return null;
    return parseLoginLink(result.stdout, loginUrl);
  } catch {
    return null;
  }
}
