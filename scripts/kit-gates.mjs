/**
 * kit 側の門をどれだけ回すかの判断（B-19）。**判断だけを持つ。**
 *
 * ## なぜ人に選ばせないのか
 *
 * `agentcms verify` は**利用者のサイトの門**である（`requireProject` で project root を
 * 解決し、その中だけを見る）。kit 自身の試験 — `packages/cli` の vitest・typecheck・lint —
 * はそこに含まれない。含めることもできない（生成プロジェクトに `packages/cli` は無い）。
 *
 * この穴は 2026-08-20 の 1 日で **3 回**すり抜けを起こした:
 *
 * 1. `content.reorder` を catalog へ足したのに MCP の tool 面が 3 commit 欠けていた
 * 2. 配る側の PHP テストが kit の親ディレクトリを覗いていた（`generated project portability`）
 * 3. 型エラーを入れたまま 4 時間気づかなかった（**vitest は型を見ない**）
 *
 * 3 件とも「**門は在ったが、走らせる側が選び間違えた**」である。
 * だから `--kit` のような**選択式は採らない** — 同じ間違いをもう一度許すことになる。
 * 選択そのものを機械へ移す。
 *
 * ## なぜ書き出す側と分けてあるか
 *
 * `release-guard.mjs` と同じ作法。**判断だけを試験できる形**にしておかないと、
 * この門は試されないまま置かれる（F-50 では、分けていなかったせいで
 * **検査が import しただけで対象を書き換える**事故が実際に起きた）。
 */

/**
 * 触り心地（docs/feel-bar.md）に効く場所か。画面・画面へ返す PHP・道筋・門そのもの。
 *
 * 2026-11-05、「UI の触り心地が大事。ずっと言い続けてる」に対して、決まりを足すだけで
 * 一度も良くならなかった。**門で止めないと、次の版で誰かが作り直して黙って戻る。**
 */
const FEEL_PREFIXES = [
  "template/resources/",
  "template/app/",
  "template/routes/",
  "template/tests/e2e/feel/",
  "template/playwright.feel.config.ts",
  "docs/feel-bar.md",
];

const touchesFeel = (path) =>
  FEEL_PREFIXES.some((prefix) => path === prefix || path.startsWith(prefix));

/** `template/` の下か（= 利用者のサイトとして verify が見る範囲） */
const isTemplate = (path) => path === "template" || path.startsWith("template/");

/**
 * 触った場所から、要る門を決める。
 *
 * @param {string[]} changedPaths repo root からの相対パス
 * @returns {{kit: {required: boolean, why: string}, template: {required: boolean, why: string}}}
 */
export function requiredGates(changedPaths) {
  const paths = changedPaths.map((p) => p.trim()).filter((p) => p !== "");
  const touchedTemplate = paths.filter(isTemplate);
  const touchedFeel = paths.filter(touchesFeel);

  return {
    /*
     * **kit 半分は常に要る。**
     *
     * 「`packages/cli` を触っていないなら要らない」と思いたくなるが、実測は逆だった —
     * 上の 3 件のうち **2 件は `template/` だけを触った変更**で kit の試験が赤くなっている
     * （catalog は PHP 側に在り、配る側の PHP テストは kit の検査が見ている）。
     * 26 秒しか掛からないものを、**要否の判断を間違えて省く**理由が無い。
     */
    kit: {
      required: true,
      why: "kit の試験は template/ だけの変更でも赤くなる（実測 2 件）ので常に回す",
    },
    template: {
      required: touchedTemplate.length > 0,
      why:
        touchedTemplate.length > 0
          ? `template/ を ${touchedTemplate.length} 件触った`
          : "template/ を触っていない",
    },
    /*
     * **触り心地の門。** 画面に効く場所を触ったら必ず回す（選ばせない）。
     * ブラウザで実際に押して測るので、`agentcms dev` 済みの相手が要る。
     * 相手が居ないときは**赤**にする（回さずに緑と言わない）。
     */
    feel: {
      required: touchedFeel.length > 0,
      why:
        touchedFeel.length > 0
          ? `画面に効く場所を ${touchedFeel.length} 件触った（docs/feel-bar.md）`
          : "画面に効く場所を触っていない",
    },
  };
}

/**
 * 触り心地の門（docs/feel-bar.md）。`template/` で playwright を回す。
 * 相手は `AGENTCMS_FEEL_PROJECT`（`agentcms create` で作ったプロジェクト・必須）の `.env` の APP_PORT。
 */
export const FEEL_GATE = {
  name: "触り心地（docs/feel-bar.md）",
  how: "cd template && pnpm exec playwright test -c playwright.feel.config.ts（AGENTCMS_FEEL_PROJECT=<agentcms create → dev したプロジェクト>）",
};

/**
 * template 半分で回す門。
 *
 * ## なぜ `template verify` に `--offline` を付けるのか
 *
 * `template verify` の HTTP 検査は `APP_URL`（既定 `http://localhost:8180`）の
 * **そこに居るものを何でも見る**。2026-08-20 に実際、:8180 に居た**別のプロジェクト**の
 * `/works` 404 でこの門が赤くなった（B-20 の実測）。
 *
 * **commit の前に回す門が「たまたま何が動いているか」で色を変えてはいけない。**
 * 常に赤い門はいずれ誰も見なくなる（B-2 の教訓）。
 * HTTP 検査は**相手が分かっている場所**（`agentcms dev` 後の手動 / 隔離 e2e レーン）に残す。
 */
export const TEMPLATE_GATES = [
  {
    name: "agentcms verify",
    args: ["verify"],
  },
  {
    name: "agentcms template verify --offline",
    args: ["template", "verify", "--offline"],
  },
];

/**
 * **何と比べて決めたか**を 1 行で言う。
 *
 * これが無いと、走らせなかった半分の判断を**後から検証できない**
 * （「変更なし」と「基準が違ったので見えなかった」が同じ顔になる）。
 */
export function basisLine(changedPaths) {
  const count = changedPaths.filter((p) => p.trim() !== "").length;

  return `基準: HEAD..作業ツリー（未 commit・untracked を含む） / 変更 ${count} 件`;
}

/**
 * 回さなかった門を**緑と言わせない**ための締め。
 *
 * @param {{name: string, ran: boolean, ok: boolean, how: string}[]} halves
 * @returns {string[]}
 */
export function closingLines(halves) {
  return halves.map((half) => {
    if (!half.ran) {
      return `− ${half.name}: 回していません（要るときは: ${half.how}）`;
    }

    return half.ok ? `✔ ${half.name}` : `✖ ${half.name}`;
  });
}
