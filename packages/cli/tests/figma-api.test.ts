/**
 * Figma API 境界のテスト（SPEC §86: 429 / retry-after / expired token / 403 plan 制限、無限 retry 禁止）
 * SPEC §100: 2 (token config rejection) / 35 (403) / 36 (429) / 37 (secret redaction)
 */
import { describe, expect, it } from "vitest";

import { resolveFigmaToken, redactFigmaSecrets } from "../src/figma/auth.js";
import { HttpFigmaApi } from "../src/figma/api-http.js";
import { parseFigmaUrl, normalizeNodeId } from "../src/figma/url.js";
import { diagnoseApiFailure, resolveFigmaApi } from "../src/figma/provider.js";

type FakeResponse = {
  status: number;
  ok: boolean;
  headers: { get(name: string): string | null };
  json(): Promise<unknown>;
  arrayBuffer(): Promise<ArrayBuffer>;
};

function res(
  status: number,
  body: unknown,
  headers: Record<string, string> = {},
): FakeResponse {
  return {
    status,
    ok: status >= 200 && status < 300,
    headers: { get: (name: string) => headers[name] ?? null },
    json: () => Promise.resolve(body),
    arrayBuffer: () => Promise.resolve(new ArrayBuffer(0)),
  };
}

function apiWith(responses: FakeResponse[]): {
  api: HttpFigmaApi;
  requests: { url: string; headers?: Record<string, string> }[];
  sleeps: number[];
} {
  const requests: { url: string; headers?: Record<string, string> }[] = [];
  const sleeps: number[] = [];
  const queue = [...responses];
  const api = new HttpFigmaApi("figd_testtoken12345678", {
    fetchFn: (url, init) => {
      requests.push({ url, headers: init.headers });
      const next = queue.shift();
      if (!next) throw new Error("no more fake responses");
      return Promise.resolve(next);
    },
    sleep: (ms) => {
      sleeps.push(ms);
      return Promise.resolve();
    },
  });
  return { api, requests, sleeps };
}

describe("figma auth / URL", () => {
  it("FIGMA_ACCESS_TOKEN を env から解決（未設定は null）", () => {
    expect(
      resolveFigmaToken({ FIGMA_ACCESS_TOKEN: " figd_abc123456789 " }),
    ).toBe("figd_abc123456789");
    expect(resolveFigmaToken({})).toBeNull();
  });

  it("redaction: figd_ token をマスクする", () => {
    expect(redactFigmaSecrets("error: figd_SECRETSECRET123 invalid")).toBe(
      "error: figd_*** invalid",
    );
  });

  it("URL parse: design URL から file key / node id を取得（SPEC §100 #1）", () => {
    const { info } = parseFigmaUrl(
      "https://www.figma.com/design/AbCdEf12345678/My-Site?node-id=1-2&t=xyz",
    );
    expect(info?.file_key).toBe("AbCdEf12345678");
    expect(info?.file_type).toBe("design");
    expect(info?.node_ids).toEqual(["1:2"]);
    expect(info?.file_name).toBe("My-Site");
  });

  it("URL parse: 不正 URL / 非 figma ドメインを拒否", () => {
    expect(parseFigmaUrl("not-a-url").error).toBeTruthy();
    expect(
      parseFigmaUrl("https://example.com/design/AbCdEf12345678/x").error,
    ).toContain("figma.com");
    expect(
      parseFigmaUrl("https://www.figma.com/files/recent").error,
    ).toBeTruthy();
  });

  it("node id 正規化（1-2 → 1:2、instance path 対応）", () => {
    expect(normalizeNodeId("1-2")).toBe("1:2");
    expect(normalizeNodeId("1-2%3B3-4")).toBe("1:2;3:4");
  });

  it("token / fixture が無い場合は {what,why,fix} 診断（--token は提供しない）", () => {
    const result = resolveFigmaApi({});
    expect("error" in result && result.error.why).toContain(
      "FIGMA_ACCESS_TOKEN",
    );
    expect("error" in result && result.error.why).toContain("--token");
  });
});

describe("HttpFigmaApi — rate limit / エラー処理", () => {
  it("認証は X-Figma-Token ヘッダのみ（URL に token を含めない）", async () => {
    const { api, requests } = apiWith([
      res(200, { name: "f", version: "1", editorType: "figma" }),
    ]);
    await api.getFileMeta("KEY123");
    expect(requests[0]?.headers?.["X-Figma-Token"]).toBe(
      "figd_testtoken12345678",
    );
    expect(requests[0]?.url).not.toContain("figd_");
    expect(requests[0]?.url).toContain("/v1/files/KEY123/meta");
  });

  it("429: Retry-After を尊重して retry し、成功したら返す（SPEC §100 #36）", async () => {
    const { api, sleeps } = apiWith([
      res(429, { err: "rate limit" }, { "Retry-After": "2" }),
      res(200, { name: "f", version: "1", editorType: "figma" }),
    ]);
    const result = await api.getFileMeta("KEY123");
    expect(result.ok).toBe(true);
    expect(sleeps).toEqual([2000]);
  });

  it("429 が続く場合は上限で停止する（無限 retry 禁止: SPEC §86）", async () => {
    const rateLimited = Array.from({ length: 10 }, () =>
      res(429, { err: "rate limit" }, { "Retry-After": "1" }),
    );
    const { api, requests } = apiWith(rateLimited);
    const result = await api.getFileMeta("KEY123");
    expect(result.ok).toBe(false);
    if (!result.ok) {
      expect(result.kind).toBe("rate_limit");
      expect(result.retryAfterSeconds).toBe(1);
    }
    expect(requests.length).toBe(4); // 初回 + retry 3 回で停止
  });

  it("403 は plan / scope 制限として診断される（SPEC §100 #35）", async () => {
    const { api } = apiWith([res(403, { err: "Invalid scope" })]);
    const result = await api.getLocalVariables("KEY123");
    expect(result.ok).toBe(false);
    if (!result.ok) {
      expect(result.kind).toBe("forbidden");
      const d = diagnoseApiFailure("Variables の取得", result);
      expect(d.why).toContain("plan 制限");
      expect(d.fix).toContain("Enterprise");
    }
  });

  it("401 は token 失効として診断される", async () => {
    const { api } = apiWith([res(401, { err: "Invalid token" })]);
    const result = await api.getFileMeta("KEY123");
    expect(result.ok).toBe(false);
    if (!result.ok) {
      expect(result.kind).toBe("auth");
      expect(diagnoseApiFailure("meta の取得", result).fix).toContain("再発行");
    }
  });

  it("エラーメッセージ中の token は redact される（SPEC §100 #37）", async () => {
    const { api } = apiWith([
      res(400, { err: "bad token figd_LEAKED1234567890" }),
    ]);
    const result = await api.getFileMeta("KEY123");
    expect(result.ok).toBe(false);
    if (!result.ok) {
      expect(result.message).not.toContain("figd_LEAKED");
      expect(result.message).toContain("figd_***");
    }
  });

  it("部分失敗: nodes 取得はエラーでも他のフローを壊さない（failure object を返す）", async () => {
    const { api } = apiWith([res(500, { err: "internal" })]);
    const result = await api.getFileNodes("KEY123", ["1:2"]);
    expect(result.ok).toBe(false);
    if (!result.ok) expect(result.kind).toBe("server");
  });
});
