<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 分類ごとの一覧ページ（WP 一周 6 周目 2026-09-03 / 効くもの ② の後半）。
 *
 * 記事に分類の名前を出すところまでは 1 つ前の PR で入れた。
 * **押せる先が無ければ、名前が出ているだけ**なので、ここで一覧を足す。
 *
 * ## URL の形
 *
 * `/{入口}/{分類のキー}/{項目の綴り}` — 例 `/blog/shinryo_kamoku/term-1`。
 *
 * 🔴 **記事の判定を先に通す。** 分類の一覧は、記事として読めなかったときだけ見る。
 * こうすると**いまある URL の意味が 1 つも変わらない**（足すだけになる）。
 */
final class PublicTermArchiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_手動順が汎用記事の公開一覧と検索と分類一覧に反映される(): void
    {
        $this->publishType();
        $term = $this->taxonomyWithTerm();
        $old = $this->publishedPost('old-order', '掲載順対象の古い記事');
        $new = $this->publishedPost('new-order', '掲載順対象の新しい記事');
        DB::table('post')->where('id', $old)->update(['published_at' => now()->subDays(3)]);
        $this->assign($term['term'], $old);
        $this->assign($term['term'], $new);
        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/content/post/'.$new.'/reorder', ['direction' => 'down'])->assertOk();

        foreach (['/blog', '/blog?q='.urlencode('掲載順対象'), '/blog/shinryo_kamoku/term-1'] as $url) {
            $this->get($url)->assertOk()->assertSeeInOrder(['掲載順対象の古い記事', '掲載順対象の新しい記事']);
        }
    }

    public function test_汎用の公開検索は公開記事だけに絞って語をページ送りへ保持する(): void
    {
        $this->publishType();
        for ($n = 1; $n <= 13; $n++) {
            $this->publishedPost('find-'.$n, '対象の記事'.$n);
        }
        $this->publishedPost('other-search', '関係ない見出し');
        $this->publishedPost('draft-search', '対象の秘密下書き', published: false);
        $future = $this->publishedPost('future-search', '対象の未来記事');
        DB::table('post')->where('id', $future)->update(['published_at' => now()->addDay()]);
        $this->get('/blog?q='.urlencode('対象'))->assertOk()
            ->assertSee('対象の記事13')->assertDontSee('関係ない見出し')->assertDontSee('対象の秘密下書き')
            ->assertDontSee('対象の未来記事')->assertSee('page=2', false)->assertSee('q=', false);
        $this->get('/blog?q='.urlencode('対象').'&page=2')->assertOk()->assertSee('対象の記事1')->assertDontSee('対象の記事13');
        $this->get('/blog?q[]=bad')->assertStatus(422);
        $this->get('/blog?q='.str_repeat('x', 201))->assertStatus(422);
        $this->get('/blog?q='.urlencode('<script>alert(1)</script>'))->assertOk()->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_公開一覧と分類一覧は古い記事までページを送れる(): void
    {
        $this->publishType();
        $term = $this->taxonomyWithTerm();
        for ($n = 1; $n <= 51; $n++) {
            $this->assign($term['term'], $this->publishedPost('entry-'.$n, '記事番号'.$n));
        }
        foreach (['/blog', '/blog/shinryo_kamoku/term-1'] as $url) {
            $this->get($url)->assertOk()->assertSee('rel="next"', false);
            $this->get($url.'?page=5')->assertOk()->assertSee('記事番号1')->assertSee('rel="prev"', false);
        }
    }

    public function test_分類の一覧に_その項目が付いた公開記事が並ぶ(): void
    {
        $this->publishType();
        $term = $this->taxonomyWithTerm();
        $withTerm = $this->publishedPost('yobou', '予防接種のお知らせ');
        $this->publishedPost('betsu', '関係ない記事');

        $this->assign($term['term'], $withTerm);

        $this->get('/blog/shinryo_kamoku/term-1')
            ->assertOk()
            ->assertSee('予防接種のお知らせ')
            ->assertDontSee('関係ない記事');
    }

    /** 分類と項目の名前が見出しに出る（どこを見ているか分かる） */
    public function test_見出しに分類と項目の名前が出る(): void
    {
        $this->publishType();
        $term = $this->taxonomyWithTerm();
        $this->assign($term['term'], $this->publishedPost());

        $this->get('/blog/shinryo_kamoku/term-1')
            ->assertSee('診療科目')
            ->assertSee('予防接種');
    }

    /** 🔴 下書きは並べない（公開面の条件は 1 か所） */
    public function test_下書きは並ばない(): void
    {
        $this->publishType();
        $term = $this->taxonomyWithTerm();
        $draft = $this->publishedPost('shitagaki', '下書きの記事', published: false);

        $this->assign($term['term'], $draft);

        $this->get('/blog/shinryo_kamoku/term-1')->assertDontSee('下書きの記事');
    }

    public function test_無い項目は404(): void
    {
        $this->publishType();
        $this->taxonomyWithTerm();

        $this->get('/blog/shinryo_kamoku/term-999')->assertNotFound();
    }

    public function test_無効にした分類は404(): void
    {
        $this->publishType();
        $term = $this->taxonomyWithTerm();

        DB::table('taxonomies')->where('id', $term['taxonomy'])->update(['status' => 'disabled']);

        $this->get('/blog/shinryo_kamoku/term-1')->assertNotFound();
    }

    public function test_アーカイブした項目は404(): void
    {
        $this->publishType();
        $term = $this->taxonomyWithTerm();

        DB::table('taxonomy_terms')->where('id', $term['term'])->update(['archived_at' => now()]);

        $this->get('/blog/shinryo_kamoku/term-1')->assertNotFound();
    }

    /** 一覧の URL を持たない型は、分類の一覧も持たない */
    public function test_一覧を持たない型では404(): void
    {
        $this->publishType(hasArchive: false);
        $term = $this->taxonomyWithTerm();

        $this->assign($term['term'], $this->publishedPost());

        $this->get('/blog/shinryo_kamoku/term-1')->assertNotFound();
    }

    /**
     * 🔴 **記事の URL を奪わない。**
     *
     * 分類の一覧は、記事として読めなかったときだけ見る。
     */
    public function test_記事のurlはこれまでどおり(): void
    {
        $this->publishType();
        $this->publishedPost('oshirase', 'これは記事');

        $this->get('/blog/oshirase')->assertOk()->assertSee('これは記事');
    }

    /** 記事に出ている分類の名前が、その一覧への入口になる */
    public function test_記事の分類名が一覧へのリンクになる(): void
    {
        $this->publishType();
        $term = $this->taxonomyWithTerm();
        $this->assign($term['term'], $this->publishedPost('oshirase'));

        $this->get('/blog/oshirase')
            ->assertOk()
            ->assertSee('/blog/shinryo_kamoku/term-1', false);
    }

    // ── ヘルパー ────────────────────────────────────────────

    private function publishType(bool $hasArchive = true): void
    {
        DB::table('content_types')->updateOrInsert(
            ['content_type_id' => 'post'],
            [
                'status' => 'active',
                'lifecycle' => 'ready',
                'public_slug' => 'blog',
                'registration' => json_encode(
                    ['public' => true, 'has_archive' => $hasArchive],
                    JSON_UNESCAPED_UNICODE,
                ),
                'lock_version' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        app()->forgetScopedInstances();
    }

    /** @return array{taxonomy: string, term: string} */
    private function taxonomyWithTerm(): array
    {
        $taxonomyId = (string) Str::ulid();
        $termId = (string) Str::ulid();

        DB::table('taxonomies')->insert([
            'id' => $taxonomyId,
            'key' => 'shinryo_kamoku',
            'label' => '診療科目',
            'hierarchical' => false,
            'status' => 'active',
            'managed_by' => 'cms',
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('taxonomy_terms')->insert([
            'id' => $termId,
            'taxonomy_id' => $taxonomyId,
            'name' => '予防接種',
            'slug' => 'term-1',
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['taxonomy' => $taxonomyId, 'term' => $termId];
    }

    private function assign(string $termId, int $contentId): void
    {
        DB::table('taxonomy_term_assignments')->insert([
            'term_id' => $termId,
            'content_type' => 'post',
            'content_id' => $contentId,
            'sort_order' => 0,
            'created_at' => now(),
        ]);
    }

    private function publishedPost(
        string $slug = 'oshirase',
        string $title = 'お知らせ',
        bool $published = true,
    ): int {
        return (int) DB::table('post')->insertGetId([
            'title' => $title,
            'slug' => $slug,
            'body' => '<p>本文</p>',
            'status' => $published ? 'published' : 'draft',
            'published_at' => $published ? now()->subDay() : null,
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
