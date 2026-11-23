import { existsSync, readFileSync } from "node:fs";
import { join, resolve } from "node:path";

import { hashContent, loadApplyRecord } from "../importer/apply.js";
import {
  classifyGenerated,
  loadTemplateEdits,
  seededCategories,
} from "../importer/edits.js";
import type { ContentSchema, TemplatePlan } from "../importer/plan-types.js";
import { cardRows } from "../importer/seeds.js";
import { PLAN_VERSION } from "../importer/plan-contract.js";
import { fingerprintSource } from "../inspect/fingerprint.js";
import type { Manifest } from "../inspect/manifest.js";
import { readEnvValue } from "../credentials.js";
import { findAgentcmsDir, findProjectRoot } from "../project.js";
import { fail, heading, info, ok, warn } from "../ui.js";

type CheckStatus = "pass" | "fail" | "skip";

interface Check {
  name: string;
  status: CheckStatus;
  detail: string;
}

/**
 * Template Import の検証（spec §33）。
 * 静的検証（Manifest/Plan/fingerprint/生成物/schema/assets）+ 起動中の環境への HTTP 検証
 * （ページ表示 / broken links / asset 到達 / form 接続）を行う。
 * visual regression / Lighthouse / Pest / E2E は `pnpm e2e` / `pnpm lighthouse` / `agentcms verify` が担い、
 * このコマンドは最後にその実行を案内する。
 */
export interface TemplateVerifyOptions {
  /**
   * 起動中の環境へ**接続しない**（B-20）。
   *
   * HTTP 検査の宛先は `APP_URL`（既定 `http://localhost:8180`）で、**そこに居るものを何でも見る**。
   * commit の前に回す門としては、**たまたま別のプロジェクトが動いているだけで赤くなる**
   * — 実際 2026-08-20 に、別サイトの `/works` 404 でこの門が赤くなった。
   * **常に赤い門は、いずれ誰も見なくなる**（B-2 の教訓）ので、
   * 記録との一致だけを見たいときはこちらを使う。
   */
  offline?: boolean;
}

/**
 * HTTP 検査の宛先（R-14）。
 *
 * ## project の `.env` を既定にする
 *
 * 以前は環境変数 `APP_URL` が無ければ `http://localhost:8180` 決め打ちだった。
 * **利用者のプロジェクトは自分の port を `.env` に持っている**ので、
 * そこを読まないと**別のプロジェクトを検査してしまう** — 実際に起きた:
 * `APP_URL=http://localhost:8182` のサイトを検査したつもりで、
 * **8180 に居た別プロジェクトの `/contact` を評価していた**。
 *
 * B-20 で「commit 前の門に HTTP を混ぜない」と決めたのは同じ事故を kit で見たからで、
 * **利用者の経路でも同じことが起きていた。**
 *
 * ## 優先順
 *
 * 1. 環境変数 `APP_URL`（**明示は上書き**。CI や一時的な向け先を殺さない）
 * 2. project root の `.env` の `APP_URL`
 * 3. `http://localhost:8180`（どちらも無いときだけ）
 */
/**
 * このプロジェクトで**実際に回せる**品質ゲート（R-15 Stage 1）。
 *
 * 以前は常に「`pnpm e2e`（visual regression 含む）→ `pnpm lighthouse` → `agentcms verify`」と
 * 出していた。**生成プロジェクトには spec も playwright の設定も配っていない**ので、
 * `pnpm e2e` はそのままでは動かない — **書いてあるとおりにやると止まる案内**である
 * （R-13 の seeder・指摘 32 と同じ形。これで 3 件目）。
 *
 * ▷ **在るものだけを言う。** 無いものを案内しない。
 */
