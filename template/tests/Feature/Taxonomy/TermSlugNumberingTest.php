<?php

declare(strict_types=1);

namespace Tests\Feature\Taxonomy;

use AgentCms\Core\Taxonomy\TaxonomyId;
use AgentCms\Core\Taxonomy\TermSlug;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 日本語の項目名から作る綴りを、**読める通し番号**にする
 * （WP 一周 6 周目 2026-09-03）。
 *
 * ## 何が出ていたか
 *
 * 分類「診療科目」に「予防接種」を足すと、綴りが `term-a2ywwcsg` になった。
 * `Str::slug('予防接種')` が空を返すので、ULID の下 8 桁へ倒していたためである。
 *
 * いまは公開側に出ていないので実害は無いが、
 * **分類ごとの一覧ページを作った瞬間、これがそのまま URL になる**。
 * `/blog/shinryo-kamoku/term-a2ywwcsg` は案件で配れない。
 *
 * 製品は同じ問題を 2 度、**読める形**で解いている:
 * 投稿の URL は日付から（`2026-09-03-1`・PR #7）、ページは型名から（`page`）。
 * 分類の項目だけが乱数のまま残っていた。
 *
 * ## オーナー決定に触れている
 *
 * `SPEC §F` の F-1／F-2 は「空になったら **ULID 由来の一意な fallback**」と決めている。
 * ここはその**綴りの形だけ**を変える（`term-<ULID 8 桁>` → `term-<通し番号>`）。
 * 「一意であること」「意味を持たないこと」「あとから変えられること」は変えていない。
 * 変えた理由は上のとおり — **URL になったときに配れるか**である。
 */
final class TermSlugNumberingTest extends TestCase
{
    use RefreshDatabase;

    public function test_日本語の名前は読める通し番号になる(): void
    {
        $taxonomy = $this->taxonomy();

        $this->assertSame('term-1', TermSlug::numberedFallback($taxonomy));
    }

    public function test_使われている番号は飛ばす(): void
    {
        $taxonomy = $this->taxonomy();

        $this->term($taxonomy, '予防接種', 'term-1');
        $this->term($taxonomy, '歯科', 'term-2');

        $this->assertSame('term-3', TermSlug::numberedFallback($taxonomy));
    }

    /** 番号は**分類ごと**に数える（別の分類の番号に引きずられない） */
    public function test_番号は分類ごとに数える(): void
    {
        $first = $this->taxonomy('shinryo_kamoku');
        $second = $this->taxonomy('chiiki');

        $this->term($first, '予防接種', 'term-1');

        $this->assertSame('term-1', TermSlug::numberedFallback($second));
    }

    /**
     * 🔴 **アーカイブ済みも数に入れる。**
     *
     * archive は綴りを解放しない（`isTaken` と同じ扱い）。
     * 解放すると、戻したときに 2 つが同じ綴りになる。
     */
    public function test_アーカイブ済みの番号も飛ばす(): void
    {
        $taxonomy = $this->taxonomy();

        $this->term($taxonomy, 'やめた科目', 'term-1', archived: true);

        $this->assertSame('term-2', TermSlug::numberedFallback($taxonomy));
    }

    /** 英字の名前からは、これまでどおり読める綴りを作る */
    public function test_英字の名前は名前から作る(): void
    {
        $this->assertSame(
            'press-release',
            TermSlug::generate('Press Release', TaxonomyId::generate()),
        );
    }

    /** 自動で付けた綴りだと分かる（画面が「後から変えられます」と言うため） */
    public function test_通し番号も自動生成として扱う(): void
    {
        $this->assertTrue(TermSlug::isGenerated('term-1'));
        $this->assertTrue(TermSlug::isGenerated('term-12'));
        $this->assertFalse(TermSlug::isGenerated('press-release'));
    }

    /**
     * 🔴 **昔のデータを「人が付けた綴り」に見せない。**
     *
     * 既に `term-a2ywwcsg` で保存されている項目がある。
     * ここが false になると、画面が「（自動生成）」と言わなくなる。
     */
    public function test_昔のulid由来の綴りも自動生成のまま(): void
    {
        $this->assertTrue(TermSlug::isGenerated('term-a2ywwcsg'));
    }

    // ── ヘルパー ────────────────────────────────────────────

    private function taxonomy(string $key = 'shinryo_kamoku'): string
    {
        $id = TaxonomyId::generate();

        DB::table('taxonomies')->insert([
            'id' => $id,
            'key' => $key,
            'label' => '診療科目',
            'hierarchical' => false,
            'status' => 'active',
            'managed_by' => 'cms',
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function term(string $taxonomyId, string $name, string $slug, bool $archived = false): void
    {
        DB::table('taxonomy_terms')->insert([
            'id' => TaxonomyId::generate(),
            'taxonomy_id' => $taxonomyId,
            'name' => $name,
            'slug' => $slug,
            'sort_order' => 0,
            'archived_at' => $archived ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
