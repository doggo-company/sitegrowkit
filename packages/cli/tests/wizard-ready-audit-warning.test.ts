import { mkdtempSync, readFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { afterEach, describe, expect, it, vi } from "vitest";

import type { EngineContext } from "../src/engine.js";

const verifyMock = vi.hoisted(() => vi.fn());
vi.mock("../src/commands/verify.js", () => ({ verify: verifyMock }));

const { WizardRunner } = await import("../src/wizard/runner.js");

const here = dirname(fileURLToPath(import.meta.url));
const assetsDir = resolve(here, "../src/wizard/assets");

/**
 * 2026-11-05 一周 S1: セットアップの最後で audit が落ちると全員が止まり、
 * サイトの URL もログイン情報も見られなかった。
 * setup では audit を止めずに READY にし、完了画面で知らせる。
 */
const temps: string[] = [];
function home(): string {
  const dir = mkdtempSync(join(tmpdir(), "agentcms-ready-audit-"));
  temps.push(dir);
  return dir;
}
afterEach(() => {
  verifyMock.mockReset();
  while (temps.length) rmSync(temps.pop()!, { recursive: true, force: true });
});

type Internals = {
  runFinalCheck(ctx: EngineContext, projectPath: string): Promise<void>;
  ready?: { auditWarnings?: string[]; projectPath: string };
};

function runner(): Internals {
  return new WizardRunner({
    pickerAvailable: false,
    defaultLocation: "/tmp",
    home: home(),
  }) as unknown as Internals;
}

describe("setup の最終確認と依存の脆弱性", () => {
  it("audit だけが落ちたら止めず、完了画面に警告を渡す", async () => {
    verifyMock.mockImplementation(
      async (ctx: EngineContext, options: { auditWarnOnly?: boolean }) => {
        expect(options.auditWarnOnly).toBe(true);
        ctx.onEvent?.({ type: "message", level: "warn", text: "⚠ composer audit: 脆弱性" });
        ctx.onEvent?.({ type: "message", level: "warn", text: "⚠ pnpm audit (prod): 脆弱性" });
        return 0;
      },
    );
    const r = runner();
    const seen: string[] = [];
    await r.runFinalCheck({ onEvent: (e) => e.type === "message" && seen.push(e.text) }, "/p");

    expect(r.ready?.auditWarnings).toHaveLength(2);
    expect(r.ready?.projectPath).toBe("/p");
    // 元の onEvent（詳細ログ）にも流れ続ける
    expect(seen).toHaveLength(2);
  });

  it("脆弱性が無ければ警告は空", async () => {
    verifyMock.mockResolvedValue(0);
    const r = runner();
    await r.runFinalCheck({}, "/p");
    expect(r.ready?.auditWarnings).toEqual([]);
  });

  it("audit 以外の失敗は今まで通り止める", async () => {
    verifyMock.mockResolvedValue(1);
    const r = runner();
    await expect(r.runFinalCheck({}, "/p")).rejects.toThrow();
  });
});

describe("完了画面", () => {
  const html = readFileSync(join(assetsDir, "index.html"), "utf8");
  const js = readFileSync(join(assetsDir, "app.js"), "utf8");

  it("URL を文字でも出す枠と、脆弱性のお知らせ枠がある", () => {
    expect(html).toContain('data-bind="ready-urls"');
    expect(html).toContain('data-bind="audit-warning"');
  });

  it("サイトと管理画面の URL・お知らせを textContent で描く", () => {
    expect(js).toContain("ready.publicUrl");
    expect(js).toContain("ready.loginUrl");
    expect(js).toContain("auditWarnings");
    expect(js).not.toMatch(/auditWarning\w*\.innerHTML/);
  });
});
