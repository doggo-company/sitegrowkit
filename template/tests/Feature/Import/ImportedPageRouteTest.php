<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use AgentCms\Core\Application\ActorType;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationInterface;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Pages\DuplicatePage;
use AgentCms\Core\Application\Pages\PublishPage;
use AgentCms\Core\Components\ComponentRegistry;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageStatus;
use App\Console\Commands\ImportPageCommand;
use App\Http\Controllers\ImportedPageController;
use App\Models\ImportedPageLayout;
use App\Models\User;
use Database\Seeders\TemplateContentSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * トップ以外の取り込みページも、**元の URL** で管理画面の編集結果を出す（R03 続き (1)）。
 *
 * `routes/imported.php` が生成する route は `ImportedPageController` を通り、
 * 結び付いたページ（`imported_page_layouts`）が公開されていればそれ、無ければ静的な Blade を出す。
 * 同梱ページ（`/about` `/service`）も `PublicSiteController::bundled()` が同じ問いを立てる。
 *
 * ここで固定するのは 4 つ:
 *   1. 結び付いたページが公開されていれば、元の URL でそれが出る
 *   2. 無い・未公開・ゴミ箱なら静的な Blade（受け皿）が出る — 半端なものを公開面に出さない
 *   3. 運用者が先に作っていた同じ slug のページは乗っ取らない（結び付きで引く）
 *   4. 同梱の `/about` も同じ
 */
final class ImportedPageRouteTest extends TestCase
{
    use RefreshDatabase;

    private const TYPE = 'imported_company_about';

