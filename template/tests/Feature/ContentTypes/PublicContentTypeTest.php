<?php

declare(strict_types=1);

namespace Tests\Feature\ContentTypes;

use AgentCms\Core\Application\ContentTypes\UpdateContentType;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Pages\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 公開スラッグと、汎用の公開面（Phase 9.7 / STAGE 20C 修正・SPEC §D.4 / §E.2 / §F.2.2 / OG-D）。
 *
 * ここで固定するのは 3 つ。
 *
 * 1. **スラッグは 3 種類すべてと突き合わせる** — 別の投稿タイプ・ページ・既存 route。
 *    どれか 1 つ漏らすと、保存できたのに開けない URL ができる
 * 2. **公開面は route を固定したまま** — archive しても route 集合は変わらず、
 *    要求を受けてから公開可否を判定する（boot で DB を読まない契約を壊さない）
 * 3. **ページを奪わない** — 投稿タイプでない slug は、これまでどおり PageDocument が受ける
 */
final class PublicContentTypeTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
    }

    private function context(): OperationContext
    {
        return OperationContext::admin($this->owner->getKey(), (string) $this->owner->name);
    }

    /** `post` を公開状態にする */
    private function publish(string $slug = 'blog', bool $hasArchive = true): void
    {
        $result = app(UpdateContentType::class)->execute([
            'id' => 'post',
            'lock_version' => 0,
            'registration' => [
                'public' => true,
                'publicly_queryable' => true,
                'has_archive' => $hasArchive,
            ],
            'public_slug' => $slug,
        ], $this->context());

        $this->assertTrue($result->isSuccess(), (string) $result->message);

        app()->forgetScopedInstances();
    }

    private function entry(string $slug = 'hello', string $status = 'published'): int
    {
        return DB::table('post')->insertGetId([
            'title' => 'こんにちは',
            'slug' => $slug,
            'body' => '<p>本文です</p>',
            'status' => $status,
            'published_at' => $status === 'published' ? now()->subDay() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function archive(): void
    {
        DB::table('content_types')->where('content_type_id', 'post')->update(['status' => 'archived']);
        app()->forgetScopedInstances();
    }

    // ── 1. 公開スラッグの衝突検査 ─────────────────────────────────

    /** @return list<array{0: string}> */
    public static function reservedSlugs(): array
    {
        return [
            ['login'], ['admin'], ['api'], ['contact'], ['media'],
            ['sitemap.xml'], ['robots.txt'], ['up'], ['dashboard'], ['works'], ['news'],
        ];
    }

    #[DataProvider('reservedSlugs')]
    public function test_既存routeのスラッグは奪えない(string $slug): void
    {
        $result = app(UpdateContentType::class)->execute([
            'id' => 'post', 'lock_version' => 0, 'public_slug' => $slug,
        ], $this->context());

        $this->assertSame(OperationStatus::ValidationError, $result->status, "{$slug} が通ってしまった");
        $this->assertArrayHasKey('public_slug', $result->errors);
        $this->assertSame(0, DB::table('content_types')->count());
    }

    public function test_ページのスラッグは奪えない(): void
    {
        Page::query()->create([
            'slug' => 'company', 'title' => '会社案内',
            'status' => 'draft', 'managed_by' => Page::MANAGED_BY_DOCUMENT,
        ]);

        $result = app(UpdateContentType::class)->execute([
            'id' => 'post', 'lock_version' => 0, 'public_slug' => 'company',
        ], $this->context());

        $this->assertSame(OperationStatus::ValidationError, $result->status, '下書きのページでも奪わせない');
        $this->assertStringContainsString('ページ', implode('', $result->errors['public_slug'] ?? []));
    }

    public function test_ゴミ箱のページのスラッグも奪えない(): void
    {
        Page::query()->create([
            'slug' => 'trashed-page', 'title' => '捨てたページ',
            'status' => 'draft', 'managed_by' => Page::MANAGED_BY_DOCUMENT,
            'trashed_at' => now(),
        ]);

        $result = app(UpdateContentType::class)->execute([
            'id' => 'post', 'lock_version' => 0, 'public_slug' => 'trashed-page',
        ], $this->context());

        // 復元されたときに衝突するので、安全側で拒否する
        $this->assertSame(OperationStatus::ValidationError, $result->status);
    }

    public function test_別の投稿タイプのスラッグは奪えない(): void
    {
        DB::table('content_types')->insert([
            'content_type_id' => 'other_type', 'status' => 'active', 'lifecycle' => 'ready',
            'menu_order' => 0, 'comments_enabled' => false, 'revisions_enabled' => true,
            'public_slug' => 'topics', 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        app()->forgetScopedInstances();

        $result = app(UpdateContentType::class)->execute([
            'id' => 'post', 'lock_version' => 0, 'public_slug' => 'topics',
        ], $this->context());

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertStringContainsString('投稿タイプ', implode('', $result->errors['public_slug'] ?? []));
    }

    public function test_自分が使っているスラッグは保存し直せる(): void
    {
        $this->publish('blog');

        $result = app(UpdateContentType::class)->execute([
            'id' => 'post',
            'lock_version' => 1,
            'registration' => ['public' => true],
            'public_slug' => 'blog',
            'display_overrides' => ['label' => 'ブログ'],
        ], $this->context());

        $this->assertTrue($result->isSuccess(), '自分自身のスラッグを自己衝突扱いしている');
    }

    public function test_衝突の検査で監査に値を残さない(): void
    {
        app(UpdateContentType::class)->execute([
            'id' => 'post', 'lock_version' => 0, 'public_slug' => 'login',
        ], $this->context());

        $metadata = (string) DB::table('audit_logs')
            ->where('operation', 'content.type.update')->latest('id')->value('metadata');

        $this->assertStringNotContainsString('login', $metadata);
    }

    // ── 2. 汎用の公開面 ───────────────────────────────────────────

    public function test_公開した型に一覧と詳細の_ur_lが生える(): void
    {
        $this->publish();
        $this->entry();

        $this->get('/blog')->assertOk()->assertSee('こんにちは');
        $this->get('/blog/hello')->assertOk()->assertSee('本文です', false);
    }

    public function test_未公開のentryは詳細も一覧も出さない(): void
    {
        $this->publish();
        $this->entry('draft-entry', 'draft');

        $this->get('/blog')->assertOk()->assertDontSee('こんにちは');
        $this->get('/blog/draft-entry')->assertNotFound();
    }

    public function test_無効にすると両方の_ur_lが404になり復元で戻る(): void
    {
        $this->publish();
        $this->entry();

        $this->get('/blog')->assertOk();

        $this->archive();

        $this->get('/blog')->assertNotFound();
        $this->get('/blog/hello')->assertNotFound();

        DB::table('content_types')->where('content_type_id', 'post')->update(['status' => 'active']);
        app()->forgetScopedInstances();

        // **同じ URL で戻る**（route 集合を変えていないので、復元しただけで開ける）
        $this->get('/blog')->assertOk();
        $this->get('/blog/hello')->assertOk();
    }

    public function test_公開していない型の_ur_lは無い(): void
    {
        $this->entry();

        $this->get('/blog')->assertNotFound();
        $this->get('/blog/hello')->assertNotFound();
    }

    public function test_一覧を持たない型は詳細だけ開ける(): void
    {
        $this->publish('blog', hasArchive: false);
        $this->entry();

        $this->get('/blog')->assertNotFound();
        $this->get('/blog/hello')->assertOk();
    }

    public function test_知らないスラッグではページが今までどおり動く(): void
    {
        $this->publish();

        // PageDocument の fallback を奪わない
        $this->get('/does-not-exist')->assertNotFound();
        $this->get('/blog/does-not-exist')->assertNotFound();
    }

    public function test_既存の公開routeを奪わない(): void
    {
        $this->publish();

        $this->get('/')->assertOk();
        $this->get('/contact')->assertOk();
        $this->get('/news')->assertOk();
        $this->get('/works')->assertOk();
        $this->get('/sitemap.xml')->assertOk();
        $this->get('/robots.txt')->assertOk();
    }

    public function test_公開面はscriptを実行させない(): void
    {
        $this->publish();

        DB::table('post')->insert([
            'title' => '<script>alert(1)</script>タイトル',
            'slug' => 'xss', 'body' => '<p>ふつうの本文</p>', 'status' => 'published',
            'published_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->get('/blog/xss')->assertOk();
        $html = $response->getContent() ?: '';

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_公開面はvueを要求しない(): void
    {
        $this->publish();
        $this->entry();

        $html = $this->get('/blog')->assertOk()->getContent() ?: '';

        // 公開側は Blade SSR。Inertia の起点を出さない
        $this->assertStringNotContainsString('data-page="app"', $html);
    }

    public function test_sitemapに公開した型が載る(): void
    {
        $this->publish();
        $this->entry();
        $this->entry('draft-one', 'draft');

        $xml = $this->get('/sitemap.xml')->assertOk()->getContent() ?: '';

        $this->assertStringContainsString('/blog</loc>', $xml);
        $this->assertStringContainsString('/blog/hello</loc>', $xml);
        $this->assertStringNotContainsString('/blog/draft-one', $xml);

        $this->archive();

        $this->assertStringNotContainsString('/blog', $this->get('/sitemap.xml')->getContent() ?: '');
    }

    public function test_公開routeはboot時に_d_bを読まない(): void
    {
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        // route 集合の決定に DB は要らない（fallback 1 本で受けるため）
        app('router')->getRoutes()->refreshNameLookups();

        $this->assertSame([], $queries);
    }
}
