import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

import type { Manifest } from "../src/inspect/manifest.js";
import { generateFromPlan } from "../src/importer/generate.js";
import type { TemplatePlan } from "../src/importer/plan-types.js";
import {
  cardRows,
  cardsOf,
  datedRows,
  hasSeedRows,
} from "../src/importer/seeds.js";

/**
 * **commit 済みの古い記録を、そのまま読む**（監査 指摘 40）。
 *
 * ## 新しく作った fixture では、この枝を絶対に踏めない
 *
 * plan を `buildPlan()` で作れば、**必ず新しい形**になる。
 * 古い形（`seeds.works` / `seeds.news`）を通る道は、
 * **commit 済みの現物を読む試験でしか踏めない**。
 * 実際 `collectionsOf()` の古い形の枝を殺しても、CLI スイートは 752/752 緑だった。
 *
 * ## 壊れ方は「静かに 0 件」
 *
 * 枝が消えても例外は出ない。`collections` が空を返し、
 * **seed が 1 件も取れないまま緑で終わる**
 * （R-15 の「manifest を見つけられず 0 tests」と同型）。
 *
 * ## なぜ記録を作り直さないのか
 *
 * kit の `template-plan.json` は Phase 5 の **provenance**（何をどこから取り込んだかの記録）で、
 * **書き換えた時点で provenance ではなくなる**。
 * 実測でも、いま作り直すと `index.html` の割り当てが当時と変わる。
 * だから記録は据え置き、**読む側が両方の形を読む**。
 */
const FIXTURE = resolve(__dirname, "../../../examples/html-template");

const RECORD = resolve(
  __dirname,
  "../../../.agentcms/manifest/template-plan.json",
);

function committedPlan(): TemplatePlan {
  return JSON.parse(readFileSync(RECORD, "utf8")) as TemplatePlan;
}

describe("kit に commit 済みの plan を読む", () => {
  const plan = committedPlan();

  /** この記録が「古い形」であることを、まず確かめる（新しい形なら枝を踏めていない） */
  it("記録は古い形のままである（作り直していない）", () => {
    const seeds = plan.seeds as unknown as Record<string, unknown>;

    expect(
      seeds["collections"],
      "記録が新しい形になっている＝作り直された。provenance としては別物になる",
    ).toBeUndefined();
    expect(Array.isArray(seeds["works"])).toBe(true);
  });

  it("古い形からも seed を取り出せる", () => {
    expect(hasSeedRows(plan)).toBe(true);
    expect(cardRows(plan).length).toBeGreaterThan(0);
    expect(datedRows(plan).length).toBeGreaterThan(0);
  });

  /**
   * **無い項目を「未定義」のまま書き出さない**（監査 指摘 44）。
   *
   * `detail_route` は Stage 2 で足した項目なので、**古い記録には無い**
   * （commit 済みの記録の 3 つの integration すべてに無い — この試験がその証拠）。
   * 生成側が `undefined` を素通しすると `route("undefined", …)` を書き出し、
   * 公開ページが 500 になる（**R-13 8 件目が実案件で踏んだのと同じ壊れ方**）。
   */
  it("古い記録には detail_route が無い（この試験が踏んでいる世界）", () => {
    for (const integration of plan.cms_integrations) {
      expect(
        integration.detail_route,
        `${integration.id} に detail_route が在る＝記録が作り直された`,
      ).toBeUndefined();
    }
  });

  it('その記録から生成しても route("undefined") を書かない', () => {
    const manifest = JSON.parse(
      readFileSync(
        resolve(__dirname, "../../../.agentcms/manifest/template.json"),
        "utf8",
      ),
    ) as Manifest;

    const all = generateFromPlan(FIXTURE, manifest, plan)
      .files.map((f) => `${f.target}\n${String(f.content)}`)
      .join("\n");

    expect(all).not.toContain("undefined");
  });

  /** 古い記録は kit の時代のもの。投入先は kit の module（**単数形**）である */
  it("投入先は kit の module のクラスになる", () => {
    expect(cardsOf(plan)?.type).toBe("works");
    expect(cardsOf(plan)?.model).toBe("Modules\\Works\\Models\\Work");
  });
});
