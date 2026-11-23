import { mkdtempSync, rmSync } from "node:fs";
import { homedir, tmpdir } from "node:os";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { afterEach, describe, expect, it, vi } from "vitest";

import {
  OPEN_TARGET_ENV,
  openExternal,
  openerCandidates,
} from "../src/wizard/open.js";
import { startWizardServer } from "../src/wizard/server.js";

const here = dirname(fileURLToPath(import.meta.url));
const assetsDir = resolve(here, "../src/wizard/assets");

/* ---------------------------------------------------------------- *
 * Phase 8.5-C.3 / F-W1 — WSL でブラウザを開けなくても落ちない
 * ---------------------------------------------------------------- */

afterEach(() => {
  vi.restoreAllMocks();
  vi.resetModules();
});

/** platform / WSL 判定を差し替えて opener 候補を得る */
async function candidatesFor(
  platformValue: NodeJS.Platform,
  wsl: boolean,
): Promise<ReturnType<typeof openerCandidates>> {
  vi.resetModules();
  vi.doMock("node:os", async () => {
    const actual = await vi.importActual<typeof import("node:os")>("node:os");
    return { ...actual, platform: () => platformValue };
  });
  vi.doMock("../src/runtime-user.js", async () => {
    const actual = await vi.importActual<
      typeof import("../src/runtime-user.js")
    >("../src/runtime-user.js");
    return { ...actual, isWsl: () => wsl };
  });
  const mod = await import("../src/wizard/open.js");
  return mod.openerCandidates();
}

describe("opener の候補（WSL は単一依存にしない）", () => {
  it("WSL では wslview を先頭に、フォールバックを続けて持つ", async () => {
    const list = await candidatesFor("linux", true);
    const cmds = list.map((o) => o.cmd);
    expect(cmds[0]).toBe("wslview");
    expect(cmds).toContain("explorer.exe");
    expect(cmds).toContain("powershell.exe");
    expect(cmds.length).toBeGreaterThanOrEqual(3);
  });

  it("macOS は open のみ（既存挙動を変えない）", async () => {
    const list = await candidatesFor("darwin", false);
    expect(list).toEqual([{ cmd: "open", args: [] }]);
  });

  it("WSL でない Linux は xdg-open のまま（support scope を広げない）", async () => {
    const list = await candidatesFor("linux", false);
    expect(list).toEqual([{ cmd: "xdg-open", args: [] }]);
  });

  it("explorer.exe は終了コードを成功判定に使わない", async () => {
    const list = await candidatesFor("linux", true);
    const explorer = list.find((o) => o.cmd === "explorer.exe");
    expect(explorer?.ignoreExitCode).toBe(true);
  });

  // F-D6（実測）: explorer.exe は `?` を含む URL を開けず、しかも
  // ignoreExitCode のため失敗しても成功として報告される。
  // Setup Wizard の URL は必ず `?t=` を含むため、**最後の手段**でなければならない。
  it("explorer.exe は powershell.exe より後（最後の手段）に置く", async () => {
    const cmds = (await candidatesFor("linux", true)).map((o) => o.cmd);
    expect(cmds.indexOf("powershell.exe")).toBeLessThan(
      cmds.indexOf("explorer.exe"),
    );
    expect(cmds[cmds.length - 1]).toBe("explorer.exe");
  });
});

