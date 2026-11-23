<?php

declare(strict_types=1);

namespace Modules\Works\Tests;

use AgentCms\Core\Application\Content\ReorderContent;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Works\Models\Work;
use Tests\TestCase;

/**
 * `works` の実績に付けた分類が、**訪問者にも見えて、押せる**（R05・2026-09-09）。
 *
 * `works.category` は select のまま（Phase 9.3C）だが、分類管理で作った分類を
 * 実績に付けることはできる。`news` と同じ読み手・同じ判定で出す。
 */
final class WorksPublicTaxonomyTest extends TestCase
{
    use RefreshDatabase;

    public function test_実績の分類が名前とリンクで出る(): void
    {
        $term = $this->taxonomyWithTerm('業種', '飲食店');
        $this->assign($term['term'], $this->publishedWork('cafe-1', 'カフェのサイト'));

        $this->get('/works/cafe-1')
            ->assertOk()
            ->assertSee('業種')
            ->assertSee('<a class="article__term" href="/works/gyoshu/inshoku">飲食店</a>', false);
    }

    public function test_分類の一覧が200でその項目が付いた公開実績だけを並べる(): void
    {
        $term = $this->taxonomyWithTerm('業種', '飲食店');
        $this->assign($term['term'], $this->publishedWork('cafe-1', 'カフェのサイト'));
        $this->publishedWork('other', '関係ない実績');
        $this->assign($term['term'], $this->publishedWork('draft-1', '下書きの実績', published: false));

        $this->get('/works/gyoshu/inshoku')
            ->assertOk()
            ->assertSee('飲食店')
            ->assertSee('カフェのサイト')
            ->assertSee('/works/cafe-1', false)
            ->assertDontSee('関係ない実績')
            ->assertDontSee('下書きの実績');
    }

    public function test_一覧を持たない設定では文字のまま出す(): void
    {
        $this->setting(['has_archive' => false]);
        $term = $this->taxonomyWithTerm('業種', '飲食店');
        $this->assign($term['term'], $this->publishedWork('cafe-1'));

        $this->get('/works/cafe-1')
            ->assertOk()
            ->assertSee('<span class="article__term">飲食店</span>', false)
            ->assertDontSee('/works/gyoshu/inshoku', false);

        $this->get('/works/gyoshu/inshoku')->assertNotFound();
    }

    public function test_アーカイブした項目は出ず一覧も404(): void
    {
        $term = $this->taxonomyWithTerm('業種', 'やめた業種');
        $this->assign($term['term'], $this->publishedWork('cafe-1'));

        DB::table('taxonomy_terms')->where('id', $term['term'])->update(['archived_at' => now()]);

        $this->get('/works/cafe-1')->assertOk()->assertDontSee('やめた業種');
        $this->get('/works/gyoshu/inshoku')->assertNotFound();
    }

    public function test_付いていなければ何も出ない(): void
    {
        $this->taxonomyWithTerm('業種', '飲食店');
        $this->publishedWork('cafe-1');

        $this->get('/works/cafe-1')
            ->assertOk()
            ->assertDontSee('article__taxonomies', false)
            ->assertDontSee('飲食店');
    }

    public function test_分類の記事が増えても最後までページを送れる(): void
    {
        $term = $this->taxonomyWithTerm('分類', '項目');
        for ($n = 1; $n <= 13; $n++) {
            $this->assign($term['term'], $this->publishedWork('many-'.$n, '連番記事'.$n));
        }
        $this->get('/works/gyoshu/inshoku')->assertOk()->assertSee('rel="next"', false);
        $this->get('/works/gyoshu/inshoku?page=2')->assertOk()->assertSee('連番記事1')->assertDontSee('連番記事13');
        $this->get('/works?q[]=bad')->assertStatus(422);
    }

    public function test_分類一覧も手動の掲載順で並ぶ(): void
    {
        $term = $this->taxonomyWithTerm('分類', '項目');
        $old = $this->publishedWork('order-old', '掲載順先頭');
        $new = $this->publishedWork('order-new', '掲載順末尾');
        DB::table('works')->where('id', $old)->update(['published_at' => now()->subDays(3)]);
        $this->assign($term['term'], $old);
        $this->assign($term['term'], $new);
        $result = app(ReorderContent::class)->execute(
            'works', $old, null, OperationContext::cli('test'),
        );
        $this->assertSame(OperationStatus::Success, $result->status);
        $this->get('/works/gyoshu/inshoku')->assertOk()->assertSeeInOrder(['掲載順先頭', '掲載順末尾']);
    }

    // ── helpers ────────────────────────────────────────────

    /** @param  array<string, bool>  $registration */
    private function setting(array $registration): void
    {
        DB::table('content_types')->updateOrInsert(
            ['content_type_id' => 'works'],
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
            'id' => $taxonomyId, 'key' => 'gyoshu', 'label' => $label,
            'hierarchical' => false, 'status' => 'active', 'managed_by' => 'cms',
            'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('taxonomy_terms')->insert([
            'id' => $termId, 'taxonomy_id' => $taxonomyId, 'name' => $name, 'slug' => 'inshoku',
            'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['taxonomy' => $taxonomyId, 'term' => $termId];
    }

    private function assign(string $termId, int $contentId): void
    {
        DB::table('taxonomy_term_assignments')->insert([
            'term_id' => $termId, 'content_type' => 'works', 'content_id' => $contentId,
            'sort_order' => 0, 'created_at' => now(),
        ]);
    }

    private function publishedWork(string $slug, string $title = '実績', bool $published = true): int
    {
        $work = Work::query()->create([
            'title' => $title, 'slug' => $slug, 'category' => 'web', 'body' => '<p>本文</p>',
            'status' => $published ? 'published' : 'draft',
            'published_at' => $published ? now()->subDay() : null,
        ]);

        return (int) $work->getKey();
    }
}
