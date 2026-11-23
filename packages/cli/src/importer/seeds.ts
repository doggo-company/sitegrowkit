import type {
  CardSeed,
  DatedSeed,
  SeedCollection,
  TemplatePlan,
} from "./plan-types.js";

/**
 * seed の読み取り（R-13 Stage 2）。
 *
 * plan は「どの型へ・どの形で・どの行を入れるか」を `collections` に持つ。
 * 読む側が `seeds.works` のように**型名を名指しすると、別名の型を扱えない**ので、
 * ここを通して**形（shape）で**取り出す。
 */

/**
 * 古い形式（v1 / v2）の seeds。
 *
 * **記録は書き換えない。** kit に commit されている plan は Phase 5 の provenance で、
 * 作り直すと当時の取り込み結果と食い違う（実測: 再生成すると `index.html` の割当が変わる）。
 * **読む側が両方の形を読む**のが正しい — 新しく書くのは常に新しい形である。
 *
 * ▷ **古い形は、新しく作った fixture では絶対に踏めない。**
 *   `buildPlan()` で作れば必ず新しい形になるので、この枝を通るのは
 *   **commit 済みの現物を読む試験だけ**である（`tests/plan-legacy-record.test.ts`）。
 *   壊れ方は「静かに 0 件」— 例外は出ず、seed が 1 件も取れないまま緑で終わる。
 */
interface LegacySeeds {
  works?: CardSeed[];
  news?: DatedSeed[];
}

function collectionsOf(plan: TemplatePlan): SeedCollection[] {
  const seeds = plan.seeds as TemplatePlan["seeds"] & LegacySeeds;

  if (Array.isArray(seeds.collections)) return seeds.collections;

  const out: SeedCollection[] = [];

  if (seeds.works && seeds.works.length > 0) {
    out.push({
      type: "works",
      model: "Modules\\Works\\Models\\Work",
      shape: "cards",
      rows: seeds.works,
    });
  }

  if (seeds.news && seeds.news.length > 0) {
    out.push({
      type: "news",
      model: "Modules\\News\\Models\\News",
      shape: "dated",
      rows: seeds.news,
    });
  }

  return out;
}

/** 最初の `cards` collection（無ければ null） */
export function cardsOf(
  plan: TemplatePlan,
): (SeedCollection & { shape: "cards" }) | null {
  return (
    collectionsOf(plan).find(
      (c): c is SeedCollection & { shape: "cards" } => c.shape === "cards",
    ) ?? null
  );
}

/** 最初の `dated` collection（無ければ null） */
export function datedOf(
  plan: TemplatePlan,
): (SeedCollection & { shape: "dated" }) | null {
  return (
    collectionsOf(plan).find(
      (c): c is SeedCollection & { shape: "dated" } => c.shape === "dated",
    ) ?? null
  );
}

/** `cards` の行（無ければ空） */
export function cardRows(plan: TemplatePlan): CardSeed[] {
  return cardsOf(plan)?.rows ?? [];
}

/** `dated` の行（無ければ空） */
export function datedRows(plan: TemplatePlan): DatedSeed[] {
  return datedOf(plan)?.rows ?? [];
}

/** 投入する行が 1 つでもあるか */
export function hasSeedRows(plan: TemplatePlan): boolean {
  return collectionsOf(plan).some((c) => c.rows.length > 0);
}