export function remainingGates(projectRoot: string): string[] {
  const gates: string[] = [];
  const has = (rel: string): boolean => existsSync(join(projectRoot, rel));

  /*
   * 設定と spec の**両方**が要る。片方だけでは playwright は何も実行しない。
   *
   * どちらの設定が在るかで**案内する命令が変わる**。生成プロジェクトには
   * 標準 E2E の設定を配っていない（`globalSetup` が docker と kit の seeder を前提にする）ので、
   * そこで `pnpm e2e` と案内すると **設定の無い playwright を起動させる**ことになる。
   */
  if (has("tests/e2e")) {
    if (has("playwright.config.ts")) {
      gates.push("`pnpm e2e`（visual regression 含む）");
    } else if (has("playwright.visual.config.ts")) {
      gates.push("`pnpm e2e:visual`（取り込み元との視覚差分）");
    }
  }

  if (has("lighthouserc.json")) gates.push("`pnpm lighthouse`");

  gates.push("`agentcms verify`");

  return gates;
}

export function httpTarget(projectRoot: string): string {
  const explicit = process.env["APP_URL"];

  if (explicit !== undefined && explicit !== "") return explicit;

  const envPath = join(projectRoot, ".env");

  if (existsSync(envPath)) {
    const fromEnv = readEnvValue(readFileSync(envPath, "utf8"), "APP_URL");

    if (fromEnv !== null && fromEnv !== "") return fromEnv;
  }

  return "http://localhost:8180";
}

