/**
 * 進捗バーの CSS を上流から作り直す（F-50 / B-24）。**書き出すだけ。**
 *
 * ## なぜ手で書き写さないのか
 *
 * `template/resources/css/progress.css` は `@inertiajs/core` が実行時に差し込む CSS の
 * **肩代わり**である。管理画面の CSP は inline style を許さないので、差し込みを止めて
 * 同じものを Vite 経由（= `'self'`）で配っている。
 *
 * 肩代わりした以上、**上流が変わったら追従する**義務がある。その追従を手作業にすると
 * 「1 行だけ違う」を見落とす。だから**上流の template をそのまま取り出して組み立てる**。
 *
 * 組み立て方そのものは `progress-css.mjs` が持つ（**検査が import しても何も書かない**ため）。
 *
 * ## 使い方（repo root で）
 *
 * ```
 * node scripts/regenerate-progress-css.mjs
 * ```
 *
 * ずれているかどうかは `packages/cli/tests/inertia-progress-css.test.ts` が言う。
 * これは**言われてから**走らせるもので、定期的に流すものではない。
 */
import { readFileSync, writeFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

import {
  buildProgressCss,
  configuredColor,
  upstreamSelector,
} from "./progress-css.mjs";

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const upstream = join(
  repoRoot,
  "template/node_modules/@inertiajs/core/dist/index.js",
);
const target = join(repoRoot, "template/resources/css/progress.css");
const appTs = join(repoRoot, "template/resources/js/app.ts");

const die = (message) => {
  console.error(`✖ ${message}`);
  process.exit(1);
};

const source = readFileSync(upstream, "utf8");
const selector = upstreamSelector(source);
const color = configuredColor(readFileSync(appTs, "utf8"));

if (!selector) die("上流の baseComponentSelector を読めません");
if (!color) die("resources/js/app.ts から進捗バーの色を読めません");

writeFileSync(target, buildProgressCss(source, { selector, color }));

console.log(
  `✔ ${target} を作り直しました（selector=${selector} / color=${color}）`,
);
