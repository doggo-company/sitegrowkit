/**
 * Stage 1 — 管理画面の「AI 連携」が `docs/AI_AGENTS.md` とずれていないこと。
 *
 * ## なぜ複製が要るのか
 *
 * `docs/` は**生成プロジェクトへ配られない**（`create` が写すのは `template/` だけ）。
 * だから画面は文字列を**持つ**しかない。**複製は許すが、黙ってずれることは許さない。**
 *
 * ## なぜ kit 側（ここ）に置くのか
 *
 * 照合には正本（`docs/`）と複製（`template/`）の**両方**が要る。
 * 正本が在るのは kit だけなので、これは **kit の検査**である。
 * 利用者へ配られる `template/tests/` に置くと、親ディレクトリを覗く PHP テストになり
 * `generated project portability` が落ちる —
 * そして生成プロジェクトでは**永遠に skip される検査**にしかならない。
 *
 * ## ファイル全体ではなく、定数そのものと比べる
 *
 * `readFileSync(...).includes(...)` は**docblock に同じ語が在るだけで緑になる**。
 * それでは「画面が持っている値」を見ていない。ここは
 * **module を読み込んで、export された値と比べる**（F-51 / ccb ②）。
 */
import { readFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

import { describe, expect, it } from "vitest";

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(here, "../../..");

interface AgentTab {
  id: string;
  label: string;
  launch: string;
  mcp: "auto" | "manual";
  note?: string;
}

/**
 * 画面が持つ値を**そのまま**読む。
 *
 * `tsconfig.tests.json` の `rootDir` は packages/cli なので静的 import はできない
 * （kit の外を rootDir に含めると build の形が崩れる）。値が要るだけなので動的に読む。
 */
async function shipped(): Promise<{
  AGENT_TABS: AgentTab[];
  FIRST_PROMPT: string;
  OTHER_AGENTS_NOTE: string;
}> {
  const url = pathToFileURL(
    join(repoRoot, "template/resources/js/lib/ai-setup.ts"),
  ).href;

  return (await import(/* @vite-ignore */ url)) as {
    AGENT_TABS: AgentTab[];
    FIRST_PROMPT: string;
    OTHER_AGENTS_NOTE: string;
  };
}

const docs = (): string =>
  readFileSync(join(repoRoot, "docs/AI_AGENTS.md"), "utf8");

/** 見出しの直後の ``` ブロックを取り出す */
function fencedBlockAfter(markdown: string, heading: string): string {
  const at = markdown.indexOf(heading);

  if (at < 0) return "";

  const open = markdown.indexOf("```", at);
  const start = open < 0 ? -1 : markdown.indexOf("\n", open);
  const close = start < 0 ? -1 : markdown.indexOf("```", start);

  return start < 0 || close < 0 ? "" : markdown.slice(start, close).trim();
}

describe("画面の AI 連携と docs/AI_AGENTS.md", () => {
  it("最初のプロンプトが正本と一字一句同じ", async () => {
    const canonical = fencedBlockAfter(docs(), "## 最初のプロンプト");

    expect(canonical, "正本から最初のプロンプトを取り出せない").not.toBe("");

    // **一部一致ではなく同一。** 行を足された・削られたのも、ここで赤くなる
    expect((await shipped()).FIRST_PROMPT.trim()).toBe(canonical);
  });

  /**
   * **タブの中身も正本に載っていること。**
   *
   * 画面のタブを足したのに docs が 3 つのまま、を防ぐ
   * （Stage 1 で Grok Build を足したときに実際に起きた — F-51）。
   */
  it("どのタブの起動コマンドも正本の表に在る", async () => {
    const rows = docs()
      .split(/\r?\n/)
      .filter((l) => l.trimStart().startsWith("|"));

    for (const tab of (await shipped()).AGENT_TABS) {
      const row = rows.find((l) => l.includes(tab.label));

      expect(row, `正本の表に ${tab.label} の行が無い`).toBeTruthy();
      expect(row, `${tab.label} の起動コマンドが正本とずれている`).toContain(
        `\`${tab.launch}\``,
      );
    }
  });

  /**
   * **「自動生成の対象でない」と「つながらない」を混同させない断りが、両方に在る。**
   *
   * 画面だけが言っていると、docs を読んだ人には根拠が無い。
   * 正本には**確認した日と出典**も置く（外部の仕様は変わるため）。
   */
  it("Grok Build の但し書きが画面と正本の両方に在る", async () => {
    const grok = (await shipped()).AGENT_TABS.find(
      (t) => t.id === "grok-build",
    );

    expect(grok?.note ?? "", "画面の Grok Build に但し書きが無い").toContain(
      "そのまま",
    );

    const md = docs();

    expect(md, "正本に Grok Build の但し書きが無い").toContain(
      "そのまま使えます",
    );
    expect(md, "外部の事実を書きながら、確認先が書かれていない").toContain(
      "docs.x.ai",
    );
  });

  it("タブに無いエージェントを使えないことにしない、が両方に在る", async () => {
    expect((await shipped()).OTHER_AGENTS_NOTE).toContain("MCP");
    expect(docs()).toContain("MCP に対応していれば同じようにつなげます");
  });
});
