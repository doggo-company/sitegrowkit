<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\Content\ContentTypeResolver;
use AgentCms\Core\Application\ContentTypes\UpdateContentType;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsResolver;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsWriter;
use AgentCms\Core\Permalink\ContentPermalink;
use AgentCms\Core\Permalink\PermalinkStructure;
use AgentCms\Core\Spam\HoneypotCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * URL の構造を型ごとに選ぶ（P1 ③ 段 2）。
 *
 * ## 選択肢にする（自由入力にしない）
 *
 * `%year%/%postname%` のような自由なパターンにすると、**slug を含まない構造を
 * 書けてしまう**（URL が一意にならない）。解く側は正規表現地獄になり、
 * 入口の衝突検査も効かなくなる。だから**4 つの値から選ぶ**。
 *
 * ## 書かなければ、いままでどおり
 *
 * 既定は `base_slug`。**既存のサイトは 1 文字も変わらない**（原則 0）。
 */
final class PermalinkStructureTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    /** @param  array<string, mixed>  $registration */
    private function publishType(string $type, string $base, array $registration = []): void
    {
        DB::table('content_types')->updateOrInsert(
            ['content_type_id' => $type],
            [
                'status' => 'active', 'lifecycle' => 'ready', 'lock_version' => 0,
                'registration' => json_encode(
                    ['public' => true, ...$registration],
                    JSON_THROW_ON_ERROR,
                ),
                'public_slug' => $base,
                'created_at' => now(), 'updated_at' => now(),
            ],
        );

        app()->forgetScopedInstances();
        app()->forgetInstance(ContentTypeSettingsResolver::class);
        app()->forgetInstance(ContentPermalink::class);
    }

    private function entry(string $slug = 'hello', ?string $publishedAt = '2026-08-15 09:00:00'): int
    {
        return DB::table('post')->insertGetId([
            'title' => '記事', 'slug' => $slug, 'body' => '<p>本文</p>',
            'status' => $publishedAt === null ? 'draft' : 'published',
            'published_at' => $publishedAt,
            'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function entryUrl(int $id): ?string
    {
        $model = app(ContentTypeResolver::class)->modelClass('post');

        return app(ContentPermalink::class)->entry('post', $model::query()->findOrFail($id), absolute: false);
    }

    /**
     * **書かなければ、いままでの形。**
     *
     * ここが崩れると、**更新を当てただけで全サイトの URL が変わる**。
     */
    public function test_書かなければ従来の形のまま(): void
    {
        $this->publishType('post', 'blog');
        $id = $this->entry();

        $this->assertSame(PermalinkStructure::BaseSlug, app(ContentPermalink::class)->structureFor('post'));
        $this->assertSame('/blog/hello', $this->entryUrl($id));
        $this->get('/blog/hello')->assertOk();
    }

    /**
     * **4 つの形が、作る → 解く で往復する。**
     *
     * 作れても解けなければ 404 になり、解けても作れなければリンクが出ない。
     * **どちらか片方だけ通る状態が一番たちが悪い**（画面には出るのに開けない）。
     */
    public function test_4つの形が往復する(): void
    {
        $cases = [
            ['base_slug', '/blog/hello'],
            ['slug', '/hello'],
            ['base_date_slug', '/blog/2026/08/hello'],
            ['base_id', null],   // id は行ごとに変わるので下で組み立てる
        ];

        foreach ($cases as [$structure, $expected]) {
            $this->publishType('post', 'blog', ['permalink' => $structure]);
            DB::table('post')->delete();
            $id = $this->entry();

            $expected ??= "/blog/{$id}";

            $this->assertSame($expected, $this->entryUrl($id), "{$structure}: 作れない");
            $this->get($expected)->assertOk("{$structure}: 解けない");
        }
    }

    /** **一覧は構造に関わらず入口のまま**（`/blog`）。archive は base が持ち場である */
    public function test_一覧は構造に関わらず入口のまま(): void
    {
        $this->publishType('post', 'blog', ['permalink' => 'slug']);
        $this->entry();

        $this->assertSame('/blog', app(ContentPermalink::class)->index('post', absolute: false));
        $this->get('/blog')->assertOk();
    }

    /**
     * **公開日が無ければ、日付を含む URL は作らない。**
     *
     * `created_at` で代用すると、**公開した瞬間に URL が変わる** —
     * 公開前に配った URL が死ぬ。**日付が確定していない構造で日付 URL を約束しない。**
     */
    public function test_公開日が無ければ日付urlを作らない(): void
    {
        $this->publishType('post', 'blog', ['permalink' => 'base_date_slug']);
        $id = $this->entry('draft-entry', publishedAt: null);

        $this->assertNull($this->entryUrl($id));
    }

    /**
     * **日付が合わない URL では開けない。**
     *
     * 合わせないと `/blog/1999/01/hello` でも同じ記事が出て、**同じ記事に無数の URL** ができる。
     */
    public function test_日付が合わないurlでは開けない(): void
    {
        $this->publishType('post', 'blog', ['permalink' => 'base_date_slug']);
        $this->entry();

        $this->get('/blog/2026/08/hello')->assertOk();
        $this->get('/blog/1999/01/hello')->assertNotFound();
    }

    /**
     * **コメントの戻り先も構造に追随する**（F-40）。
     *
     * ここが自分で URL を組んでいると、構造を変えた型で
     * **コメントのあとに旧い URL へ戻され、そのまま 404 になる**。
     */
    public function test_コメントの戻り先も構造に追随する(): void
    {
        $this->publishType('post', 'blog', [
            'permalink' => 'base_date_slug', 'publicly_queryable' => true,
        ]);
        DB::table('content_types')->where('content_type_id', 'post')
            ->update(['comments_enabled' => true]);
        app()->forgetInstance(ContentTypeSettingsResolver::class);
        app()->forgetInstance(ContentPermalink::class);

        $id = $this->entry();

        $response = $this->post("/comments/post/{$id}", [
            'author_name' => '読者', 'body' => 'よい記事でした',
            HoneypotCheck::FIELD => '',
        ]);

        // **戻り先が新しい形になっている**（旧い形へ戻されると、そこは 404 である）
        $response->assertRedirectContains('/blog/2026/08/hello');
        $this->assertStringNotContainsString(
            '/blog/hello',
            (string) $response->headers->get('Location'),
        );
    }

    /**
     * **知らない値は既定へ倒し、倒したことを覚えておく。**
     *
     * 古い CMS が新しい値を読んでも **URL が壊れず、いまの形のまま**になる。
     */
    public function test_知らない値は既定へ倒す(): void
    {
        $this->publishType('post', 'blog', ['permalink' => 'nanika']);
        $id = $this->entry();

        $this->assertSame('/blog/hello', $this->entryUrl($id));
        $this->assertContains(
            'registration.permalink',
            app(ContentTypeSettingsResolver::class)->for('post')->unrecognized,
        );
    }

    /**
     * **`slug`（入口なし）を選べるのは 1 型だけ。**
     *
     * この構造は**その型が top-level を占める**ことを意味し、解決順が
     * 通常 route → 投稿タイプ → ページ なので、**投稿がページを隠す**。
     * 2 型が同時に名乗ると、どちらの記事か決められない。
     */
    /**
     * **未公開の型は、いつでも公開できる**（R-13 11 件目）。
     *
     * URL 連続性のガードが守るのは「**いま生きている URL**」である。
     * 型が非公開なら公開面はどこも 404 で、引き継ぐべき URL は無い。
     * 記事の公開件数は「URL が生きているか」を意味しない —
     * seed で入れた記事は `status: published` でも、型が非公開なら 1 本も配信されていない。
     *
     * 実測: import が作った型を公開できずに止まった（型は在るのに公開面が 404 のまま）。
     */
    public function test_未公開の型は記事が在っても公開できる(): void
    {
        // 記事は在る（seed 済み）が、型は非公開のまま
        $this->entry('hello');

        $result = app(UpdateContentType::class)->execute(
            [
                'id' => 'post', 'lock_version' => 0,
                'registration' => ['public' => true, 'permalink' => 'base_date_slug'],
                'public_slug' => 'blog',
            ],
            OperationContext::admin($this->operatorId(), 'admin'),
        );

        $this->assertSame(
            OperationStatus::Success,
            $result->status,
            (string) json_encode($result->errors, JSON_UNESCAPED_UNICODE),
        );
    }

    /** 公開中の型で、1 本の転送に落とせない変更は**これまでどおり断る** */
    public function test_公開中の型は引き継げない変更を断る(): void
    {
        $this->publishType('post', 'blog');
        $this->entry('hello');

        $result = app(UpdateContentType::class)->execute(
            [
                'id' => 'post',
                'lock_version' => app(ContentTypeSettingsWriter::class)->currentLockVersion('post'),
                'registration' => ['public' => true, 'permalink' => 'base_date_slug'],
                'public_slug' => 'blog',
            ],
            OperationContext::admin($this->operatorId(), 'admin'),
        );

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertStringContainsString(
            '引き継げません',
            (string) json_encode($result->errors, JSON_UNESCAPED_UNICODE),
        );
    }

    public function test_slug構造は2型目を断る(): void
    {
        // `product` が先に top-level を占めている
        $this->publishType('product', 'products', ['permalink' => 'slug']);

        $result = app(UpdateContentType::class)->execute(
            [
                'id' => 'post', 'lock_version' => 0,
                'registration' => ['public' => true, 'permalink' => 'slug'],
                'public_slug' => 'blog',
            ],
            OperationContext::admin($this->operatorId(), 'admin'),
        );

        $this->assertSame(OperationStatus::ValidationError, $result->status);

        // **理由を言う**（「使えません」だけでは直しようが無い）
        $this->assertStringContainsString(
            'product',
            (string) json_encode($result->errors, JSON_UNESCAPED_UNICODE),
        );

        // **1 型目は変わっていない**
        $this->assertSame('product', app(ContentPermalink::class)->topLevelType());
    }
}