export async function templateVerify(
  options: TemplateVerifyOptions = {},
): Promise<number> {
  heading("agentcms template verify");
  const checks: Check[] = [];
  const push = (name: string, status: CheckStatus, detail: string): void => {
    checks.push({ name, status, detail });
    if (status === "pass") ok(`${name} — ${detail}`);
    else if (status === "skip") warn(`${name} — SKIP: ${detail}`);
    else warn(`${name} — FAIL: ${detail}`);
  };

  const agentcms = findAgentcmsDir();
  const projectRoot = findProjectRoot();
  if (!agentcms || !projectRoot) {
    fail({
      what: "SiteGrowKit のプロジェクトが見つかりません",
      why: ".agentcms/ またはプロジェクトルートを解決できませんでした",
      fix: "SiteGrowKit のプロジェクト内で実行してください",
    });
    return 1;
  }

  // ---- 1. Manifest / Plan / Apply record の整合 ----
  const manifestPath = join(agentcms, "manifest", "template.json");
  const planPath = join(agentcms, "manifest", "template-plan.json");
  const recordPath = join(agentcms, "manifest", "template-apply.json");
  if (!existsSync(manifestPath) || !existsSync(planPath)) {
    fail({
      what: "Manifest / Plan がありません",
      why: "template inspect / plan が未実行です",
      fix: "`agentcms template inspect <dir>` → `agentcms template plan` を実行してください",
    });
    return 1;
  }
  const manifest = JSON.parse(readFileSync(manifestPath, "utf8")) as Manifest;
  const planJson = readFileSync(planPath, "utf8");
  const plan = JSON.parse(planJson) as TemplatePlan;
  const record = loadApplyRecord(recordPath);

  /*
   * 古い形式の Plan は**読めるが、新しくは作らない**（R-13 Stage 2）。
   *
   * kit に commit されている plan は Phase 5 の provenance で、作り直すと
   * **当時の取り込み結果と食い違う**（実測: 再生成すると `index.html` の割当が変わる）。
   * だから検査は止めずに読む（`importer/seeds.ts` が両方の形を読む）。
   * ただし**黙って読まない** — 形が古いことは言う。
   */
  if (plan.version !== PLAN_VERSION) {
    push(
      "Plan の形式",
      "skip",
      `v${String(plan.version)} の記録として読みました（現在の形式は v${PLAN_VERSION}）。新しく作り直すときは \`agentcms template plan\``,
    );
  }

  push(
    "Plan ↔ Manifest",
    plan.manifest_fingerprint === manifest.fingerprint.total ? "pass" : "fail",
    plan.manifest_fingerprint === manifest.fingerprint.total
      ? `fingerprint 一致 (${manifest.fingerprint.total.slice(0, 12)}…)`
      : "Plan が古い Manifest を参照（template plan を再実行）",
  );

  // ---- 2. Source fingerprint（inspect 後に元テンプレートが変わっていないか） ----
  const sourceDir = resolve(plan.source);
  if (existsSync(sourceDir)) {
    const current = fingerprintSource(
      sourceDir,
      manifest.fingerprint.files.map((f) => f.path),
    );
    push(
      "Source fingerprint",
      current.total === manifest.fingerprint.total ? "pass" : "fail",
      current.total === manifest.fingerprint.total
        ? "source は inspect 時から未変更"
        : "source が変更されている（re-inspect / replan が必要）",
    );
  } else {
    push("Source fingerprint", "skip", `${plan.source} が見つからない`);
  }

  // ---- 3. Apply record と生成物 ----
  if (!record) {
    push("Apply record", "fail", "apply が未実行（template apply を実行）");
  } else {
    push(
      "Apply record ↔ Manifest",
      record.manifest_fingerprint === manifest.fingerprint.total
        ? "pass"
        : "fail",
      record.manifest_fingerprint === manifest.fingerprint.total
        ? "apply は現在の Manifest に基づく"
        : "apply 後に Manifest が変わっている（re-apply を検討）",
    );
    /*
     * 生成物は kit の改訂で**正しく変わっていく**（SEO タグ・パンくず・ブランド中立化）。
     * 「apply 時のまま」を赤で見張り続けると**常に赤い検査**になり、いずれ誰も見なくなる。
     * だから見るのは **「記録に無い変更が無いこと」**（B-2 / (c-1)）。
     */
    const edits = loadTemplateEdits(agentcms);
    const state = classifyGenerated(record.files, edits, (target) => {
      const abs = join(projectRoot, target);

      return existsSync(abs) ? hashContent(readFileSync(abs)) : null;
    });

    const problems = [
      ...state.unknown.map((f) => `記録に無い変更: ${f.target}`),
      ...state.missing.map((t) => `記録に無い欠落: ${t}`),
      ...state.contradictions.map((t) => `配らないと決めたのに在る: ${t}`),
    ];

    // **黙って緑にしない。** 手が入っていること自体は、緑のときも見せる
    const noted = [
      state.approved.length > 0
        ? `${state.approved.length} 件は手直しとして記録済み`
        : "",
      state.removed.length > 0
        ? `${state.removed.length} 件は配らないと決めた`
        : "",
    ].filter(Boolean);

    push(
      "生成ファイル",
      problems.length === 0 ? "pass" : "fail",
      problems.length === 0
        ? [
            `${record.files.length} ファイル: 記録に無い変更なし`,
            ...noted,
          ].join(" / ")
        : problems.join(", "),
    );

    if (state.unknown.length > 0) {
      // **道具を増やさない。** そのまま `template-edits.json` へ貼れる形で出す
      info(
        "  手で直したものなら、理由を添えて .agentcms/manifest/template-edits.json へ:",
      );
      for (const f of state.unknown) {
        info(
          `    { "target": "${f.target}", "sha256": "${f.sha256}", "reason": "…" }`,
        );
      }
    }
  }

  // ---- 4. Schema 整合（seed カテゴリが choices に存在するか 等） ----
  const worksSchemaPath = join(projectRoot, "content", "schemas", "works.json");
  if (existsSync(worksSchemaPath) && cardRows(plan).length > 0) {
    const schema = JSON.parse(
      readFileSync(worksSchemaPath, "utf8"),
    ) as ContentSchema;
    /*
     * **見るのは基底 schema だけ**（F-42）。
     *
     * 一度は overlay も読むようにしたが、それは誤りだった。
     * `content/schemas/overrides/` は **その開発機の状態**であって配布物ではなく
     * （`distribution.ts` が配布から外している）、読むと**この 1 台でだけ緑**になる。
     * 検査が答えるべきは「**配るものが揃っているか**」である。
     */
    const choices =
      schema.fields.find((f) => f.key === "category")?.choices ?? [];

    /*
     * **配るものどうしを比べる**（F-42）。
     *
     * 比べる相手は plan の seed ではなく、**いま配っている seeder の値**である。
     * plan は**由来の記録**であって現物の正本ではない（記録は書き換えないまま、
     * 読む相手を変える）。実際、恒常的な赤の正体は「seed が古い」だった。
     */
    const seederPath = join(
      projectRoot,
      "database",
      "seeders",
      "TemplateContentSeeder.php",
    );
    const seeded = existsSync(seederPath)
      ? seededCategories(readFileSync(seederPath, "utf8"))
      : cardRows(plan).map((w) => w.category);

    // category 概念が原デザインに無い場合（"" — 承認済み判断）は choices 照合の対象外
    const declared = [...new Set(seeded)].filter(Boolean);
    const missing = declared.filter((c) => !choices.includes(c));
    /*
     * **空振りを緑と読まない**（F-43）。
     *
     * seeder から 1 件も読めなかったときに「不足なし」と言うと、
     * **網が 1 枚も無い状態で緑**になる。読めなかったことは、読めたことではない。
     */
    if (declared.length === 0) {
      push(
        "Works schema choices",
        "skip",
        "seeder から category を 1 件も読めませんでした（この主張は測れていません）",
      );
    } else {
      push(
        "Works schema choices",
        missing.length === 0 ? "pass" : "fail",
        missing.length === 0
          ? `seed カテゴリ ${declared.length} 種はすべて schema choices に存在`
          : `不足: ${missing.join(", ")}`,
      );
    }
  }

  // ---- 5. Assets ----
  const removedTargets = new Set(
    loadTemplateEdits(agentcms).removed.map((e) => e.target),
  );
  // **配らないと決めたものは「欠落」ではない**（記録は足す、書き換えない）
  const missingAssets = plan.assets.static.filter(
    (a) => !removedTargets.has(a.to) && !existsSync(join(projectRoot, a.to)),
  );
  push(
    "Static assets",
    missingAssets.length === 0 ? "pass" : "fail",
    missingAssets.length === 0
      ? `${plan.assets.static.length} ファイル配置済み`
      : `欠落: ${missingAssets.map((a) => a.to).join(", ")}`,
  );

  // ---- 6. HTTP 検証（起動中の環境が必要） ----
  const baseUrl = httpTarget(projectRoot);
  /*
   * **「繋がらなかった」と「繋ぎに行かないと決めた」を混ぜない。**
   * 同じ SKIP でも理由が違う。理由文が実際と違うと、読んだ人は
   * 起動していない環境を疑って時間を使う（F-26 型の誤った注記）。
   */
  if (options.offline) {
    push(
      "HTTP 検証",
      "skip",
      "--offline のため接続しません（記録との一致だけを検証しました）",
    );
  } else if (!(await fetchOk(`${baseUrl}/healthz`))) {
    push(
      "HTTP 検証",
      "skip",
      `${baseUrl} に接続できない（\`agentcms dev\` で起動後に再実行してください）`,
    );
  } else {
    await httpChecks(baseUrl, plan, push);
  }

  // ---- 結果 ----
  console.log("");
  const failed = checks.filter((c) => c.status === "fail");
  const skipped = checks.filter((c) => c.status === "skip");
  if (failed.length === 0) {
    ok(
      `template verify: ${checks.length - skipped.length} 検査 PASS${skipped.length > 0 ? ` / ${skipped.length} SKIP` : ""}`,
    );
    info(`残りの品質ゲート: ${remainingGates(projectRoot).join(" → ")}`);
    return 0;
  }
  fail({
    what: `template verify: ${failed.length} 検査が失敗`,
    why: failed.map((f) => `${f.name}: ${f.detail}`).join(" / "),
    fix: "上記の各検査のメッセージに従って修正してください",
  });
  return 1;
}

