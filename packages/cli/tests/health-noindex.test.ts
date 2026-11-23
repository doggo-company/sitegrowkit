/**
 * B-41 安全側 — **本番の health が必ず落ちる**のを止める（そして逆向きを測る）。
 *
 * `runHealthChecks` の noindex 検査は **staging 決め打ち**だった
 * （3 か所の呼び出しがどこも opts を渡していない）。本番は `Allow: /` を返すので
 * **必ず FAIL → 自動切り戻し**、初回は戻す先が無いので `stop`。
 * つまり**成功したデプロイが「失敗」と報告される**。
 *
 * 直す向きは「本番では検査を飛ばす」ではない。**逆向きを測る** —
 * 本番には **noindex が残っていてはいけない**（残っていれば検索から消える）。
 *
 * HTTP は張らない（`fetch` を差し替える）。
 */
import { readFileSync } from "node:fs";
import { afterEach, describe, expect, it, vi } from "vitest";

import {
  blocksEntireSite,
  noindexExpectation,
  runHealthChecks,
} from "../src/deploy/health.js";
import type { CapabilityCheck } from "../src/deploy/types.js";

/** アプリ実測: `routes/public.php` の robots.txt は環境で 2 通りに分岐する */
const ROBOTS_STAGING = "User-agent: *\nDisallow: /\n";
const ROBOTS_PRODUCTION =
  "User-agent: *\nAllow: /\nDisallow: /admin\nDisallow: /login\n\nSitemap: https://example.jp/sitemap.xml\n";

interface Page {
  status?: number;
  headers?: Record<string, string>;
  body?: string;
}

/** 応答表に載っていない path は 404。載っているものだけ返す偽サイト */
function stubSite(pages: Record<string, Page>): void {
  vi.stubGlobal(
    "fetch",
    vi.fn(async (input: string) => {
      const p = pages[new URL(input).pathname];
      if (!p) return new Response("", { status: 404 });
      return new Response(p.body ?? "", {
        status: p.status ?? 200,
        headers: p.headers,
      });
    }),
  );
}

const site = (robots: string, topHeaders: Record<string, string>): void =>
  stubSite({
    "/": { body: "<html>hello</html>", headers: topHeaders },
    "/robots.txt": { body: robots },
  });

const noindexCheck = (
  checks: CapabilityCheck[],
  key: string,
): CapabilityCheck | undefined => checks.find((c) => c.key === key);

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("robots.txt がサイト全体を塞いでいるか", () => {
  /**
   * **`includes("Disallow: /")` では測れない。**
   * 本番の robots.txt には `Disallow: /admin` が在るので、素朴な部分一致だと
   * **本番を「塞がれている」と読む**。行として `Disallow: /` だけを見る。
   */
  it("Disallow: /admin は塞いでいない", () => {
    expect(blocksEntireSite(ROBOTS_PRODUCTION)).toBe(false);
  });

  it("Disallow: / は塞いでいる", () => {
    expect(blocksEntireSite(ROBOTS_STAGING)).toBe(true);
  });

  it("CRLF でも読む", () => {
    expect(blocksEntireSite("User-agent: *\r\nDisallow: /\r\n")).toBe(true);
  });

  it("robots.txt が空なら塞いでいない", () => {
    expect(blocksEntireSite("")).toBe(false);
  });
});

describe("target ごとに noindex の期待は逆になる", () => {
  it("staging は noindex が必要", () => {
    expect(noindexExpectation("staging")).toBe("required");
  });

  /**
   * 判断の根拠はアプリ側の分岐そのもの（`app()->environment('staging')`）。
   * `ensureLayout()` がサーバーの `APP_ENV` を target に一致させているので、
   * **staging という名前の target だけが noindex になる**。
   */
  it("production は noindex が在ってはいけない", () => {
    expect(noindexExpectation("production")).toBe("forbidden");
  });
});

/**
 * **既定値を消しても、呼ぶ側が定数を書けば同じ穴が開く。**
 * 3 か所とも設定から target を渡していることを固定する。
 */
describe("呼び出し側は target を config から渡す", () => {
  it("runHealthChecks の呼び出しは 3 か所とも cfg.target を渡す", () => {
    const src = readFileSync(
      new URL("../src/commands/deploy.ts", import.meta.url),
      "utf8",
    );
    const calls = [...src.matchAll(/runHealthChecks\(([\s\S]*?)\);/g)].map(
      (m) => m[1] ?? "",
    );
    expect(calls).toHaveLength(3);
    for (const args of calls) expect(args).toMatch(/cfg\.target/);
  });
});

describe("staging の health", () => {
  it("Disallow: / と X-Robots-Tag が揃えば PASS", async () => {
    site(ROBOTS_STAGING, { "x-robots-tag": "noindex, nofollow" });
    const checks = await runHealthChecks(
      "https://stg.example.jp",
      "/healthz",
      "staging",
    );
    expect(noindexCheck(checks, "noindex")?.status).toBe("PASS");
    // 本番用の検査は走らない（逆向きの物差しが混ざらないこと）
    expect(noindexCheck(checks, "noindex_absent")).toBeUndefined();
  });

  it("本番と同じ robots.txt を返していたら FAIL（index されてしまう）", async () => {
    site(ROBOTS_PRODUCTION, {});
    const checks = await runHealthChecks(
      "https://stg.example.jp",
      "/healthz",
      "staging",
    );
    expect(noindexCheck(checks, "noindex")?.status).toBe("FAIL");
  });
});

describe("production の health", () => {
  /** **これが直したかった落ち方である。** 本番の正しい応答で緑になること。 */
  it("Allow: / + noindex 無しで PASS", async () => {
    site(ROBOTS_PRODUCTION, {});
    const checks = await runHealthChecks(
      "https://example.jp",
      "/healthz",
      "production",
    );
    expect(noindexCheck(checks, "noindex_absent")?.status).toBe("PASS");
    // staging 用の検査は走らない
    expect(noindexCheck(checks, "noindex")).toBeUndefined();
  });

  it("robots.txt が全体を塞いだままなら FAIL（本番が検索から消える）", async () => {
    site(ROBOTS_STAGING, {});
    const checks = await runHealthChecks(
      "https://example.jp",
      "/healthz",
      "production",
    );
    expect(noindexCheck(checks, "noindex_absent")?.status).toBe("FAIL");
  });

  it("X-Robots-Tag に noindex が残っていれば FAIL", async () => {
    site(ROBOTS_PRODUCTION, { "x-robots-tag": "noindex, nofollow" });
    const checks = await runHealthChecks(
      "https://example.jp",
      "/healthz",
      "production",
    );
    expect(noindexCheck(checks, "noindex_absent")?.status).toBe("FAIL");
  });
});
