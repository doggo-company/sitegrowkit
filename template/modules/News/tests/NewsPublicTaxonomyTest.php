<?php

declare(strict_types=1);

namespace Modules\News\Tests;

use AgentCms\Core\Application\Content\ReorderContent;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\News\Models\News;
use Tests\TestCase;

/**
 * `news` の記事に付けた分類が、**訪問者にも見えて、押せる**（R05・2026-09-09）。
 *
 * ## 何が起きていたか
 *
 * 汎用の投稿タイプは 6 周目（2026-09-03）で分類の名前と一覧を出したが、
 * `news` は module 専用 route（`/news/{slug}`）で描くので **1 文字も出なかった**。
 * しかも `/news/{分類}/{綴り}` は fallback に届かない（公開スラッグの行が無い型を
 * `ContentPermalink::typeForBase()` は知らない）ので、リンクにしても 404 だった。
 *
 * ## ここで固定すること
 *
 * - 名前が出て、`/news/{分類のキー}/{項目の綴り}` へのリンクになる
 * - その一覧が **200** で、その項目が付いた公開記事だけを並べる
 * - `has_archive` を持たない設定では、名前は出るがリンクにしない（一覧も 404）
 * - 無効な分類・アーカイブ済みの項目は出ない（一覧も 404）
 * - 付いていなければ 1 バイトも出ない
 */
final class NewsPublicTaxonomyTest extends TestCase
{
    use RefreshDatabase;

    public function test_記事の分類が名前とリンクで出る(): void
    {
        $term = $this->taxonomyWithTerm('カテゴリ', 'プレスリリース');
        $news = $this->publishedNews('press-1', 'リリースのお知らせ');
        $this->assign($term['term'], $news);

        $this->get('/news/press-1')
            ->assertOk()
            ->assertSee('カテゴリ')
            ->assertSee('<a class="article__term" href="/news/news_category/term-1">プレスリリース</a>', false);
    }

    public function test_分類の一覧が200でその項目が付いた公開記事だけを並べる(): void
    {
        $term = $this->taxonomyWithTerm('カテゴリ', 'プレスリリース');
        $with = $this->publishedNews('press-1', 'リリースのお知らせ');
        $this->publishedNews('other', '関係ない記事');
        $draft = $this->publishedNews('draft-1', '下書きの記事', published: false);
        $this->assign($term['term'], $with);
        $this->assign($term['term'], $draft);

        $this->get('/news/news_category/term-1')
            ->assertOk()
            ->assertSee('プレスリリース')
            ->assertSee('リリースのお知らせ')
            ->assertSee('/news/press-1', false)
            ->assertDontSee('関係ない記事')
            ->assertDontSee('下書きの記事');
    }

    /** 一覧を持たない設定では、名前は出すがリンクにしない（押せる先が無い） */
    public function test_一覧を持たない設定では文字のまま出す(): void
    {
        $this->setting(['has_archive' => false]);
        $term = $this->taxonomyWithTerm('カテゴリ', 'プレスリリース');
        $this->assign($term['term'], $this->publishedNews('press-1'));

        $this->get('/news/press-1')
            ->assertOk()
            ->assertSee('<span class="article__term">プレスリリース</span>', false)
            ->assertDontSee('/news/news_category/term-1', false);

        $this->get('/news/news_category/term-1')->assertNotFound();
    }

    public function test_アーカイブした項目は出ず一覧も404(): void
    {
        $term = $this->taxonomyWithTerm('カテゴリ', 'やめた分類');
        $this->assign($term['term'], $this->publishedNews('press-1'));

        DB::table('taxonomy_terms')->where('id', $term['term'])->update(['archived_at' => now()]);

        $this->get('/news/press-1')->assertOk()->assertDontSee('やめた分類');
        $this->get('/news/news_category/term-1')->assertNotFound();
    }

    public function test_無効にした分類は出ず一覧も404(): void
    {
        $term = $this->taxonomyWithTerm('カテゴリ', 'プレスリリース');
        $this->assign($term['term'], $this->publishedNews('press-1'));

        DB::table('taxonomies')->where('id', $term['taxonomy'])->update(['status' => 'disabled']);

        $this->get('/news/press-1')->assertOk()->assertDontSee('プレスリリース');
        $this->get('/news/news_category/term-1')->assertNotFound();
    }

