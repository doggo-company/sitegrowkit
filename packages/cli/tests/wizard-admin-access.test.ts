import {
  mkdirSync,
  mkdtempSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { afterAll, beforeAll, describe, expect, it } from "vitest";

import type { RunResult } from "../src/run.js";
import {
  issueLoginLink,
  loginLinkArgs,
  parseLoginLink,
  readAdminCredential,
} from "../src/wizard/admin-access.js";
import { type WizardServer, startWizardServer } from "../src/wizard/server.js";
import { CSRF_HEADER, SESSION_COOKIE } from "../src/wizard/session.js";

const here = dirname(fileURLToPath(import.meta.url));
const assetsDir = resolve(here, "../src/wizard/assets");

/**
 * UX 一周 2 周目 N1（止まった）: 完了画面にログインのメールアドレスもパスワードも出ず、
 * Finder で隠れたフォルダの JSON を開かないと管理画面に入れなかった。
 */
const TOKEN = "A".repeat(64);
const LOGIN = "http://localhost:8180/login";

const temps: string[] = [];
function project(credential?: unknown): string {
  const dir = mkdtempSync(join(tmpdir(), "agentcms-admin-access-"));
  temps.push(dir);
  if (credential !== undefined) {
    mkdirSync(join(dir, ".agentcms/local"), { recursive: true });
    writeFileSync(
      join(dir, ".agentcms/local/admin-credentials.json"),
      typeof credential === "string" ? credential : JSON.stringify(credential),
    );
  }
  return dir;
}
afterAll(() => {
  while (temps.length) rmSync(temps.pop()!, { recursive: true, force: true });
});

const ok = (stdout: string, code = 0): RunResult => ({
  code,
  stdout,
  stderr: "",
  signal: null,
});

describe("ログイン情報を読む", () => {
  it("メールアドレスとパスワードを返す", () => {
    const dir = project({ email: "admin@example.test", password: "Secret23" });
    expect(readAdminCredential(dir)).toEqual({
      email: "admin@example.test",
      password: "Secret23",
    });
  });

  it("無い・壊れている・空なら null（作り話の値を出さない）", () => {
    expect(readAdminCredential(project())).toBeNull();
    expect(readAdminCredential(project("{broken"))).toBeNull();
    expect(
      readAdminCredential(project({ email: "a@b", password: "" })),
    ).toBeNull();
    expect(readAdminCredential("")).toBeNull();
  });
});

describe("1 回きりのログインリンク", () => {
  it("管理画面と同じ場所の URL だけを受け取る", () => {
    expect(
      parseLoginLink(`http://localhost:8180/login/once/${TOKEN}\n`, LOGIN),
    ).toBe(`http://localhost:8180/login/once/${TOKEN}`);
    // 別の場所・別の形は開かせない
    expect(
      parseLoginLink(`http://evil.example/login/once/${TOKEN}`, LOGIN),
    ).toBeNull();
    expect(
      parseLoginLink(`http://localhost:9999/login/once/${TOKEN}`, LOGIN),
    ).toBeNull();
    expect(
      parseLoginLink("http://localhost:8180/login/once/short", LOGIN),
    ).toBeNull();
    expect(
      parseLoginLink("ERROR 1 回きりのログインリンクは…", LOGIN),
    ).toBeNull();
    expect(
      parseLoginLink(`http://localhost:8180/login/once/${TOKEN}`, "not a url"),
    ).toBeNull();
  });

  it("artisan を docker compose 経由で呼ぶ（shell を通さない）", () => {
    const args = loginLinkArgs("/p");
    expect(args.slice(0, 3)).toEqual(["compose", "exec", "-T"]);
    expect(args.slice(-3)).toEqual(["php", "artisan", "agentcms:login-link"]);
  });

  it("コマンドが失敗したら null（画面はいつものログイン画面を開く）", async () => {
    const seen: string[][] = [];
    const url = await issueLoginLink("/p", LOGIN, async (_c, args) => {
      seen.push(args);
      return ok(`http://localhost:8180/login/once/${TOKEN}`, 1);
    });
    expect(url).toBeNull();
    expect(seen).toHaveLength(1);
    expect(
      await issueLoginLink("/p", LOGIN, async () => {
        throw new Error("no docker");
      }),
    ).toBeNull();
  });

  it("成功したら URL を返す", async () => {
    const url = await issueLoginLink("/p", LOGIN, async () =>
      ok(`\nhttp://localhost:8180/login/once/${TOKEN}\n`),
    );
    expect(url).toBe(`http://localhost:8180/login/once/${TOKEN}`);
  });
});

describe("セットアップ画面の口", () => {
  let server: WizardServer;
  let home: string;
  let token: string;
  let origin: string;
  let dir: string;

  beforeAll(async () => {
    home = mkdtempSync(join(tmpdir(), "agentcms-admin-access-home-"));
    temps.push(home);
    dir = project({ email: "admin@example.test", password: "Pw-Only-Here-9" });
    server = await startWizardServer({ assetsDir, home });
    token = new URL(server.url).searchParams.get("t") as string;
    origin = `http://127.0.0.1:${server.port}`;
  });
  afterAll(async () => {
    await server.close();
  });

  const cookie = () => ({ Cookie: `${SESSION_COOKIE}=${token}` });
  const ready = (phase = "READY") => {
    const internals = server.runner as unknown as {
      phase: string;
      ready: Record<string, unknown>;
    };
    internals.phase = phase;
    internals.ready = {
      projectPath: dir,
      loginUrl: LOGIN,
      credentialsPath: ".agentcms/local/admin-credentials.json",
    };
  };

  it("READY になる前は渡さない", async () => {
    // 途中で前回の完了情報が残っていても、READY でなければ渡さない
    ready("SETTING_UP");
    const res = await fetch(`${origin}/api/credentials`, { headers: cookie() });
    expect(res.status).toBe(404);
  });

  it("session が無ければ渡さない", async () => {
    ready();
    const res = await fetch(`${origin}/api/credentials`);
    expect(res.status).toBe(403);
  });

  it("READY では session 付きの GET でだけ渡す（キャッシュさせない）", async () => {
    ready();
    const res = await fetch(`${origin}/api/credentials`, { headers: cookie() });
    expect(res.status).toBe(200);
    expect(res.headers.get("cache-control")).toBe("no-store");
    expect(await res.json()).toEqual({
      ok: true,
      email: "admin@example.test",
      password: "Pw-Only-Here-9",
    });
  });

  it("パスワードは snapshot（SSE・状態）と詳細ログに載らない", async () => {
    ready();
    const state = await (
      await fetch(`${origin}/api/state`, { headers: cookie() })
    ).text();
    expect(state).not.toContain("Pw-Only-Here-9");
    const logs = await (
      await fetch(`${origin}/api/logs`, { headers: cookie() })
    ).text();
    expect(logs).not.toContain("Pw-Only-Here-9");
  });

  it("ログインリンクは他のサイトから作らせない（CSRF）", async () => {
    ready();
    const res = await fetch(`${origin}/api/login-link`, {
      method: "POST",
      headers: {
        ...cookie(),
        Origin: "http://evil.example",
        [CSRF_HEADER]: "1",
      },
    });
    expect(res.status).toBe(403);
  });
});

describe("完了画面", () => {
  const html = readFileSync(join(assetsDir, "index.html"), "utf8");
  const js = readFileSync(join(assetsDir, "app.js"), "utf8");

  it("メールアドレスとパスワードを文字で出し、それぞれにコピーボタンがある", () => {
    expect(html).toContain('data-bind="credential-email"');
    expect(html).toContain('data-bind="credential-password"');
    expect(html).toContain('data-action="copy-email"');
    expect(html).toContain('data-action="copy-password"');
    expect(js).toContain('fetch("/api/credentials"');
    expect(js).toMatch(/credentialPassword\.textContent\s*=/);
    expect(js).not.toMatch(/credential\w*\.innerHTML/);
  });

  it("「管理画面を開く」は 1 回きりのリンクを取りに行き、だめならログイン画面", () => {
    expect(js).toContain('post("/api/login-link")');
    expect(js).toMatch(/let target = loginUrl;/);
  });
});
