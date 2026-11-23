/**
 * README_FIRST の「6 歩が済んだら、サーバーに出す」の順番（直し 4 回目）。
 *
 * `agentcms deploy` の setup / doctor / plan は、どれも `.agentcms/deploy/<target>.json` が無いと
 * 「デプロイ設定がありません」で止まる（生成プロジェクトで実測）。だから手順書 §4（設定を作る）を
 * どのコマンドよりも先に書く。管理画面の同じ案内は template 側の Round4DeployGuideTest が見る
 * （template/tests は kit の親ディレクトリを読めない — productization.test.ts）。
 */
import { readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

import { describe, expect, it } from "vitest";

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), "../../..");

function between(text: string, from: string, to: string): string {
  const start = text.indexOf(from);
  expect(start, `${from} が見つからない`).toBeGreaterThanOrEqual(0);
  const end = text.indexOf(to, start);
  expect(end, `${to} が見つからない`).toBeGreaterThan(start);
  return text.slice(start, end);
}

describe("README_FIRST のサーバーに出す手順", () => {
  it("デプロイ設定（手順書 §4）を、どの agentcms deploy コマンドよりも先に書く", () => {
    const readme = readFileSync(resolve(repoRoot, "README_FIRST.md"), "utf8");
    const section = between(readme, "### 6 歩が済んだら、サーバーに出す", "## 7.");
    const steps = between(section, "進める順番", "本番は手順書の §12");

    expect(steps).toContain("手順書 §4");
    const settings = steps.indexOf(".agentcms/deploy/staging.example.json");
    expect(settings, "デプロイ設定の段が無い").toBeGreaterThanOrEqual(0);
    for (const command of [
      "agentcms deploy setup",
      "agentcms deploy doctor",
      "agentcms deploy plan",
      "agentcms deploy staging",
    ]) {
      const at = steps.indexOf(command);
      expect(at, `${command} が無い`).toBeGreaterThanOrEqual(0);
      expect(settings, `${command} がデプロイ設定より先に出ている`).toBeLessThan(at);
    }
  });
});
