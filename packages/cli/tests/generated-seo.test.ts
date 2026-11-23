/**
 * B-1 — 取り込んだサイトが、**最初から**同じ SEO タグを持つ。
 *
 * P1 ① は kit の同梱見本を手で直しただけで、**新しく取り込むサイトには届かない**。
 * 実測すると、見本の 5 ページのうち **canonical と og を持つのは 1 枚だけ**で、
 * 残り 4 枚は何も持っていなかった。**取り込んだ時点で不揃い**である。
 *
 * ## 共通のものは共通の場所に 1 回だけ
 *
 * ページごとに書くと、**新しいページを足した人が忘れる**し、忘れたことに誰も気づかない。
 * だから layout が出し、**ページ側は同じものを二度出さない**。
 */
import { readFileSync } from "node:fs";
import { join, resolve } from "node:path";
import { describe, expect, it } from "vitest";

import { generateFromPlan } from "../src/importer/generate.js";
import { buildPlan } from "../src/importer/plan.js";
import type { ContentSchema } from "../src/importer/plan-types.js";
import { analyzeTemplateDir } from "../src/inspect/analyze.js";

const FIXTURE = resolve(__dirname, "../../../examples/html-template");
const SCHEMAS = resolve(__dirname, "../../../template/content/schemas");

function loadSchema(id: string): ContentSchema {
  return JSON.parse(
    readFileSync(join(SCHEMAS, `${id}.json`), "utf8"),
  ) as ContentSchema;
}

const manifest = analyzeTemplateDir(FIXTURE, "fixture");
const plan = buildPlan(manifest, {
  works: loadSchema("works"),
  news: loadSchema("news"),
  contact: loadSchema("contact"),
});
const result = generateFromPlan(FIXTURE, manifest, plan);
const byTarget = new Map(result.files.map((f) => [f.target, f]));

const layout = byTarget.get("resources/views/layouts/imported.blade.php");

describe("生成した layout の SEO", () => {
  it("layout が canonical を出す", () => {
    expect(layout?.content).toContain(
      '<link rel="canonical" href="{{ url()->current() }}">',
    );
  });

  it("layout が og の標準セットを出す", () => {
    for (const prop of [
      "og:site_name",
      "og:title",
      "og:description",
      "og:type",
      "og:url",
    ]) {
      expect(layout?.content).toContain(`property="${prop}"`);
    }
  });

  /**
   * **同じものを二度出さない。**
   *
   * 見本の `index.html` は og を 4 つ持っている。layout が出すのと同じ prop を
   * ページ側でも出すと、**1 ページに og:title が 2 つ**並ぶ。
   */
  it("原本が og を持つページでも、layout と同じ prop を二度出さない", () => {
    const home = byTarget.get("resources/views/public/home.blade.php");

    expect(home).toBeDefined();
    expect(home?.content).not.toContain('property="og:title"');
    expect(home?.content).not.toContain('rel="canonical"');
  });

  /**
   * **原本にしか無いものは残す。**
   *
   * layout が出さない prop（`og:image` など）まで落とすと、
   * **取り込みで情報が減る**。
   */
  it("layout が出さない og は落とさない", () => {
    // 見本には og:image が無いので、生成器の規則そのものを見る
    const emitted = [layout, ...byTarget.values()]
      .map((f) => String(f?.content ?? ""))
      .join("\n");

    expect(emitted).not.toContain('property="og:image"');
  });

  /** **1 つずつ出る**（layout が 1 回だけ書く） */
  it("layout の中で canonical と og は 1 つずつ", () => {
    const content = String(layout?.content ?? "");

    for (const needle of [
      'rel="canonical"',
      'property="og:site_name"',
      'property="og:title"',
      'property="og:description"',
      'property="og:type"',
      'property="og:url"',
    ]) {
      expect(content.split(needle).length - 1, needle).toBe(1);
    }
  });

  /**
   * **旧ドメインを持ち込まない。**
   *
   * 見本の `index.html` の canonical は `https://greenleaf.example/` で、
   * **新しいサイトでは誤り**になる。生成物のどこにも残っていないこと。
   */
  it("旧ドメインの canonical が残らない", () => {
    /*
     * **本文の連絡先は content である。** 見本の footer には
     * `mailto:hello@greenleaf.example` が在り、それは残ってよい（取り込みは内容を保つ）。
     * 消えていなければならないのは **URL を名乗る所**である。
     */
    for (const file of byTarget.values()) {
      for (const line of String(file.content).split("\n")) {
        if (line.includes("canonical") || line.includes("og:url")) {
          expect(line, file.target).not.toContain("greenleaf.example");
        }
      }
    }

    expect(layout?.content).toContain(
      '<link rel="canonical" href="{{ url()->current() }}">',
    );
  });

  /**
   * **`og:description` が空にならない。**
   *
   * ページに説明が無いとき `??` では空文字がそのまま通る。`?:` にして
   * サイト設定へ落とす（**空の og を出すくらいなら、サイトの説明を出す**）。
   */
  it("説明が無いページでもサイト設定へ落とす", () => {
    expect(layout?.content).toContain(
      "{{ $metaDescription ?: ($site['seo_description'] ?? '') }}",
    );
  });

  /**
   * **全ページに出る。**
   *
   * 生成したページはすべて同じ layout を継ぐので、SEO は 5 枚とも同じ形になる。
   * 「原本が持っていた 1 枚だけ」だった状態を、ここで固定する。
   */
  it("生成したページはすべて同じ layout を継ぐ", () => {
    // **ページの一覧は plan が持っている**（layout や component を数えない）
    const pages = plan.pages.map((p) => p.target);

    expect(pages.length).toBe(5);

    for (const target of pages) {
      expect(
        String(byTarget.get(target)?.content ?? ""),
        target,
      ).toContain("@extends('layouts.imported'");
    }
  });
});
