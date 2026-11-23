import { existsSync, readFileSync } from "node:fs";
import { join } from "node:path";

/**
 * 生成物に**手で入れた変更の記録**（B-2）。
 *
 * ## なぜ apply の記録と別の紙なのか
 *
 * `template-apply.json` は **apply が上書きする紙**である。手直しの記録をそこへ書くと、
 * **次の apply で消える**。書き手が違うものは紙を分ける。
 *
 * ## なぜ「手を入れた」だけでは足りないのか
 *
 * 印だけを置くと、**その後どれだけ変えても緑**になる。承認したのは
 * **その内容**であって、そのファイルではない。だから `sha256` を持ち、
 * **そこから先の変更はまた赤**になるようにする。
 *
 * ## なぜ `edited` と `removed` を分けるのか
 *
 * 「別の内容になった」と「もう無い」は**別の軸**である。1 つの配列へ混ぜると
 * `sha256: null` のような読めない値が要る。
 *
 * `reason` は検査が読まない。**人が読むためだけに在る**（理由の無い承認を作らない）。
 */
export interface TemplateEdits {
  edited: { target: string; sha256: string; reason: string }[];
  removed: { target: string; reason: string }[];
}

export interface GeneratedClassification {
  /** 記録どおりに手が入っているファイル（緑・ただし黙って緑にしない） */
  approved: string[];
  /** **記録に無い変更**（赤）。貼れる形で返す */
  unknown: { target: string; sha256: string }[];
  /** 記録に無い欠落（赤） */
  missing: string[];
  /** 配らないと決めたもの（緑） */
  removed: string[];
  /** 配らないと決めたのに在る（赤）。記録と現物の食い違い */
  contradictions: string[];
}

export const EMPTY_TEMPLATE_EDITS: TemplateEdits = { edited: [], removed: [] };

/** `.agentcms/manifest/template-edits.json`。無ければ空（**この検査を新設した日の既定**） */
export function loadTemplateEdits(agentcmsDir: string): TemplateEdits {
  const path = join(agentcmsDir, "manifest", "template-edits.json");

  if (!existsSync(path)) return EMPTY_TEMPLATE_EDITS;

  try {
    const raw = JSON.parse(
      readFileSync(path, "utf8"),
    ) as Partial<TemplateEdits>;

    return {
      edited: Array.isArray(raw.edited) ? raw.edited : [],
      removed: Array.isArray(raw.removed) ? raw.removed : [],
    };
  } catch {
    // 読めない記録は「無い」と同じに扱う。**壊れた JSON で門ごと落とさない**
    return EMPTY_TEMPLATE_EDITS;
  }
}

/**
 * 生成物の現状を仕分ける。
 *
 * `actual` は「その target の中身の sha256（無ければ null）」を返す。
 * **ファイルの読み方をここへ持ち込まない**ので、置かずに測れる。
 */
export function classifyGenerated(
  files: { target: string; sha256: string }[],
  edits: TemplateEdits,
  actual: (target: string) => string | null,
): GeneratedClassification {
  const approvedSha = new Map(edits.edited.map((e) => [e.target, e.sha256]));
  const removed = new Set(edits.removed.map((e) => e.target));

  const result: GeneratedClassification = {
    approved: [],
    unknown: [],
    missing: [],
    removed: [],
    contradictions: [],
  };

  for (const file of files) {
    const current = actual(file.target);

    if (removed.has(file.target)) {
      // 配らないと決めたもの。**在るなら記録と食い違っている**
      if (current === null) result.removed.push(file.target);
      else result.contradictions.push(file.target);
      continue;
    }

    if (current === null) {
      result.missing.push(file.target);
      continue;
    }

    if (current === file.sha256) continue;

    if (approvedSha.get(file.target) === current) {
      result.approved.push(file.target);
      continue;
    }

    result.unknown.push({ target: file.target, sha256: current });
  }

  return result;
}

/**
 * seeder が使っている category の値（F-43）。
 *
 * **空振りを緑と読まないため**に、ここは「見つからなかった」を空配列で返す。
 * 呼ぶ側は**空を「不足なし」と読んではいけない** — それは
 * 「一致した」ではなく「**測れていない**」である。
 *
 * 引用符は両方受ける。PHP は `'category'` とも `"category"` とも書けるので、
 * 片方だけを見ていると**書き方を変えただけで網が外れる**。
 */
export function seededCategories(source: string): string[] {
  return [
    ...source.matchAll(/["\']category["\']\s*=>\s*["\']([^"\']*)["\']/g),
  ].map((m) => m[1] ?? "");
}
