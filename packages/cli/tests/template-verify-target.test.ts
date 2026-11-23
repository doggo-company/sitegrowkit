import { mkdtempSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { afterEach, describe, expect, it } from "vitest";

import { httpTarget } from "../src/commands/template-verify.js";

/**
 * HTTP 検査の宛先（R-14）。
 *
 * 以前は環境変数が無ければ `http://localhost:8180` 決め打ちで、
 * **利用者のプロジェクトを検査したつもりで、そこに居た別プロジェクトを見ていた**
 * （`APP_URL=http://localhost:8182` のサイトで、8180 の `/contact` を評価していた）。
 *
 * B-20 で「commit 前の門に HTTP を混ぜない」と決めたのと**同じ事故**が、
 * 利用者の経路でも起きていた。
 */
function projectWith(env: string | null): string {
  const dir = mkdtempSync(join(tmpdir(), "agentcms-verify-target-"));

  if (env !== null) writeFileSync(join(dir, ".env"), env, "utf8");

  return dir;
}

const saved = process.env["APP_URL"];

afterEach(() => {
  if (saved === undefined) delete process.env["APP_URL"];
  else process.env["APP_URL"] = saved;
});

describe("HTTP 検査の宛先", () => {
  it("project の .env を既定にする", () => {
    delete process.env["APP_URL"];

    const root = projectWith("APP_NAME=x\nAPP_URL=http://localhost:8182\n");

    expect(httpTarget(root)).toBe("http://localhost:8182");
  });

  /** 明示は上書き。CI や一時的な向け先を殺さない */
  it("環境変数が在れば、そちらが勝つ", () => {
    process.env["APP_URL"] = "http://localhost:9999";

    const root = projectWith("APP_URL=http://localhost:8182\n");

    expect(httpTarget(root)).toBe("http://localhost:9999");
  });

  it("空の環境変数は勝たない", () => {
    process.env["APP_URL"] = "";

    const root = projectWith("APP_URL=http://localhost:8182\n");

    expect(httpTarget(root)).toBe("http://localhost:8182");
  });

  it("どちらも無ければ既定", () => {
    delete process.env["APP_URL"];

    expect(httpTarget(projectWith(null))).toBe("http://localhost:8180");
  });

  it(".env に APP_URL が無ければ既定", () => {
    delete process.env["APP_URL"];

    expect(httpTarget(projectWith("APP_NAME=x\n"))).toBe(
      "http://localhost:8180",
    );
  });
});