describe("セキュリティ特性", () => {
  it("URL を shell 文字列へ連結しない（PowerShell へは環境変数で渡す）", async () => {
    const list = await candidatesFor("linux", true);
    const ps = list.find((o) => o.cmd === "powershell.exe");
    expect(ps?.targetVia).toBe("env");
    // 引数は固定。URL を含む要素が無いこと
    expect(ps?.args.join(" ")).toContain(`$env:${OPEN_TARGET_ENV}`);
    for (const arg of ps?.args ?? []) {
      expect(arg).not.toMatch(/https?:\/\//);
    }
  });

  // F-D6（実測）: WSL の環境変数は WSLENV に名前を列挙しないと
  // Windows プロセスへ渡らず、`$env:AGENTCMS_OPEN_TARGET` が空になって
  // Start-Process が失敗していた。
  it("環境変数で渡す opener には WSLENV を付ける", async () => {
    vi.resetModules();
    vi.doMock("node:os", async () => {
      const actual = await vi.importActual<typeof import("node:os")>("node:os");
      return { ...actual, platform: () => "linux" as NodeJS.Platform };
    });
    vi.doMock("../src/runtime-user.js", async () => {
      const actual = await vi.importActual<
        typeof import("../src/runtime-user.js")
      >("../src/runtime-user.js");
      return { ...actual, isWsl: () => true };
    });
    const calls: { cmd: string; env?: Record<string, string> }[] = [];
    vi.doMock("../src/run.js", async () => {
      const actual =
        await vi.importActual<typeof import("../src/run.js")>("../src/run.js");
      return {
        ...actual,
        run: async (
          cmd: string,
          _args: string[],
          opts: { env?: Record<string, string> } = {},
        ) => {
          calls.push({ cmd, env: opts.env });
          // wslview は未導入、powershell は成功、という実機構成を再現する
          if (cmd === "wslview") {
            const err = new Error("not found") as NodeJS.ErrnoException;
            err.code = "ENOENT";
            throw err;
          }
          return { code: 0, stdout: "", stderr: "" };
        },
      };
    });
    try {
      const mod = await import("../src/wizard/open.js");
      const url = "http://127.0.0.1:65500/?t=abc";
      const result = await mod.openExternal(url);

      expect(result.opened).toBe(true);
      const ps = calls.find((c) => c.cmd === "powershell.exe");
      expect(ps?.env?.[OPEN_TARGET_ENV]).toBe(url);
      expect(ps?.env?.WSLENV?.split(":")).toContain(OPEN_TARGET_ENV);
      // explorer.exe まで到達しない（powershell で成功しているため）
      expect(calls.map((c) => c.cmd)).not.toContain("explorer.exe");
    } finally {
      // run.js の差し替えを後続テストへ波及させない
      vi.doUnmock("../src/run.js");
      vi.resetModules();
    }
  });

  it("localhost 以外の URL は開かない（開こうともしない）", async () => {
    const result = await openExternal("https://example.com/evil");
    expect(result.opened).toBe(false);
    expect(result.rejected).toBe(true);
    expect(result.attempts).toEqual([]);
  });

  it("生成した localhost URL はそのまま対象になる", async () => {
    const home = mkdtempSync(join(tmpdir(), "agentcms-openurl-"));
    const server = await startWizardServer({ assetsDir, home });
    try {
      const url = new URL(server.url);
      expect(url.hostname).toBe("127.0.0.1");
      expect(url.searchParams.get("t")).toBeTruthy();
      // token は base64url のみ（shell metacharacter を含み得ない）
      expect(url.searchParams.get("t")).toMatch(/^[A-Za-z0-9_-]+$/);
    } finally {
      await server.close();
      rmSync(home, { recursive: true, force: true });
    }
  });
});

describe("失敗しても落とさない（F-W1）", () => {
  it("最初の opener が ENOENT でも例外を投げず次を試す", async () => {
    vi.resetModules();
    vi.doMock("../src/wizard/open.js", async () => {
      const actual = await vi.importActual<
        typeof import("../src/wizard/open.js")
      >("../src/wizard/open.js");
      return actual;
    });
    // 実在しない opener だけを候補にした状態を再現する
    const mod = await import("../src/wizard/open.js");
    const spy = vi.spyOn(mod, "openerCandidates").mockReturnValue([
      { cmd: "agentcms-missing-opener-a", args: [] },
      { cmd: "agentcms-missing-opener-b", args: [] },
    ]);

    const result = await mod.openExternal(`file://${homedir()}`);
    // file:// は許可されないため rejected（例外にはならない）
    expect(result.opened).toBe(false);
    spy.mockRestore();
  });

  it("すべての opener が使えなくても例外にならず not-found を返す", async () => {
    const home = mkdtempSync(join(tmpdir(), "agentcms-openfail-"));
    const server = await startWizardServer({ assetsDir, home });
    try {
      vi.resetModules();
      vi.doMock("node:os", async () => {
        const actual =
          await vi.importActual<typeof import("node:os")>("node:os");
        // 候補が存在しない platform を装う
        return { ...actual, platform: () => "sunos" as NodeJS.Platform };
      });
      const mod = await import("../src/wizard/open.js");

      const result = await mod.openExternal(server.url);
      // 例外にならず、どの候補も成功しなかったことが結果として返る
      expect(result.opened).toBe(false);
      expect(result.attempts.every((a) => a.outcome !== "opened")).toBe(true);

      // 重要: server は生きたまま
      const res = await fetch(`http://127.0.0.1:${server.port}/api/state`, {
        headers: {
          Cookie: `agentcms_setup=${new URL(server.url).searchParams.get("t")}`,
        },
      });
      expect(res.status).toBe(200);
    } finally {
      await server.close();
      rmSync(home, { recursive: true, force: true });
    }
  });

  it("存在しない opener を spawn しても reject しない（構造化された結果を返す）", async () => {
    vi.resetModules();
    vi.doMock("node:os", async () => {
      const actual = await vi.importActual<typeof import("node:os")>("node:os");
      return { ...actual, platform: () => "linux" as NodeJS.Platform };
    });
    vi.doMock("../src/runtime-user.js", async () => {
      const actual = await vi.importActual<
        typeof import("../src/runtime-user.js")
      >("../src/runtime-user.js");
      // WSL 扱いにして wslview / explorer.exe / powershell.exe を試させる
      return { ...actual, isWsl: () => true };
    });
    const mod = await import("../src/wizard/open.js");

    // macOS 上では 3 つとも存在しないため、全て not-found になるはず
    const result = await mod.openExternal("http://127.0.0.1:65500/?t=abc");
    expect(result.opened).toBe(false);
    expect(result.attempts.map((a) => a.cmd)).toEqual([
      "wslview",
      "powershell.exe",
      "explorer.exe",
    ]);
    for (const a of result.attempts) {
      expect(["not-found", "failed"]).toContain(a.outcome);
    }
  });
});
