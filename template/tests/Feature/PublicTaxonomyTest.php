<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 記事に付けた分類が、**訪問者にも見える**（WP 一周 6 周目 2026-09-03 / 効くもの ②）。
 *
 * ## 何が起きていたか
 *
 * 分類管理で分類を作り、項目を足し、投稿タイプへ関連付け、記事の編集画面で
 * チェックを入れて公開する — **ここまで気持ちよく通る**。
 * ところが公開ページを見ると、記事にも一覧にも**分類の名前がどこにも無い**。
 *
 * ```
 * 狂犬病予防注射のご案内  2026.09.03  投稿一覧へ戻る
 * ```
 *
 * `resources/views` に `taxonomy` / `terms` を含むファイルは 0 件、
 * 公開の route（193 本）にも分類の入口が 1 つも無かった。
 * **管理画面の作り込みが、訪問者からは丸ごと存在しない。**
 *
 * 案件でいちばん普通の要望（「お知らせをカテゴリで分けたい」）が、
 * **画面上はできたように見えて、できない。**
 *
 * ## ここで固定すること
 *
 * まず**記事に名前を出す**。分類ごとの一覧ページは次の PR で足す
 * （route が増えるので、1 件ずつ確かめて進む）。
 */
final class PublicTaxonomyTest extends TestCase
{
    use RefreshDatabase;

    public function test_記事に付けた分類の名前が公開ページに出る(): void
    {
        $this->publishType();
        $term = $this->taxonomyWithTerm('診療科目', '予防接種');
        $slug = $this->publishedPost();

        $this->assign($term, 1);

        $this->get("/blog/{$slug}")
            ->assertOk()
            ->assertSee('診療科目')
            ->assertSee('予防接種');
    }

    /** 同じ分類に 2 つ付いていれば、2 つとも出る */
    public function test_複数の項目が付いていれば全部出る(): void
    {
        $this->publishType();
        $first = $this->taxonomyWithTerm('診療科目', '予防接種');
        $second = $this->addTerm($first['taxonomy'], '歯科');
        $slug = $this->publishedPost();

        $this->assign($first, 1);
        $this->assign(['term' => $second], 1);

        $this->get("/blog/{$slug}")
            ->assertSee('予防接種')
            ->assertSee('歯科');
    }

    /**
     * 🔴 **アーカイブした項目は、公開面から消える。**
     *
     * 管理側は「付いていたものを黙って落とさない」ために残して見せるが、
     * 訪問者に見せるのは**いま使っているもの**だけである。
     */
    public function test_アーカイブした項目は出ない(): void
    {
        $this->publishType();
        $term = $this->taxonomyWithTerm('診療科目', 'やめた科目');
        $slug = $this->publishedPost();

        $this->assign($term, 1);

        DB::table('taxonomy_terms')->where('id', $term['term'])->update(['archived_at' => now()]);

        $this->get("/blog/{$slug}")->assertDontSee('やめた科目');
    }

    /** 無効にした分類は、丸ごと出ない */
    public function test_無効にした分類は出ない(): void
    {
        $this->publishType();
        $term = $this->taxonomyWithTerm('診療科目', '予防接種');
        $slug = $this->publishedPost();

        $this->assign($term, 1);

        DB::table('taxonomies')->where('id', $term['taxonomy'])->update(['status' => 'disabled']);

        $this->get("/blog/{$slug}")
            ->assertDontSee('診療科目')
            ->assertDontSee('予防接種');
    }

    /** 付いていない記事に、空の見出しを出さない */
    public function test_付いていなければ見出しごと出ない(): void
    {
        $this->publishType();
        $this->taxonomyWithTerm('診療科目', '予防接種');
        $slug = $this->publishedPost();

        $this->get("/blog/{$slug}")->assertDontSee('診療科目');
    }

    /** 名前は必ずエスケープする（分類名は人が入れる） */
    public function test_名前のhtmlは効かない(): void
    {
        $this->publishType();
        $term = $this->taxonomyWithTerm('診療科目', '<script>alert(1)</script>');
        $slug = $this->publishedPost();

        $this->assign($term, 1);

        $this->get("/blog/{$slug}")->assertDontSee('<script>alert(1)</script>', false);
    }

    // ── ヘルパー ────────────────────────────────────────────

    private function publishType(): void
    {
        DB::table('content_types')->updateOrInsert(
            ['content_type_id' => 'post'],
            [
                'status' => 'active',
                'lifecycle' => 'ready',
                'public_slug' => 'blog',
                'registration' => json_encode(['public' => true], JSON_UNESCAPED_UNICODE),
                'lock_version' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        app()->forgetScopedInstances();
    }

    /** @return array{taxonomy: string, term: string} */
    private function taxonomyWithTerm(string $label, string $name): array
    {
        $taxonomyId = (string) Str::ulid();

        DB::table('taxonomies')->insert([
            'id' => $taxonomyId,
            'key' => 'shinryo_kamoku',
            'label' => $label,
            'hierarchical' => false,
            'status' => 'active',
            'managed_by' => 'cms',
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['taxonomy' => $taxonomyId, 'term' => $this->addTerm($taxonomyId, $name)];
    }

    private function addTerm(string $taxonomyId, string $name, int $order = 0): string
    {
        $id = (string) Str::ulid();

        DB::table('taxonomy_terms')->insert([
            'id' => $id,
            'taxonomy_id' => $taxonomyId,
            'name' => $name,
            'slug' => 'term-'.strtolower(substr($id, -8)),
            'sort_order' => $order,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** @param  array{taxonomy?: string, term: string}  $term */
    private function assign(array $term, int $contentId): void
    {
        DB::table('taxonomy_term_assignments')->insert([
            'term_id' => $term['term'],
            'content_type' => 'post',
            'content_id' => $contentId,
            'sort_order' => 0,
            'created_at' => now(),
        ]);
    }

    private function publishedPost(string $slug = 'oshirase'): string
    {
        DB::table('post')->insert([
            'title' => 'お知らせ',
            'slug' => $slug,
            'body' => '<p>本文</p>',
            'status' => 'published',
            'published_at' => now()->subDay(),
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $slug;
    }
}