    public function test_付いていなければ何も出ない(): void
    {
        $this->taxonomyWithTerm('カテゴリ', 'プレスリリース');
        $this->publishedNews('press-1');

        $this->get('/news/press-1')
            ->assertOk()
            ->assertDontSee('article__taxonomies', false)
            ->assertDontSee('カテゴリ');
    }

    /** 名前は人が入れる。必ずエスケープする */
    public function test_名前のhtmlは効かない(): void
    {
        $term = $this->taxonomyWithTerm('カテゴリ', '<script>alert(1)</script>');
        $this->assign($term['term'], $this->publishedNews('press-1'));

        $this->get('/news/press-1')
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;', false);
    }

    /** 記事の URL はこれまでどおり（分類の route が奪わない） */
    public function test_記事のurlはこれまでどおり(): void
    {
        $this->publishedNews('press-1', 'これは記事');

        $this->get('/news/press-1')->assertOk()->assertSee('これは記事');
        $this->get('/news/news_category/nothing')->assertNotFound();
    }

    public function test_分類の記事が増えても最後までページを送れる(): void
    {
        $term = $this->taxonomyWithTerm('分類', '項目');
        for ($n = 1; $n <= 13; $n++) {
            $this->assign($term['term'], $this->publishedNews('many-'.$n, '連番記事'.$n));
        }
        $this->get('/news/news_category/term-1')->assertOk()->assertSee('rel="next"', false);
        $this->get('/news/news_category/term-1?page=2')->assertOk()->assertSee('連番記事1')->assertDontSee('連番記事13');
        $this->get('/news?q[]=bad')->assertStatus(422);
    }

    public function test_分類一覧も手動の掲載順で並ぶ(): void
    {
        $term = $this->taxonomyWithTerm('分類', '項目');
        $old = $this->publishedNews('order-old', '掲載順先頭');
        $new = $this->publishedNews('order-new', '掲載順末尾');
        DB::table('news')->where('id', $old)->update(['published_at' => now()->subDays(3)]);
        $this->assign($term['term'], $old);
        $this->assign($term['term'], $new);
        $result = app(ReorderContent::class)->execute(
            'news', $old, null, OperationContext::cli('test'),
        );
        $this->assertSame(OperationStatus::Success, $result->status);
        $this->get('/news/news_category/term-1')->assertOk()->assertSeeInOrder(['掲載順先頭', '掲載順末尾']);
    }

    // ── helpers ────────────────────────────────────────────

    /** @param  array<string, bool>  $registration */
    private function setting(array $registration): void
    {
        DB::table('content_types')->updateOrInsert(
            ['content_type_id' => 'news'],
            [
                'status' => 'active', 'lifecycle' => 'ready',
                'registration' => json_encode($registration, JSON_UNESCAPED_UNICODE),
                'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
            ],
        );

        app()->forgetScopedInstances();
    }

    /** @return array{taxonomy: string, term: string} */
    private function taxonomyWithTerm(string $label, string $name): array
    {
        $taxonomyId = (string) Str::ulid();
        $termId = (string) Str::ulid();

        DB::table('taxonomies')->insert([
            'id' => $taxonomyId, 'key' => 'news_category', 'label' => $label,
            'hierarchical' => true, 'status' => 'active', 'managed_by' => 'code',
            'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('taxonomy_terms')->insert([
            'id' => $termId, 'taxonomy_id' => $taxonomyId, 'name' => $name, 'slug' => 'term-1',
            'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['taxonomy' => $taxonomyId, 'term' => $termId];
    }

    private function assign(string $termId, int $contentId): void
    {
        DB::table('taxonomy_term_assignments')->insert([
            'term_id' => $termId, 'content_type' => 'news', 'content_id' => $contentId,
            'sort_order' => 0, 'created_at' => now(),
        ]);
    }

    private function publishedNews(string $slug, string $title = 'お知らせ', bool $published = true): int
    {
        $news = News::query()->create([
            'title' => $title, 'slug' => $slug, 'body' => '<p>本文</p>',
            'status' => $published ? 'published' : 'draft',
            'published_at' => $published ? now()->subDay() : null,
        ]);

        return (int) $news->getKey();
    }
}
