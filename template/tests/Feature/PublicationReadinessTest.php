<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\PublicationReadiness;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 「仮のまま公開してしまった」を機械が止める。
 *
 * 🔴 **原稿待ちの文は、エラーを出さない。**「準備中」も「※ 記事は仮のものです」も、
 * サイトは 200 で開く。気づけるのは、覚えている人が見に行ったときだけ。
 *
 * ▷ **検査を書いたら、検査を検査する。**
 *   赤にすべきものと、緑のままにすべきものを両方並べる。
 */
final class PublicationReadinessTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array{0: string, 1: string}> */
    public static function 原稿待ちの文(): array
    {
        return [
            ['<p>※ 記事は仮のものです。</p>', '仮のもの'],
            ['<p>※ 紹介文は素材回収後に差し替え</p>', '素材回収'],
            ['<span>TEL / FAX（準備中）</span>', '準備中'],
            ['<p>ダミーの画像です</p>', 'ダミー'],
            ['<a href="tel:0766-00-0000">0766-00-0000</a>', '0766-00-0000'],
            ['<p>admin@example.test</p>', 'example.test'],
        ];
    }

    #[DataProvider('原稿待ちの文')]
    public function test_原稿待ちの印を見つける(string $html, string $expected): void
    {
        $this->assertContains($expected, PublicationReadiness::placeholdersIn($html));
    }

    /**
     * 🔴 **素直でない例。**ここが赤になる検査は、使い物にならない。
     *
     * `仮植え` は庭木を一時的に植える**正しい造園用語**で、原稿が完成しても残る。
     * `準備` も同じ — 「準備中」だけが印であって、「準備」は普通の日本語。
     *
     * **広すぎる検査は、緑にするために本文を歪めさせる。**
     *
     * @return list<array{0: string}>
     */
    public static function 原稿として正しい文(): array
    {
        return [
            ['<p>移植までの間、根鉢を傷めないよう仮植えしておきます。</p>'],
            ['<p>植栽の準備から施工まで、一貫してお引き受けします。</p>'],
            ['<p>仮設足場を組んでの高所剪定にも対応します。</p>'],
            ['<p>お問い合わせは 0766-12-3456 まで。</p>'],
            ['<p>example.com のような一般的な文字列</p>'],
        ];
    }

    #[DataProvider('原稿として正しい文')]
    public function test_原稿として正しい文は印にしない(string $html): void
    {
        $this->assertSame([], PublicationReadiness::placeholdersIn($html));
    }

    public function test_タグの中は数えない(): void
    {
        // class 名や URL に含まれる語で赤にすると、直しようが無くなる
        $this->assertSame(
            [],
            PublicationReadiness::placeholdersIn('<div class="準備中-panel" data-x="ダミー"></div>'),
        );
    }

    public function test_コマンドが在る(): void
    {
        $this->assertArrayHasKey(
            'site:readiness',
            $this->app[Kernel::class]->all(),
        );
    }

    /**
     * 🔴 **sitemap に載っているのに開けない URL を、検査が見逃していた。**
     *
     * `publicPaths()` は sitemap をたどるが、`render()` が**本文だけ取って
     * 状態コードを捨てていた**。404 の本文には原稿待ちの言葉が無いので、
     * **検査は緑のまま通る。**
     *
     * これは D-9 が言っていた事故そのもの — `news` / `works` は module が
     * 受けているだけで、**module を外した日に 404 になる**。sitemap には載り続ける。
     * 名指しで news / works を守るのではなく、**「載っているのに開けない」を拾う**。
     */
    public function test_sitemap_に載っているのに開けない_url_を止める(): void
    {
        Route::get('/sitemap.xml', fn () => response(
            '<?xml version="1.0"?><urlset><url><loc>http://localhost/</loc></url>'
            .'<url><loc>http://localhost/nai-michi</loc></url></urlset>',
            200,
            ['Content-Type' => 'application/xml'],
        ));

        $findings = (new PublicationReadiness)->check();

        $paths = array_column($findings, 'where');
        $this->assertContains('/nai-michi', $paths,
            'sitemap に載っているのに開けない URL が見逃されている');

        // 何が起きたかまで言う（状態コードが分からないと直せない）
        $what = array_column($findings, 'what', 'where')['/nai-michi'] ?? '';
        $this->assertStringContainsString('404', $what);
    }

    /**
     * **画面から触れる**（2026-08-30）。
     *
     * 判定は前から在ったが、入口が `php artisan site:readiness` だけだった。
     * ダッシュボードの「はじめに」④ が**リンクの無い項目**として並んでいて、
     * 読んだ人はどこへ行けばよいか分からなかった。
     */
    public function test_公開前チェックの画面が開ける(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);

        $this->actingAs($owner)->get('/admin/readiness')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('admin/readiness/Index')->has('findings'));
    }

    /**
     * 🔴 **検査したせいで、検査した画面が壊れない**（2026-08-30 実測）。
     *
     * `PublicationReadiness` は公開ページを内部で開いて数える。その `$kernel->handle()` が
     * container の `request` を**差し替えたまま**にしていた。console から呼ぶぶんには
     * 誰も困らなかったが、**管理画面から呼ぶと外側の応答が壊れた** —
     * 以降の `asset()` が `http://localhost/build/...`（ポート無し）を吐き、
     * **CSP の `'self'` に弾かれて画面が真っ白**になる（実測 46 件の違反）。
     *
     * ここでは「検査のあとも、同じ host:port で URL を組めるか」を見る。
     */
    public function test_検査のあとも_ur_l_の組み立てが壊れない(): void
    {
        $before = url('/build/app.js');

        app(PublicationReadiness::class)->check();

        $this->assertSame(
            $before,
            url('/build/app.js'),
            '検査のあとに URL の根が変わっている（画面のアセットが CSP で弾かれる）',
        );
    }

    /** 内部検査が外側のログイン情報を使わず、セッションとCookieも変更しない。 */
    public function test_公開検査は匿名で行い外側のログイン状態を保つ(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner);
        $session = app('session.store');
        $session->start();
        $session->put('work-in-progress', 'retained');
        $before = [$session->getId(), $session->all()];
        $auth = app('auth');
        $cookie = app('cookie');
        $cookie->queue('outer-work', 'retained');
        Route::get('/sitemap.xml', fn () => '<urlset><url><loc>http://localhost/qa-anonymous</loc></url></urlset>');
        Route::middleware('web')->get('/qa-anonymous', function () {
            $this->assertNull(auth()->user());
            $this->assertNull(session('work-in-progress'));
            session()->put('inner-work', 'must-not-leak');
            cookie()->queue('inner-work', 'must-not-leak');

            return 'Ready';
        });

        app(PublicationReadiness::class)->check();

        $this->assertSame($auth, app('auth'));
        $this->assertSame($owner->id, auth()->id());
        $this->assertSame($session, app('session.store'));
        $this->assertSame($before, [$session->getId(), $session->all()]);
        $this->assertTrue($cookie->hasQueued('outer-work'));
        $this->assertFalse($cookie->hasQueued('inner-work'));
    }

    /** **サイト全体の状態なので、設定を触れない役割には見せない** */
    public function test_設定を触れない役割には見せない(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)->get('/admin/readiness')->assertForbidden();
    }

    /**
     * **「最初の 5 歩」の最後が、その画面を指している**（リンクの無い約束を残さない）。
     *
     * 行き先は画面から `App\Support\FirstSteps` へ移った（2026-09-03）ので、
     * **文字列ではなく実際に渡している値**を見る。こちらのほうが強く、文面を変えても腐らない。
     */
    public function test_最初の5歩の公開前チェックが行き先を持つ(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);

        $steps = $this->actingAs($admin)->get('/dashboard')->inertiaProps('firstSteps');
        $hrefs = array_column($steps['steps'], 'href', 'key');

        $this->assertSame(
            '/admin/readiness',
            $hrefs['readiness'] ?? null,
            '「公開前チェック」に行き先がありません',
        );
    }

    /**
     * 検査を検査する。**開ける URL で赤くしてはいけない。**
     */
    public function test_開ける_url_は赤にしない(): void
    {
        Route::get('/sitemap.xml', fn () => response(
            '<?xml version="1.0"?><urlset><url><loc>http://localhost/</loc></url></urlset>',
            200,
            ['Content-Type' => 'application/xml'],
        ));

        $findings = (new PublicationReadiness)->check();

        foreach ($findings as $f) {
            $this->assertStringNotContainsString('開けない', $f['what'],
                '開ける URL を赤にしている: '.$f['where']);
        }
    }

    public function test_メール未配信の指摘から設定手順へ進める(): void
    {
        config(['mail.default' => 'log']);
        $owner = User::factory()->create(['role' => 'owner']);

        $this->actingAs($owner)->get('/admin/readiness')->assertOk()
            ->assertInertia(function ($page): void {
                $page->where('findings', function ($findings): bool {
                    $mail = collect($findings)->firstWhere('where', 'メール設定');
                    $this->assertNotNull($mail);
                    $this->assertSame('blocker', $mail['level']);
                    $this->assertSame('#mail-settings-guide', $mail['fix']['href'] ?? null);
                    $this->assertSame('設定手順を見る', $mail['fix']['label'] ?? null);

                    return true;
                });
            });
    }
}
