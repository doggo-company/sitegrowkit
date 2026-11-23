import { describe, expect, it } from "vitest";

import { readyLines } from "../src/commands/dev.js";

/**
 * 起動できたあとの最後の出力（WP 一周 2026-09-03 / オーナー 13:06）。
 *
 * オーナーの原文 —「**一番困るのははじめインストールした後に何をすればいいか
 * わからないこと**」。
 *
 * これまでの終わり方は URL の羅列だけだった。開けるようになったことは分かるが、
 * **開いて何をするか**がどこにも書いていない。ここで固定するのは
 * 「終わりに、次の一手が 3 行で書いてあること」である。
 */
describe("agentcms dev の終わりの出力", () => {
  const lines = readyLines({
    publicUrl: "http://localhost:8180",
    loginUrl: "http://localhost:8180/login",
    credentialsPath: ".agentcms/local/admin-credentials.json",
    mailpitUrl: "http://localhost:8026",
  });

  const text = lines.join("\n");

  it("開く場所を出す", () => {
    expect(text).toContain("http://localhost:8180");
    expect(text).toContain("http://localhost:8180/login");
  });

  it("認証情報の在り処を出し、値は出さない", () => {
    expect(text).toContain(".agentcms/local/admin-credentials.json");
    // パスワードそのものは**絶対に出さない**（端末の履歴とスクロールに残る）
    expect(text).not.toMatch(/password|パスワード[:：]/i);
  });

  it("次にやることを 3 行で出す", () => {
    const next = lines.slice(lines.indexOf("次にやること:") + 1);

    expect(lines).toContain("次にやること:");
    expect(next.filter((line) => line.trim().startsWith("1."))).toHaveLength(1);
    expect(next.filter((line) => line.trim().startsWith("2."))).toHaveLength(1);
    expect(next.filter((line) => line.trim().startsWith("3."))).toHaveLength(1);
  });

  it("最初の一手はログインである", () => {
    const first = lines.find((line) => line.trim().startsWith("1."));

    expect(first).toContain("ログイン");
  });

  it("ダッシュボードの 6 歩へ繋ぐ", () => {
    // 画面を開いたあと何を見ればよいかを言う（画面側の「最初の 6 歩」と同じ言葉）
    expect(text).toContain("最初の 6 歩");
  });
});
