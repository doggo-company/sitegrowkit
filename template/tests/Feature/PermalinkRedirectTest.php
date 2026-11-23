<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\ContentTypes\UpdateContentType;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Result;
use AgentCms\Core\ContentTypes\Registration\ContentTypeSettingsResolver;
use AgentCms\Core\Permalink\ContentPermalink;
use AgentCms\Core\Redirects\RedirectRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * 入口・構造を変えても、**旧 URL が生き続ける**（P1 ③ 段 3）。
 *
 * ## 記事ごとにルールを作らない
 *
 * 構造を変えると**その型の記事の URL が一斉に変わる**。1000 記事なら 1000 の URL が死ぬ。
 * 記事ごとに転送を作ればルールが際限なく増えるので、**接頭辞の付け替え 1 本**で
 * 書けるときだけ自動で作り、**書けないときは変更のほうを断る**。
 *
 * ## OG10 との関係
 *
 * OG10 が禁じたのは「**記事の slug 変更**の自動ルール」で、そちらは編集のたびに起き得る。
 * こちらは**サイト全体の操作**で、作るのは 1 本だけ。**適用範囲が違う。**
 */
final class PermalinkRedirectTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    /** @param  array<string, mixed>  $registration */
    private function publishType(string $base, array $registration = []): void
    {
        DB::table('content_types')->updateOrInsert(
            ['content_type_id' => 'post'],
            [
                'status' => 'active', 'lifecycle' => 'ready', 'lock_version' => 0,
                'registration' => json_encode(['public' => true, ...$registration], JSON_THROW_ON_ERROR),
                'public_slug' => $base,
                'created_at' => now(), 'updated_at' => now(),
            ],
        );

        $this->forget();
    }

    private function forget(): void
    {
        app()->forgetInstance(ContentTypeSettingsResolver::class);
        app()->forgetInstance(ContentPermalink::class);
    }

    private function entry(string $slug = 'hello'): int
    {
        return DB::table('post')->insertGetId([
            'title' => '記事', 'slug' => $slug, 'body' => '<p>本文</p>',
            'status' => 'published', 'published_at' => '2026-08-15 09:00:00',
            'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $registration */
    private function update(string $base, array $registration = []): Result
    {
        $version = (int) DB::table('content_types')->where('content_type_id', 'post')->value('lock_version');

        $result = app(UpdateContentType::class)->execute([
            'id' => 'post', 'lock_version' => $version,
            'registration' => ['public' => true, 'publicly_queryable' => true, ...$registration],
            'public_slug' => $base,
        ], OperationContext::admin($this->operatorId(), 'admin'));

        $this->forget();

        return $result;
    }

    /**
     * **入口を付け替えると、旧 URL が新 URL へ転送される。**
     *
     * ルールは **1 本**（記事数に比例しない）。
     */
    public function test_入口を付け替えると1本の転送ができる(): void
    {
        $this->publishType('blog');
        $this->entry();
        $this->entry('second');

        $this->assertTrue($this->update('journal')->isSuccess());

        $this->assertSame(1, RedirectRule::query()->count(), 'ルールが 1 本ではない');

        $rule = RedirectRule::query()->firstOrFail();
        $this->assertSame('prefix', $rule->match_type);
        $this->assertSame('/blog', $rule->source_path);
        $this->assertSame('/journal', $rule->target_url);
        // **手で作ったものと混ぜない**
        $this->assertSame(RedirectRule::REASON_SLUG_CHANGE, $rule->created_reason);

        // 旧 URL が生きている（**記事ごとに書いていないのに 2 件とも通る**）
        $this->get('/blog/hello')->assertRedirect(url('/journal/hello'));
        $this->get('/blog/second')->assertRedirect(url('/journal/second'));
        $this->get('/journal/hello')->assertOk();
    }

    /** **入口を落とす向き**も 1 本で書ける（`/blog/x` → `/x`） */
    public function test_入口を落としても転送される(): void
    {
        $this->publishType('blog');
        $this->entry();

        $this->assertTrue($this->update('blog', ['permalink' => 'slug'])->isSuccess());

        $this->get('/blog/hello')->assertRedirect(url('/hello'));
        $this->get('/hello')->assertOk();
    }

    /**
     * **1 本で書けない変更は断る。**
     *
     * 記事ごとにルールを作れば書けるが、**ルールが記事数だけ増える**。
     * 増える側を選ばない。
     */
    public function test_1本で書けない変更は断る(): void
    {
        $this->publishType('blog');
        $this->entry();

        $result = $this->update('blog', ['permalink' => 'base_date_slug']);

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertSame(0, RedirectRule::query()->count(), '断ったのにルールを作っている');

        // **設定も変わっていない**（断ったのだから、変わっていてはいけない）
        $this->get('/blog/hello')->assertOk();
    }

    /**
     * **公開している記事が 1 件も無ければ、どんな構造でも選べる。**
     *
     * 死ぬ URL が無いので、断る理由が無い。
     * **公開前に構造を決められない CMS には意味が無い。**
     */
    public function test_公開前ならどの構造でも選べる(): void
    {
        $this->publishType('blog');

        $this->assertTrue($this->update('blog', ['permalink' => 'base_date_slug'])->isSuccess());
        // 1 本で書けない形なので、転送は作れない（作れないまま通した）
        $this->assertSame(0, RedirectRule::query()->count());
    }

    /**
     * **記事が 1 件も無くても、入口を付け替えたら転送を置く。**
     *
     * 記事が無くても**一覧の URL（`/blog`）は生きていた**。
     * 「断るかどうか」と「転送を置くかどうか」は別の問いである。
     */
    public function test_記事が無くても入口の付け替えは転送を置く(): void
    {
        $this->publishType('blog');

        $this->assertTrue($this->update('journal')->isSuccess());

        $this->assertSame(1, RedirectRule::query()->count());
        $this->get('/blog')->assertRedirect(url('/journal'));
    }

    /**
     * **入口を足す向きは断る。**
     *
     * `/hello` → `/blog/hello` を 1 本で書くには source が `/` になり、
     * **サイト全体（`/about` も `/contact` も）を巻き込む**。
     */
    public function test_入口を足す向きは断る(): void
    {
        $this->publishType('blog', ['permalink' => 'slug']);
        $this->entry();

        $result = $this->update('blog', ['permalink' => 'base_slug']);

        $this->assertSame(OperationStatus::ValidationError, $result->status);
        $this->assertSame(0, RedirectRule::query()->count());

        // 断ったので、記事は元の URL のままで開ける
        $this->get('/hello')->assertOk();
    }

    /**
     * **続けて変えても、旧い旧 URL から辿り着ける。**
     *
     * 転送は最大 3 段まで解決される（`RedirectResolver::MAX_HOPS`）。
     */
    public function test_続けて変えても最初のurlから辿り着ける(): void
    {
        $this->publishType('blog');
        $this->entry();

        $this->assertTrue($this->update('journal')->isSuccess());
        $this->assertTrue($this->update('diary')->isSuccess());

        $this->assertSame(2, RedirectRule::query()->count());
        $this->get('/blog/hello')->assertRedirect(url('/diary/hello'));
    }

    /**
     * **`slug` 構造で入口の名前だけ変えるのは通る**（F-41）。
     *
     * 記事の URL は `/hello` のままで**動かない**。動くのは一覧の `/blog` だけで、
     * それは接頭辞 1 本で書ける形そのものである。
     * 「入口が在るか」と「記事 URL に入口が入るか」を同じ述語で見ていたため、
     * **起きないことを理由に断っていた**。
     */
    public function test_slug構造で入口の名前だけ変えるのは通る(): void
    {
        $this->publishType('blog', ['permalink' => 'slug']);
        $this->entry();

        $this->assertTrue($this->update('journal', ['permalink' => 'slug'])->isSuccess());

        // **記事の URL は動いていない**
        $this->get('/hello')->assertOk();

        // **一覧だけが動く**
        $this->get('/blog')->assertRedirect(url('/journal'));
        $this->get('/journal')->assertOk();

        $this->assertSame(1, RedirectRule::query()->count());
    }

    /**
     * **設定画面から選べる。**
     *
     * 仕組みが在っても、画面に出ていなければ誰も使えない。
     * 選択肢の**名前と例はサーバー（enum）が持つ** — 画面と core で言い方をずらさない。
     */
    public function test_設定画面から構造を選べる(): void
    {
        $this->publishType('blog');

        $this->actingAs($this->operator())
            ->get('/admin/content-types/post/edit')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                /** @var list<array<string, string>> $options */
                $options = $page->toArray()['props']['options']['permalinks'] ?? [];

                $this->assertSame(
                    ['base_slug', 'slug', 'base_date_slug', 'base_id'],
                    array_column($options, 'value'),
                );

                // **例は入口を反映する**（`/blog/hello` であって `/{base}/hello` ではない）
                $this->assertSame('/blog/hello', $options[0]['example'] ?? null);
            });
    }

    /** **動いていなければ、何も作らない**（同じ設定を保存し直しただけ） */
    public function test_動いていなければルールを作らない(): void
    {
        $this->publishType('blog');
        $this->entry();

        $this->assertTrue($this->update('blog')->isSuccess());

        $this->assertSame(0, RedirectRule::query()->count());
    }
}
