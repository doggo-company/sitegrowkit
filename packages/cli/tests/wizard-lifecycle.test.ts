import { mkdtempSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { afterEach, describe, expect, it } from "vitest";

import { startWizardServer } from "../src/wizard/server.js";

const here = dirname(fileURLToPath(import.meta.url));
const assetsDir = resolve(here, "../src/wizard/assets");

/* ---------------------------------------------------------------- *
 * Phase 8.5-D.8 / F-D3 — Wizard を無期限に残さない
 *
 * かつて Wizard server は自分から終了する経路を持たず、launcher を
 * 起動するたびにプロセスが増え、PC / WSL 再起動まで残っていた。
 * 「利用者が何もしなくても永久には残らない」ことを保証する。
 * ---------------------------------------------------------------- */

const temps: string[] = [];

function tempHome(): string {
  const dir = mkdtempSync(join(tmpdir(), "agentcms-lifecycle-"));
  temps.push(dir);
  return dir;
}

afterEach(() => {
  delete process.env.AGENTCMS_WIZARD_IDLE_MS;
  delete process.env.AGENTCMS_WIZARD_READY_MS;
  while (temps.length) {
    const dir = temps.pop();
    if (dir) rmSync(dir, { recursive: true, force: true });
  }
});

/** closed が期限内に解決すること（＝ process を止める妨げが残っていない） */
async function closesWithin(
  closed: Promise<void>,
  ms: number,
): Promise<boolean> {
  let done = false;
  const timer = new Promise<void>((r) => setTimeout(r, ms));
  await Promise.race([closed.then(() => void (done = true)), timer]);
  return done;
}

describe("Wizard の寿命（F-D3 — 無期限残留をなくす）", () => {
  it("操作が無いまま idle timeout を過ぎたら graceful shutdown する", async () => {
    process.env.AGENTCMS_WIZARD_IDLE_MS = "400";
    const server = await startWizardServer({ assetsDir, home: tempHome() });

    expect(await closesWithin(server.closed, 6_000)).toBe(true);
  });

  it("SSE を開いたままでも寿命は延びない（タブを開きっぱなしでも残らない）", async () => {
    process.env.AGENTCMS_WIZARD_IDLE_MS = "500";
    const server = await startWizardServer({ assetsDir, home: tempHome() });
    const token = new URL(server.url).searchParams.get("t") ?? "";

    // SSE を開く（受信し続ける purchaser のブラウザと同じ状態）
    const controller = new AbortController();
    const res = await fetch(`http://127.0.0.1:${server.port}/api/events`, {
      headers: { Cookie: `agentcms_setup=${token}` },
      signal: controller.signal,
    });
    expect(res.status).toBe(200);

    try {
      expect(await closesWithin(server.closed, 8_000)).toBe(true);
    } finally {
      controller.abort();
    }
  });

  it("操作している間は終了しない（リクエストが寿命を延ばす）", async () => {
    process.env.AGENTCMS_WIZARD_IDLE_MS = "600";
    const server = await startWizardServer({ assetsDir, home: tempHome() });
    const token = new URL(server.url).searchParams.get("t") ?? "";

    // 200ms ごとに操作し続ける間は開いたままであること
    const until = Date.now() + 1_400;
    while (Date.now() < until) {
      const res = await fetch(`http://127.0.0.1:${server.port}/api/state`, {
        headers: { Cookie: `agentcms_setup=${token}` },
      });
      expect(res.status).toBe(200);
      await new Promise((r) => setTimeout(r, 200));
    }
    expect(await closesWithin(server.closed, 100)).toBe(false);

    // 操作をやめれば timeout 後に終了する
    expect(await closesWithin(server.closed, 8_000)).toBe(true);
  });

  it("close() は二重に呼んでも安全で、必ず解決する", async () => {
    process.env.AGENTCMS_WIZARD_IDLE_MS = "600000";
    const server = await startWizardServer({ assetsDir, home: tempHome() });

    await Promise.all([server.close(), server.close()]);
    expect(await closesWithin(server.closed, 1_000)).toBe(true);
  });
});
