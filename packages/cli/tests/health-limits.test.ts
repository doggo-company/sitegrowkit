/**
 * health はサイトの HTML と応答を読む。乗っ取られたサイトは、どちらも好きに返せる。
 * - HTML の中のアセットの参照が別の場所（手元の 127.0.0.1 など）を指していても、取りに行かない
 * - 巨大な応答を返されても、決めた大きさで読むのをやめる（手元の CLI のメモリを使い切らない）
 *
 * HTTP は張らない（`fetch` を差し替える）。
 */
import { afterEach, describe, expect, it, vi } from "vitest";

import {
  HEALTH_MAX_BODY_BYTES,
  runHealthChecks,
  sameOriginAsset,
} from "../src/deploy/health.js";

afterEach(() => {
  vi.unstubAllGlobals();
});

function stub(top: string | ReadableStream<Uint8Array>): string[] {
  const seen: string[] = [];
  vi.stubGlobal(
    "fetch",
    vi.fn(async (input: string) => {
      seen.push(String(input));
      const u = new URL(input);
      if (u.origin !== "https://site.example") return new Response("internal", { status: 200 });
      if (u.pathname === "/") return new Response(top, { status: 200 });
      if (u.pathname.startsWith("/build/assets/")) return new Response("js", { status: 200 });
      return new Response("", { status: 404 });
    }),
  );
  return seen;
}

describe("health はサイトの HTML・応答を信じすぎない", () => {
  it("同じ origin の参照だけを URL に解く", () => {
    const base = "https://site.example/";
    expect(sameOriginAsset("/build/assets/app.js", base)).toBe("https://site.example/build/assets/app.js");
    expect(sameOriginAsset("https://site.example/build/assets/app.js", base)).toBe("https://site.example/build/assets/app.js");
    expect(sameOriginAsset("http://127.0.0.1:8765/build/assets/probe.js", base)).toBeNull();
    expect(sameOriginAsset("//evil.example/build/assets/x.js", base)).toBeNull();
    expect(sameOriginAsset("https://site.example/x?u=/build/assets/", base)).toBeNull();
  });

  it("HTML が別の場所のアセットを指していたら、そこへは GET を送らず FAIL にする", async () => {
    const seen = stub('<html><script src="http://127.0.0.1:8765/build/assets/probe.js"></script></html>');
    const checks = await runHealthChecks("https://site.example", "/healthz", "staging");
    expect(seen.some((u) => u.includes("127.0.0.1"))).toBe(false);
    expect(checks.find((c) => c.key === "assets")?.status).toBe("FAIL");
  });

  it("同じ origin のアセットは今までどおり取りに行く", async () => {
    const seen = stub('<html><script src="/build/assets/app.js"></script></html>');
    const checks = await runHealthChecks("https://site.example", "/healthz", "staging");
    expect(seen).toContain("https://site.example/build/assets/app.js");
    expect(checks.find((c) => c.key === "assets")?.status).toBe("PASS");
  });

  it("上限を超える応答は読むのをやめ、接続できなかったと同じに扱う", async () => {
    let sent = 0;
    const chunk = new Uint8Array(256 * 1024).fill(65);
    const big = new ReadableStream<Uint8Array>({
      pull(c) {
        if (sent > HEALTH_MAX_BODY_BYTES * 4) return c.close();
        sent += chunk.byteLength;
        c.enqueue(chunk);
      },
    });
    stub(big);
    const checks = await runHealthChecks("https://site.example", "/healthz", "staging");
    expect(checks.find((c) => c.key === "top")?.status).toBe("FAIL");
    expect(sent).toBeLessThanOrEqual(HEALTH_MAX_BODY_BYTES + 3 * chunk.byteLength);
  });
});