async function httpChecks(
  baseUrl: string,
  plan: TemplatePlan,
  push: (name: string, status: CheckStatus, detail: string) => void,
): Promise<void> {
  const seen = new Set<string>();
  const brokenLinks: string[] = [];
  const brokenAssets: string[] = [];

  // css ファイルが無い（inline <style> の）テンプレートでは imported CSS の存在を要求しない
  const hasCssAssets = plan.assets.static.some((a) => a.from.endsWith(".css"));
  const visited = new Set<string>();
  for (const page of plan.pages) {
    if (!page.url) continue;
    // 動的 route（/works/{slug} 等）は seed の実 slug で検証する
    // 動的 URL は seed の実 slug で埋める（名前付き route の有無に依らない）
    const urls = page.url.includes("{slug}")
      ? cardRows(plan).map((w) => page.url!.replace("{slug}", w.slug))
      : [page.url];
    for (const url of urls) {
      if (visited.has(url)) continue;
      visited.add(url);
      const res = await fetchText(`${baseUrl}${url}`);
      if (!res) {
        push(`ページ ${url}`, "fail", "応答がありません");
        continue;
      }
      // コメントアウトされた原本コードを broken link 扱いしない（Preserve First で保持される）
      const scanned = res.replace(/<!--[\s\S]*?-->/g, "");
      const styled = hasCssAssets
        ? scanned.includes("imported/css/")
        : /<style[\s>]/.test(scanned);
      push(
        `ページ ${url}`,
        styled ? "pass" : "fail",
        styled
          ? "200 + imported スタイルで表示"
          : hasCssAssets
            ? "200 だが imported CSS が読み込まれていない"
            : "200 だが inline <style> が見つからない",
      );

      // broken links / assets（同一オリジンのみ。asset() の絶対 URL も検査対象にする）
      for (const m of scanned.matchAll(/(?:href|src)="([^"]+)"/g)) {
        let target = m[1] ?? "";
        if (target.startsWith(baseUrl)) target = target.slice(baseUrl.length);
        if (
          !target ||
          target.startsWith("mailto:") ||
          target.startsWith("tel:") ||
          target.startsWith("#") ||
          target.startsWith("data:") ||
          target.includes("{{") ||
          /^(https?:)?\/\//.test(target)
        )
          continue;
        const path = target.split("#")[0] ?? "";
        if (!path || seen.has(path)) continue;
        seen.add(path);
        const full = `${baseUrl}${path.startsWith("/") ? path : `/${path}`}`;
        if (!(await fetchOk(full))) {
          (path.includes("imported/") ? brokenAssets : brokenLinks).push(path);
        }
      }
    }
  }
  push(
    "Broken links",
    brokenLinks.length === 0 ? "pass" : "fail",
    brokenLinks.length === 0
      ? `${seen.size} URL 検査、リンク切れなし`
      : `リンク切れ: ${brokenLinks.join(", ")}`,
  );
  push(
    "Imported assets 到達",
    brokenAssets.length === 0 ? "pass" : "fail",
    brokenAssets.length === 0
      ? "すべて 200"
      : `404: ${brokenAssets.join(", ")}`,
  );

  // Contact form の Pipeline 接続
  const contactPage = plan.pages.find((p) =>
    p.integrations.includes("contact-form"),
  );
  if (contactPage?.url) {
    const html = (await fetchText(`${baseUrl}${contactPage.url}`)) ?? "";
    const wired =
      html.includes('name="_token"') && html.includes("cf-turnstile");
    push(
      "Contact form 接続",
      wired ? "pass" : "fail",
      wired
        ? "CSRF token + Turnstile widget を確認"
        : "CSRF もしくは Turnstile が見つからない",
    );
  }
}

async function fetchOk(url: string): Promise<boolean> {
  try {
    const res = await fetch(url, { redirect: "follow" });
    return res.ok;
  } catch {
    return false;
  }
}

async function fetchText(url: string): Promise<string | null> {
  try {
    const res = await fetch(url, { redirect: "follow" });
    if (!res.ok) return null;
    return await res.text();
  } catch {
    return null;
  }
}
