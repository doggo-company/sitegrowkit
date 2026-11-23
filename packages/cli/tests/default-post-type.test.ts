import { describe, expect, it } from "vitest";
import { existsSync, readFileSync } from "node:fs";
import { join } from "node:path";
import { shouldCopyTemplatePath } from "../src/commands/create.js";

/**
 * 新しく生成するサイトは **汎用の「投稿」1 型だけ**を持つ
 * （PHASE 9.7 / STAGE 20B・SPEC §C・Owner Decision OG-A = (b)）。
 *
 * `news` / `works` / `product` は kit の中に**例・検証資産として残す**が、
 * 利用者の project へは配らない。既に生成済みの project からは何も消さない
 * （撤去は配布境界の変更であって migration ではない）。
 */

const templateDir = join(import.meta.dirname, "..", "..", "..", "template");

describe("既定の投稿タイプ（STAGE 20B）", () => {
  it("news / works / product を新規 project へ配らない", () => {
    for (const rel of [
      "content/schemas/news.json",
      "content/schemas/works.json",
      "content/schemas/product.json",
      "content/taxonomies/news_category.json",
      "modules/News/Models/News.php",
      "modules/News/views/index.blade.php",
      "modules/News/database/migrations/2026_08_08_100001_create_news_table.php",
      "modules/Works/Models/Work.php",
      "modules/Works/Http/WorksController.php",
      "modules/Products/Models/Product.php",
      "modules/Products/database/migrations/2026_08_14_000001_create_products_table.php",
    ]) {
      expect(shouldCopyTemplatePath(rel), `${rel} は配らないはず`).toBe(false);
    }
  });

  it("汎用の「投稿」型は配る", () => {
    for (const rel of [
      "content/schemas/post.json",
      "modules/Post/Models/Post.php",
      "modules/Post/database/migrations/2026_08_15_000030_create_post_table.php",
    ]) {
      expect(shouldCopyTemplatePath(rel), `${rel} は配るはず`).toBe(true);
    }
  });

  it("system facility と core は配り続ける", () => {
    for (const rel of [
      "content/schemas/media.json",
      "content/schemas/contact.json",
      "content/schemas/site.json",
      "modules/Media/Models/Media.php",
      "modules/Contact/Models/ContactSubmission.php",
      "modules/Notifications/Notifier.php",
      "modules/Site/Http/SiteSettingsController.php",
      "core/src/Schema/SchemaRegistry.php",
    ]) {
      expect(shouldCopyTemplatePath(rel), `${rel} は配るはず`).toBe(true);
    }
  });

  it("kit の中には例と検証資産が残っている", () => {
    for (const rel of [
      "modules/News/Models/News.php",
      "modules/Works/Models/Work.php",
      "content/schemas/news.json",
      "tests/e2e/template-visual.spec.ts",
    ]) {
      expect(
        existsSync(join(templateDir, rel)),
        `${rel} は kit から消さない`,
      ).toBe(true);
    }
  });

  it("「投稿」の schema は仕様どおりの形をしている", () => {
    const schema = JSON.parse(
      readFileSync(join(templateDir, "content/schemas/post.json"), "utf8"),
    );

    expect(schema.id).toBe("post");
    expect(schema.label).toBe("投稿");
    expect(schema.kind).toBe("collection");
    // 利用者が編集・無効化できる側に置く（R1 の「汎用」）
    expect(schema.managedBy).toBe("cms");
    expect(schema.fields.map((f: { key: string }) => f.key)).toEqual([
      "title",
      "slug",
      // 標準の投稿で画像と一覧用の紹介文を編集できる。
      "main_image_id",
      "excerpt",
      "body",
      "status",
      "published_at",
    ]);
    // news_category は news 専用。汎用の型に押しつけない
    expect(schema.taxonomies ?? []).toEqual([]);
  });
});
