import { describe, expect, it } from "vitest";

import { buildImportedCollection } from "../src/importer/imported-collection.js";

/**
 * CMS 連携済みの節を、意匠を保ったまま一覧の部品にする（NEW-2）。
 *
 * ここで固定するのは 4 つ:
 *   1. **意匠に触らない**（class・入れ子・まわりの markup がそのまま）
 *   2. **ループの綴りだけ書き換える**（中身は binding から来る）
 *   3. **関連と日付は、来る形に合わせて解く**
 *   4. **`@foreach` が無いなら組まない**
 */
const NEWS = `<section class="section news" id="news">
  <div class="section-header"><h2 class="section-title">お知らせ</h2></div>
  <div class="news-list">
@foreach ($news as $item)
  <a href="/news" class="news-item">
    <div class="news-date">{{ $item->published_at?->format('Y.m.d') }}</div>
    <div class="news-title">{{ $item->title }}</div>
  </a>
@endforeach
  </div>
</section>`;

const WORKS = `<section id="gallery">
@foreach ($works as $work)
  <article class="works-slide">
    <span class="works-num">{{ sprintf('%02d', $loop->iteration) }}</span>
    <h3>{{ $work->title }}</h3>
    <a href="/works/{{ $work->slug }}">詳しく見る</a>
    <img src="{{ $work->mainImage?->url() }}" alt="{{ $work->title }}">
  </article>
@endforeach
</section>`;

const build = (html: string, key = "news") =>
  buildImportedCollection(key, `${key}（一覧）`, html);

describe("buildImportedCollection", () => {
  // ── 1. 意匠に触らない ────────────────────────────────

  it("まわりの markup をそのまま持つ", () => {
    const v = build(NEWS)!.view;

    expect(v).toContain(`<section class="section news" id="news">`);
    expect(v).toContain(`<h2 class="section-title">お知らせ</h2>`);
    expect(v).toContain(`<div class="news-list">`);
  });

  it("ループの中の class も動かさない", () => {
    const v = build(WORKS, "gallery")!.view;

    expect(v).toContain(`<article class="works-slide">`);
    expect(v).toContain(`class="works-num"`);
  });

  // ── 2. ループの綴りだけ書き換える ────────────────────

  it("回す先を items にする", () => {
    const v = build(NEWS)!.view;

    expect(v).toContain("@foreach ($items as $item)");
    expect(v).not.toContain("@foreach ($news as $item)");
  });

  it("項目参照を配列にする", () => {
    // binding が渡すのは配列。矢印のままだと描画時に落ちる
    const v = build(NEWS)!.view;

    expect(v).toContain("$item['title']");
    expect(v).not.toContain("$item->title");
  });

  it("$loop はそのまま残す", () => {
    // 自分の @foreach の中なので、書き換える必要が無い
    expect(build(WORKS, "gallery")!.view).toContain("$loop->iteration");
  });

  it("元の変数名をコンテンツタイプにする", () => {
    // 近い名前へ勝手に寄せない。無ければ CMS が断る
    expect(build(NEWS)!.binding).toEqual({
      source: "collection",
      type: "news",
      sort: [{ field: "published_at", direction: "desc" }],
    });
    expect(build(WORKS, "gallery")!.binding.type).toBe("works");
  });

  it("並び順を原本と揃える", () => {
    // 🔴 書かないと既定の順（登録順）になり、**同じ件数・同じ markup のまま
    //    並びだけ違うもの**が出る。バイト数まで一致するので合計値では気づけない
    expect(build(NEWS)!.binding.sort).toEqual([
      { field: "published_at", direction: "desc" },
    ]);
  });

  // ── 3. 関連と日付 ────────────────────────────────────

  it("関連は id から解く", () => {
    // 一覧が渡すのは `main_image_id` だけ。関連は付いてこない
    const v = build(WORKS, "gallery")!.view;

    expect(v).toContain("Media::find($work['main_image_id'] ?? null)?->url()");
    expect(v).not.toContain("$work->mainImage");
  });

  it("日付は文字列から解く", () => {
    // `published_at` は ISO の文字列で来るので、そのままでは format できない
    const v = build(NEWS)!.view;

    expect(v).toContain("Carbon::parse($item['published_at'] ?? null)?->format('Y.m.d')");
  });

  // ── 4. 組めないなら組まない ──────────────────────────

  it("@foreach が無ければ組まない", () => {
    expect(build(`<section id="a">@if ($x) あ @endif</section>`)).toBeNull();
  });

  // ── 定義 ──────────────────────────────────────────────

  it("一覧であることを宣言する", () => {
    // 🔴 これが無いと中身が渡ってこない。**空で出る**（登録が無いのと見分けが付かない）
    const d = JSON.parse(build(NEWS)!.definition) as {
      capabilities: { collection: boolean };
      allowedParents: string[];
      renderer: { view: string };
    };

    expect(d.capabilities.collection).toBe(true);
    expect(d.allowedParents).toEqual(["page"]);
  });

  it("view の置き場所は定義が指す先と揃う", () => {
    const c = build(NEWS)!;
    const d = JSON.parse(c.definition) as { renderer: { view: string } };

    expect(c.viewName).toBe(d.renderer.view);
  });

  it("件数を変えられる", () => {
    const d = JSON.parse(build(NEWS)!.definition) as {
      propsSchema: Record<string, { default: number; max: number }>;
    };

    expect(d.propsSchema.limit!.default).toBe(6);
    expect(d.propsSchema.limit!.max).toBe(50);
  });
});
