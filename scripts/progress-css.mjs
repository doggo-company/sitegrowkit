/**
 * 進捗バーの CSS を上流から組み立てる（F-50 / B-24）。**判断と組み立てだけを持つ。**
 *
 * ## なぜ書き出す側と分けてあるか
 *
 * ここを `regenerate-progress-css.mjs`（書き出す側）と一緒にしていると、
 * **検査が import しただけで `progress.css` が上書きされる** — つまり
 * 「ずれていないか」を見るはずの検査が、見る前に**ずれを消してしまう**。
 * 実際に一度そうなった。**門が門でなくなる形**なので、読むだけの側をここへ切り出す。
 *
 * `release-guard.mjs` と同じ作法である（判断だけを試験できる形にしておく）。
 */

/**
 * 上流の `injectCSS()` の template を取り出し、うちの値を差し込む。
 *
 * 評価はしない。**差し込み口を明示的に 2 つだけ**にしておくと、
 * 上流が 3 つ目を足したときに「残った `${…}`」として現れる。
 *
 * @param {string} source `@inertiajs/core/dist/index.js` の中身
 * @param {{selector: string, color: string}} values
 * @returns {string} CSS 本体（前後の空白を落としたもの）
 */
export function renderUpstreamCss(source, { selector, color }) {
  const at = source.indexOf("var injectCSS");

  if (at < 0) {
    throw new Error("上流に injectCSS がありません（実装が変わった可能性）");
  }

  const marker = "element.textContent = ";
  const open = source.indexOf(`${marker}\``, at);

  if (open < 0) {
    throw new Error("injectCSS の template を見つけられません");
  }

  const start = open + marker.length + 1;
  const end = source.indexOf("`;", start);

  if (end < 0) {
    throw new Error("injectCSS の template が閉じていません");
  }

  const css = source
    .slice(start, end)
    .replaceAll("${baseComponentSelector}", selector)
    .replaceAll("${color}", color);

  if (css.includes("${")) {
    throw new Error(
      "上流が新しい差し込み口を足しました。scripts/progress-css.mjs を直してから作り直してください",
    );
  }

  return css.trim();
}

/** 上流が使う id。ここが変わると **CSS だけ直しても当たらない** */
export function upstreamSelector(source) {
  return source.match(/var baseComponentSelector = "([^"]+)"/)?.[1];
}

/** `resources/js/app.ts` が渡している色（**片方だけ変えたら赤**になるための出所） */
export function configuredColor(appTsSource) {
  return appTsSource.match(/progress:\s*\{[\s\S]*?color:\s*'([^']+)'/)?.[1];
}

/** 配る stylesheet の中身そのもの（見出しを含む・これと 1 バイトも違わないことを検査する） */
export function buildProgressCss(source, { selector, color }) {
  const header = [
    "/*",
    " * Inertia の進捗バーの CSS（F-50 / B-24）。",
    " *",
    " * ## なぜ自前で持つのか",
    " *",
    " * @inertiajs/core は既定でこの CSS を **<style> 要素として実行時に差し込む**。",
    " * 管理画面の CSP は style-src で inline を許していないので、**毎頁 1 件ブロックされていた**。",
    " * nonce を DOM へ晒す道もあるが、それは「インラインを許さない」という守りの形を一段崩す。",
    " * だから progress: { includeCSS: false }（resources/js/app.ts）で差し込みを止め、",
    " * **同じ CSS を Vite 経由（= 'self'）で配る**。",
    " *",
    " * ## 触るときは",
    " *",
    " * **中身は上流が組み立てる文字列と一字一句同じ**でなければならない。手で直さない。",
    " * 上流が変われば packages/cli/tests/inertia-progress-css.test.ts が赤くなるので、",
    " * `node scripts/regenerate-progress-css.mjs` で作り直す。",
    " */",
    "",
  ].join("\n");

  return `${header}${renderUpstreamCss(source, { selector, color })}\n`;
}
