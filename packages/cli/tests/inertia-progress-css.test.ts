/**
 * 進捗バーの CSS が上流とずれていないこと（F-50 / B-24）。
 *
 * ## 何を肩代わりしているのか
 *
 * `@inertiajs/core` は既定でこの CSS を **`<style>` 要素として実行時に差し込む**。
 * 管理画面の CSP（`style-src 'self' 'nonce-…'`）はそれを許さないので、
 * **毎頁 1 件ブロックされていた**。nonce を DOM へ晒せば通るが、それは
 * 「インラインを許さない」という守りの形を一段崩す。だから
 * `includeCSS: false` で差し込みを止め、**同じ CSS を Vite 経由（= `'self'`）で配る**。
 *
 * ## その代わりに負った義務を、ここで赤に変える
 *
 * 肩代わりした以上、**上流が CSS を変えたら追従する義務**が生まれる。
 * 義務を人の記憶に置くと、忘れられた瞬間に**進捗バーだけ静かに壊れる**
 * （CSP には通るので、誰も気づかない）。
 *
 * そこで B-24 の犯人特定に使った手法をそのまま検査にする —
 * **上流の `injectCSS()` の template を取り出し、うちの selector / color で組み立て、
 * 配っている stylesheet と一字一句で比べる。**
 *
 * ## 上流が入っていないとき
 *
 * **skip する。緑にはしない。** ここは上流との照合なので、上流が無ければ測れない。
 * 測れなかったことを緑と読ませない（`template` の依存を入れれば走る）。
 */
import { existsSync, readFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

import { describe, expect, it } from "vitest";

// @ts-expect-error — repo 直下のスクリプト（型定義は持たない。release-guard.test.ts と同じ扱い）
import * as progressCss from "../../../scripts/progress-css.mjs";

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(here, "../../..");

const UPSTREAM = join(
  repoRoot,
  "template/node_modules/@inertiajs/core/dist/index.js",
);
const OURS = join(repoRoot, "template/resources/css/progress.css");
const APP_TS = join(repoRoot, "template/resources/js/app.ts");

const read = (path: string): string => readFileSync(path, "utf8");

const missing = !existsSync(UPSTREAM);

describe("Inertia 進捗バーの CSS を肩代わりしている", () => {
  it("実行時の差し込みを止めている", () => {
    // ここが戻ると、**自前の CSS と二重になり、しかも CSP に弾かれる**
    expect(read(APP_TS)).toContain("includeCSS: false");
  });

  it("配る CSS を app.css が読み込んでいる", () => {
    // 在るだけでは配られない。**bundle に入る道**が要る
    expect(read(join(repoRoot, "template/resources/css/app.css"))).toContain(
      "progress.css",
    );
  });

  it.skipIf(missing)("配っている CSS が上流と一字一句同じ", () => {
    const source = read(UPSTREAM);
    const selector = progressCss.upstreamSelector(source) as
      | string
      | undefined;
    const color = progressCss.configuredColor(read(APP_TS)) as
      | string
      | undefined;

    expect(selector, "上流の baseComponentSelector を読めない").toBeTruthy();
    expect(color, "app.ts から進捗バーの色を読めない").toBeTruthy();

    // **見出しごと**比べる（本文だけ合っていても、由来の説明が消えていたら気づけない）
    expect(
      read(OURS),
      [
        "進捗バーの CSS が上流とずれています。",
        "手で直さず、repo root で次を実行して作り直してください:",
        "  node scripts/regenerate-progress-css.mjs",
      ].join("\n"),
    ).toBe(
      progressCss.buildProgressCss(source, {
        selector: selector ?? "",
        color: color ?? "",
      }) as string,
    );
  });

  it.skipIf(missing)("上流の id が変わっていない", () => {
    // `#nprogress` は CSS 側にも書いてある。変わったら **CSS が当たらなくなる**
    expect(progressCss.upstreamSelector(read(UPSTREAM))).toBe("nprogress");
    expect(read(OURS)).toContain("#nprogress {");
  });
});
