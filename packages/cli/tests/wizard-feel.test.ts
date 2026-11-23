/**
 * setup の画面の触り心地（UX 一周 15 周目 T2・T5 / docs/feel-bar.md）。
 *
 * - T2: 「はじめる」「この内容で作成する」を押してから次の画面まで 214〜498ms、見た目が何も変わらなかった
 * - T5: 1366px で「場所を選ぶ」が 2 行に折れた
 *
 * setup の画面はブラウザの門（playwright.feel.config.ts）の外にあるので、ここで形を見る。
 */
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

import { describe, expect, it } from "vitest";

const assets = join(
  dirname(fileURLToPath(import.meta.url)),
  "../src/wizard/assets",
);
const css = readFileSync(join(assets, "styles.css"), "utf8");
const js = readFileSync(join(assets, "app.js"), "utf8");

describe("setup の画面の押した手応え（T2）", () => {
  it("押している間、ボタンが沈んで暗くなる", () => {
    expect(css).toMatch(
      /\.btn:active:not\(:disabled\)\s*\{[^}]*transform:\s*translateY\(1px\)/,
    );
  });

  it("動きを減らす設定では沈まない", () => {
    expect(css).toMatch(
      /prefers-reduced-motion: reduce[\s\S]*\.btn:active:not\(:disabled\)\s*\{\s*transform:\s*none/,
    );
  });

  it("「はじめる」「この内容で作成する」は押した瞬間に送っている印を付ける", () => {
    const set =
      /const BUSY_ACTIONS = new Set\(\[([\s\S]*?)\]\)/.exec(js)?.[1] ?? "";

    expect(set).toContain('"environment-start"');
    expect(set).toContain('"start"');
    expect(js).toContain('setAttribute("aria-busy", "true")');
  });
});

describe("setup の画面の折れ（T5）", () => {
  it("横に並べたボタンは縮めず、文字を折らない", () => {
    expect(css).toMatch(/\.row \.btn\s*\{\s*flex:\s*none;?\s*\}/);
    expect(css).toMatch(/\.btn\s*\{[^}]*white-space:\s*nowrap/);
  });
});

describe("setup のフォルダ名の候補（21 周目 Y3: 初回 11.3 秒、何も出なかった）", () => {
  const html = readFileSync(join(assets, "index.html"), "utf8");
  const server = readFileSync(join(assets, "../server.ts"), "utf8");

  it("返事が遅いときは「読みの辞書を準備しています」を出す", () => {
    expect(html).toMatch(
      /id="projectNameReading"[^>]*role="status"[^>]*hidden/,
    );
    expect(html).toContain("読みの辞書を準備しています");
    expect(js).toMatch(/const READING_NOTICE_MS = 300;/);
    expect(js).toContain("notice.hidden = false");
  });

  it("後から打った方の返事を、先の返事で上書きしない", () => {
    expect(js).toContain(
      "if (seq !== suggestSeq || projectNameTouched) return;",
    );
  });

  it("画面を開いた時点で辞書を先読みする", () => {
    expect(server).toMatch(
      /origin = `http:\/\/\$\{HOST\}:\$\{port\}`;[\s\S]{0,200}void preloadReading\(\);/,
    );
  });
});