    private const HEADING = 'COMPANY IMPORTED';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TemplateContentSeeder::class);

        $this->dir = sys_get_temp_dir().'/agentcms-imported-route-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->dir.'/components');
        File::ensureDirectoryExists($this->dir.'/views/components/document');
        File::ensureDirectoryExists($this->dir.'/views/layouts/preserved');
        File::ensureDirectoryExists($this->dir.'/views/public');

        File::copyDirectory(base_path('content/components'), $this->dir.'/components');
        File::put($this->dir.'/components/'.self::TYPE.'.json', (string) json_encode([
            'type' => self::TYPE, 'label' => 'about', 'category' => 'imported', 'since' => 1,
            'propsSchema' => [
                'heading_1' => ['type' => 'string', 'label' => '見出し', 'max' => 200],
                'link_1_href' => ['type' => 'url', 'label' => 'リンク 1 の行き先'],
                'link_1_text' => ['type' => 'string', 'label' => 'リンク 1 の文言', 'max' => 200],
                'image_1_src' => ['type' => 'url', 'label' => '画像 1 の場所'],
                'image_1_alt' => ['type' => 'string', 'label' => '画像 1 の説明', 'max' => 200],
            ],
            'styleSchema' => ['visibility'], 'bindable' => [], 'allowedChildren' => [], 'allowedParents' => ['page'],
            'renderer' => ['kind' => 'blade', 'view' => 'components.document.imported-company-about'],
            'capabilities' => ['responsive' => ['visibility'], 'container' => false],
        ], JSON_UNESCAPED_UNICODE));
        File::put(
            $this->dir.'/views/components/document/imported-company-about.blade.php',
            '<section id="about"><h1>{{ $props[\'heading_1\'] ?? \'\' }}</h1>'
            .'<img src="{{ $props[\'image_1_src\'] ?? \'\' }}" alt="{{ $props[\'image_1_alt\'] ?? \'\' }}">'
            .'<a href="{{ $props[\'link_1_href\'] ?? \'\' }}">{{ $props[\'link_1_text\'] ?? \'上へ\' }}</a></section>'."\n",
        );
        File::put(
            $this->dir.'/views/layouts/preserved/company.blade.php',
            '<!DOCTYPE html><html><head><title>{{ $title ?? \'\' }}</title>'
            .'<link rel="canonical" href="{{ $canonical ?? url()->current() }}">@stack(\'head\')</head>'
            .'<body class="imported-shell"><main class="sub">@yield(\'content\')</main></body></html>'."\n",
        );
        // 静的な Blade（受け皿）
        File::put($this->dir.'/views/public/company.blade.php', '<html><body class="static-company">STATIC COMPANY</body></html>'."\n");

        $this->app->instance(ComponentRegistry::class, new ComponentRegistry($this->dir.'/components'));
        $this->app['view']->addLocation($this->dir.'/views');

        // routes/imported.php が生成するのと同じ形の route
        Route::middleware('web')->get('/company', ImportedPageController::class)
            ->defaults('view', 'public.company')
            ->defaults('layout', 'layouts.preserved.company')
            ->name('company');

        $dir = $this->dir;
        $this->beforeApplicationDestroyed(static fn () => File::deleteDirectory($dir));
    }

    public function test_結び付いたページが公開されていれば元の_url_でそれが出る(): void
    {
        $this->importCompany(publish: true);

        $this->get('/company')->assertOk()
            ->assertSee(self::HEADING)
            ->assertSee('href="#top"', false)
            ->assertSee('class="imported-shell"', false)
            ->assertDontSee('STATIC COMPANY');
    }

    public function test_ページが無ければ静的な_blade_が出る(): void
    {
        $this->get('/company')->assertOk()->assertSee('STATIC COMPANY');
    }

    public function test_管理画面の下書きプレビューも原本の枠を使う(): void
    {
        $id = $this->importCompany(publish: false);
        $this->actingAs(User::factory()->create())
            ->get('/admin/pages/'.$id.'/preview?canvas=1')->assertOk()
            ->assertSee('class="imported-shell"', false)
            ->assertSee(self::HEADING)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('/company')->assertNotFound();
    }

    public function test_未公開で作ったなら元の_url_は_404_で静的な本文も出ない(): void
    {
        $this->importCompany(publish: false);

        // 所有者は在るが公開していない。静的な Blade に落とすと取り込んだ元の本文が見える
        $this->get('/company')->assertNotFound();
    }

    public function test_運用者が先に作った同じ_slug_のページは乗っ取らない(): void
    {
        // 結び付きの無い「company」が先に在る（管理画面で作ったもの）。取り込みは kept で触らない
        $this->runCommand(['page' => [
            'title' => '運用者の会社概要', 'slug' => 'company', 'publish' => true,
            'blocks' => [['type' => 'section', 'props' => ['background' => '{colors.bg}']]],
        ]]);
        $kept = $this->runCommand($this->payload(publish: true));
        $this->assertSame('kept', $kept['action']);
        $this->assertSame(0, ImportedPageLayout::query()->count());

        // 元の URL は静的な Blade のまま（運用者のページは /company の fallback route が無いこの試験では出ない）
        $this->get('/company')->assertOk()->assertSee('STATIC COMPANY');
    }

    public function test_同梱の_about_も結び付いたページを先に出す(): void
    {
        File::put(
            $this->dir.'/views/layouts/preserved/about.blade.php',
            '<!DOCTYPE html><html><head>@stack(\'head\')</head><body class="imported-about">@yield(\'content\')</body></html>'."\n",
        );
        $out = $this->runCommand(['page' => [
            'title' => '会社', 'slug' => 'about', 'publish' => true,
            'layout' => 'layouts.preserved.about',
            'blocks' => [['type' => self::TYPE, 'props' => ['heading_1' => 'ABOUT IMPORTED', 'link_1_href' => '/']]],
        ]]);
        $this->assertSame('created', $out['action']);

        $this->get('/about')->assertOk()->assertSee('ABOUT IMPORTED')->assertSee('class="imported-about"', false);
    }

    // ── 公開を取り下げた本文は、元の URL で見えない（404） ────────

    public function test_公開を取り下げたら元の_url_は_404_で静的な本文も出ない(): void
    {
        $id = $this->importCompany(publish: true);
        $this->get('/company')->assertOk()->assertSee(self::HEADING);

        Page::query()->whereKey($id)->update(['status' => PageStatus::Draft->value, 'published_version_id' => null]);

        // 🔴 静的な Blade に落とすと「取り下げたのに元の本文が見える」
        $this->get('/company')->assertNotFound();
    }

    public function test_公開予約中は元の_url_は_404(): void
    {
        $id = $this->importCompany(publish: true);
        Page::query()->whereKey($id)->update(['published_at' => now()->addDay()]);

        $this->get('/company')->assertNotFound();
    }

    public function test_ゴミ箱に入れたら元の_url_は_404(): void
    {
        $id = $this->importCompany(publish: true);
        Page::query()->whereKey($id)->update(['status' => PageStatus::Trashed->value, 'trashed_at' => now()]);

        $this->get('/company')->assertNotFound();
    }

    public function test_公開版が無ければ元の_url_は_404(): void
    {
        $id = $this->importCompany(publish: true);
        Page::query()->whereKey($id)->update(['published_version_id' => null]);

        $this->get('/company')->assertNotFound();
    }

    // ── 複製は枠だけを引き継ぎ、元の URL を奪わない ────────────

    public function test_元を取り下げても複製は元の_url_に出ない(): void
    {
        $id = $this->importCompany(publish: true);
        $copy = $this->duplicate($id);
        $this->publish($copy->id);

        // 複製は枠を引き継ぐが所有者ではない
        $this->assertSame('layouts.preserved.company', ImportedPageLayout::query()->where('page_id', $copy->id)->value('view'));
        $this->assertNull(ImportedPageLayout::query()->where('page_id', $copy->id)->value('owned_view'));
        $this->assertSame('layouts.preserved.company', ImportedPageLayout::query()->where('page_id', $id)->value('owned_view'));

        Page::query()->whereKey($id)->update(['status' => PageStatus::Draft->value, 'published_version_id' => null]);

        // 🔴 view で引くと複製へ黙って切り替わる。所有者で引くので元の URL は 404、複製は自分の URL にだけ出る
        $this->get('/company')->assertNotFound();
        $this->get('/'.$copy->slug)->assertOk()->assertSee(self::HEADING)->assertSee('class="imported-shell"', false);
    }

    public function test_所有者は_1_つの枠に_1_枚だけ(): void
    {
        $id = $this->importCompany(publish: true);

        $this->expectException(QueryException::class);
        ImportedPageLayout::query()->create([
            'page_id' => $this->duplicate($id)->id,
            'view' => 'layouts.preserved.company',
            'owned_view' => 'layouts.preserved.company',
        ]);
    }

    // ── slug を変えたら元の URL は 301、新しい URL に本文 ────────

    public function test_slug_を変えたら元の_url_は_301_で新しい_url_に本文(): void
    {
        $id = $this->importCompany(publish: true);
        Page::query()->whereKey($id)->update(['slug' => 'company-2026']);

        $this->get('/company')->assertRedirect('/company-2026')->assertStatus(301);
        $this->get('/company-2026')->assertOk()
            ->assertSee(self::HEADING)
            ->assertSee('class="imported-shell"', false)
            ->assertSee('<link rel="canonical" href="'.url('/company-2026').'">', false);
    }

    public function test_slug_を変えて未公開なら元の_url_も_404(): void
    {
        $id = $this->importCompany(publish: true);
        Page::query()->whereKey($id)->update(['slug' => 'company-2026', 'status' => PageStatus::Draft->value, 'published_version_id' => null]);

        $this->get('/company')->assertNotFound();
        $this->get('/company-2026')->assertNotFound();
    }

    // ── 同梱の /about も同じ規則 ─────────────────────────

    public function test_同梱の_about_も取り下げれば_404_で_slug_変更は_301(): void
    {
        File::put(
            $this->dir.'/views/layouts/preserved/about.blade.php',
            '<!DOCTYPE html><html><head>@stack(\'head\')</head><body class="imported-about">@yield(\'content\')</body></html>'."\n",
        );
        $out = $this->runCommand(['page' => [
            'title' => '会社', 'slug' => 'about', 'publish' => true,
            'layout' => 'layouts.preserved.about',
            'blocks' => [['type' => self::TYPE, 'props' => ['heading_1' => 'ABOUT IMPORTED', 'link_1_href' => '/']]],
        ]]);
        $id = (int) $out['id'];
        $this->get('/about')->assertOk()->assertSee('ABOUT IMPORTED');

        Page::query()->whereKey($id)->update(['slug' => 'about-us']);
        $this->get('/about')->assertRedirect('/about-us')->assertStatus(301);
        $this->get('/about-us')->assertOk()->assertSee('ABOUT IMPORTED');

        Page::query()->whereKey($id)->update(['status' => PageStatus::Trashed->value, 'trashed_at' => now()]);
        $this->get('/about')->assertNotFound();
        $this->get('/about-us')->assertNotFound();
    }

    // ── URL の項目は kit の検証、文字は escape ──────────────

    public function test_危険な_url_は取り込みの時点で断られページも作られない(): void
    {
        foreach (['javascript:alert(1)', 'data:text/html,x', '//evil.example/x', 'vbscript:x', "java\nscript:alert(1)", '/\\evil.example'] as $bad) {
            $out = $this->runCommand($this->payloadWith(['link_1_href' => $bad]), expectFailure: true);

            $this->assertNotSame('success', $out['status'], $bad);
            $this->assertStringContainsString('使えないリンク先', json_encode($out, JSON_UNESCAPED_UNICODE) ?: '', $bad);
            $this->assertSame(0, Page::query()->where('slug', 'company')->count(), "{$bad} でページが残った");
        }

        // 数字だけのtelリンクも、スマホから押せるURLとして受け付ける。
        foreach (['/imported/images/a.png', '#top', 'https://example.com/x', 'mailto:a@example.com', 'tel:0120', 'tel:+81-3-1234-5678'] as $ok) {
            $out = $this->runCommand($this->payloadWith(['link_1_href' => $ok, 'image_1_src' => $ok]));
            $this->assertSame('created', $out['action'], $ok);
            Page::query()->whereKey((int) $out['id'])->delete();
        }
    }

    public function test_alt_とリンク文言は_escape_されて出る(): void
    {
        $out = $this->runCommand($this->payloadWith([
            'image_1_src' => '/imported/images/a.png',
            'image_1_alt' => '"><script>alert(1)</script>',
            'link_1_text' => '<b>太字</b>&<i>',
        ]));
        $this->assertSame('created', $out['action']);

        $html = (string) $this->get('/company')->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('alt="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"', $html);
        $this->assertStringNotContainsString('<b>太字</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;太字&lt;/b&gt;&amp;&lt;i&gt;', $html);
    }

    // ── 道具 ──────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function payload(bool $publish): array
    {
        return ['page' => [
            'title' => '会社概要', 'slug' => 'company', 'publish' => $publish,
            'layout' => 'layouts.preserved.company',
            'blocks' => [['type' => self::TYPE, 'props' => ['heading_1' => self::HEADING, 'link_1_href' => '#top']]],
        ]];
    }

    /**
     * @param  array<string, string>  $props
     * @return array<string, mixed>
     */
    private function payloadWith(array $props): array
    {
        return ['page' => [
            'title' => '会社概要', 'slug' => 'company', 'publish' => true,
            'layout' => 'layouts.preserved.company',
            'blocks' => [['type' => self::TYPE, 'props' => ['heading_1' => self::HEADING] + $props]],
        ]];
    }

    private function duplicate(int $pageId): Page
    {
        $copied = app(DuplicatePage::class)->execute($pageId, OperationContext::cli('editor'));
        $this->assertSame(OperationStatus::Success, $copied->status);

        /** @var array<string, mixed> $presented */
        $presented = $copied->data['page'] ?? [];

        return Page::query()->findOrFail((int) ($presented['id'] ?? 0));
    }

    private function publish(int $pageId): void
    {
        $publish = app(PublishPage::class);
        $result = $publish->execute($pageId, OperationContext::cli('template-import'));

        if ($result->status === OperationStatus::ConfirmationRequired) {
            $result = $publish->execute($pageId, new OperationContext(
                actorType: ActorType::System,
                interface: OperationInterface::Cli,
                actorLabel: 'template-import',
                confirmationToken: $result->confirmation?->token,
            ));
        }

        $this->assertSame(OperationStatus::Success, $result->status);
    }

    private function importCompany(bool $publish): int
    {
        $out = $this->runCommand($this->payload($publish));
        $this->assertSame('created', $out['action'] ?? null, json_encode($out, JSON_UNESCAPED_UNICODE) ?: '');
        $this->assertNotNull(Page::query()->find((int) $out['id']));

        return (int) $out['id'];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function runCommand(array $payload, bool $expectFailure = false): array
    {
        $stream = fopen('php://memory', 'r+');
        $this->assertNotFalse($stream);
        fwrite($stream, (string) json_encode($payload, JSON_UNESCAPED_UNICODE));
        rewind($stream);

        $input = new ArrayInput([]);
        $input->setStream($stream);
        $output = new BufferedOutput;

        $command = app(ImportPageCommand::class);
        $command->setLaravel(app());
        $exit = $command->run($input, $output);
        $raw = trim($output->fetch());
        fclose($stream);

        $this->assertSame($expectFailure ? 1 : 0, $exit, "終了コードが違う: {$raw}");

        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, "JSON を返していない: {$raw}");

        return $decoded;
    }
}
