<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\Pages\PageRepository;
use AgentCms\Core\Application\Settings\UpdateSettings;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageStatus;
use AgentCms\Core\Render\DocumentCss;
use AgentCms\Core\Render\DocumentRenderer;
use AgentCms\Core\Settings\SettingsStore;
use App\Models\ImportedPageLayout;
use App\Support\DocumentPageView;
use Database\Seeders\TemplateContentSeeder;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasOperator;
use Tests\TestCase;

/**
 * 公開 layout の 2 本立て（B-38 / B-55）。
 *
 * ここで固定するのは 3 つ:
 *
 * 1. **取り込んだ意匠の layout が在れば、document ページはそちらに載る**
 * 2. **無ければこれまでどおり** — 意匠を持たない案件の挙動は 1 バイトも変わらない
 * 3. **切り替えはファイルの有無だけ** — 設定値を増やさない
 *
 * ▷ これが無いと、取り込んだ案件で `front_page_id` を立てた瞬間に
 *   **意匠が全部落ちる**。実案件で実測すると `<style>` 58,751 字・
 *   ナビ・フッター・`<script>` 3 本が消えた（B-55）。
 *
 * ## 🔴 view のファイルを置いたり消したりして試さない
 *
 * `layouts/imported.blade.php` は **kit が同梱している実ファイル**である。
 * 試験で書き換えると同梱物を壊し、消すと**残り 39 件が
 * `View [layouts.imported] not found` で落ちる**（2026-08-28 に実際にやった）。
 * **在る側は実物で確かめ、無い側は view factory を差し替えて確かめる。**
 */
final class ImportedLayoutTest extends TestCase
{
    use HasOperator;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TemplateContentSeeder::class);
    }

    public function test_同梱の_layouts_imported_とは別物にする(): void
    {
        /*
         * 🔴 **`layouts.imported` で切り替えてはいけない。**
         *
         * あれは kit が**常に同梱している**ので、「在れば切り替える」にすると
         * **全案件の document ページが切り替わる**。`layouts.imported` には
         * CMS のナビ（`<x-navigation>`）が無いため、**メニューが消える**
         * （2026-08-28 に既存 4 件が実際に落ちた）。
         */
        $this->assertStringStartsNotWith('layouts.imported', DocumentPageView::PRESERVED_LAYOUT_PREFIX);
        $this->assertFalse(
            view()->exists(DocumentPageView::PRESERVED_LAYOUT_PREFIX.'front-0'),
            'kit は preserved layout を同梱しない（案件側で生成するもの）',
        );
    }

    public function test_同梱していない状態では今までどおり(): void
    {
        // 意匠を持たない案件の挙動は 1 バイトも変わらない
        $this->makeFrontPage();

        $this->get('/')
            ->assertOk()
            ->assertSee('ページの中身', false)
            // どちらの枠でも、本文へ飛ぶ入口がある
            ->assertSee('本文へスキップ', false);
    }

    public function test_無ければ_kit_既定に落ちる(): void
    {
        // **ファイルを消して試さない。**view の有無だけを差し替える
        $view = $this->documentPageViewWith(existing: null, bound: false);

        // 結び付いた枠が無ければ、サイトの枠（見本・取り込んだテーマ）に載る（UX 一周 2026-09-28）
        $this->assertSame(app(DocumentPageView::class)->siteLayout(), $view['layout']);
    }

    public function test_ページに結び付けられた_layout_が在れば取り込んだ側を選ぶ(): void
    {
        $view = $this->documentPageViewWith(existing: 'own', bound: true);

        $this->assertSame('layouts.preserved.'.$view['slug'], $view['layout']);
    }

    public function test_結び付いていないページには_同名のファイルが在っても当たらない(): void
    {
        /*
         * 🔴 **枠が当たるのは `imported_page_layouts` に行があるページだけ**（Codex 続き ①）。
         * ファイルの有無（slug）で当てると、取り込みの前から在った同名のページにも
         * 新しい枠が当たり、`kept` で「触らない」と言った保証が破れる。
         */
        $view = $this->documentPageViewWith(existing: 'own', bound: false);

        // 結び付いた枠が無ければ、サイトの枠（見本・取り込んだテーマ）に載る（UX 一周 2026-09-28）
        $this->assertSame(app(DocumentPageView::class)->siteLayout(), $view['layout']);
    }

    public function test_結び付いた_layout_のファイルが無ければ_kit_既定に落ちる(): void
    {
        // 別テンプレートの再取り込みで枠が消えた等。500 にしない
        $view = $this->documentPageViewWith(existing: null, bound: true);

        // 結び付いた枠が無ければ、サイトの枠（見本・取り込んだテーマ）に載る（UX 一周 2026-09-28）
        $this->assertSame(app(DocumentPageView::class)->siteLayout(), $view['layout']);
    }

    public function test_slug_を変えても結び付きは外れない(): void
    {
        $view = $this->documentPageViewWith(existing: 'own', bound: true, renameTo: 'renamed-top');

        // 結び付きは id なので、ファイルの名前は元の slug のまま使い続ける
        $this->assertSame('layouts.preserved.'.$view['originalSlug'], $view['layout']);
    }

    public function test_layout_の名前は_1_か所で決める(): void
    {
        // 綴りを 2 か所に置くと、片方だけ直した日に「置いたのに載らない」になる
        $this->assertSame('layouts.preserved.', DocumentPageView::PRESERVED_LAYOUT_PREFIX);
    }

    // ── 道具 ──────────────────────────────────────────────

    /**
     * view factory だけを差し替えて `render()` に渡された値を覗く。
     *
     * @param  string|null  $existing  在ることにする layout ファイル。`'own'` = `layouts.preserved.<元の slug>`。null = 無い
     * @param  bool  $bound  `imported_page_layouts` にこのページの行を置くか
     * @param  string|null  $renameTo  結び付けたあとで slug を変える
     * @return array<string, mixed>
     */
    private function documentPageViewWith(?string $existing, bool $bound, ?string $renameTo = null): array
    {
        $page = Page::query()->findOrFail($this->makeFrontPage());
        $originalSlug = $page->slug;
        $ownName = DocumentPageView::PRESERVED_LAYOUT_PREFIX.$originalSlug;
        $existingName = $existing === 'own' ? $ownName : $existing;

        if ($bound) {
            ImportedPageLayout::query()->create(['page_id' => $page->id, 'view' => $ownName, 'source' => 'index.html']);
        }

        if ($renameTo !== null) {
            $page->forceFill(['slug' => $renameTo])->save();
            $page->refresh();
        }

        $captured = [];

        $factory = new class($existingName, $captured) implements ViewFactory
        {
            /** @param array<string, mixed> $captured */
            public function __construct(
                private readonly ?string $existingName,
                public array &$captured,
            ) {}

            public function exists($view): bool
            {
                return str_starts_with((string) $view, DocumentPageView::PRESERVED_LAYOUT_PREFIX)
                    ? $view === $this->existingName
                    : true;
            }

            /**
             * @param  array<string, mixed>  $data
             * @param  array<string, mixed>  $mergeData
             */
            public function make($view, $data = [], $mergeData = []): View
            {
                $this->captured = $data;

                return app('view')->make('public.document', [
                    'title' => '', 'metaDescription' => null, 'canonical' => null,
                    'document' => '', 'themeCss' => '', 'layout' => 'layouts.public',
                ]);
            }

            public function file($path, $data = [], $mergeData = []): View
            {
                return $this->make($path, $data, $mergeData);
            }

            public function share($key, $value = null): mixed
            {
                return $value;
            }

            public function composer($views, $callback): array
            {
                return [];
            }

            public function creator($views, $callback): array
            {
                return [];
            }

            public function addNamespace($namespace, $hints): self
            {
                return $this;
            }

            public function replaceNamespace($namespace, $hints): self
            {
                return $this;
            }
        };

        $version = $page->publishedVersion;
        $this->assertNotNull($version);

        (new DocumentPageView(
            app(DocumentRenderer::class),
            app(SettingsStore::class),
            app(DocumentCss::class),
            $factory,
        ))->render($page, $version);

        return $factory->captured + ['slug' => $page->slug, 'originalSlug' => $originalSlug];
    }

    /** 中身のあるページを作って、トップに指定する */
    private function makeFrontPage(): int
    {
        $page = Page::query()->create([
            'title' => 'トップ',
            'slug' => 'front-'.Page::query()->count(),
            'status' => PageStatus::Published->value,
            'managed_by' => Page::MANAGED_BY_DOCUMENT,
        ]);

        $version = app(PageRepository::class)->createVersion($page, [
            'schemaVersion' => 1,
            'root' => [
                'id' => 'root', 'key' => 'page', 'type' => 'page', 'props' => [],
                'children' => [[
                    'id' => 'n1', 'key' => 'h', 'type' => 'heading',
                    'props' => ['text' => 'ページの中身', 'level' => 2], 'children' => [],
                ]],
            ],
        ], 'cli', null);

        $page->forceFill([
            'published_version_id' => $version->id,
            'published_at' => now(),
        ])->save();

        app(UpdateSettings::class)->execute(
            ['front_page_id' => (string) $page->id],
            OperationContext::cli('test'),
            partial: true,
        );

        return $page->id;
    }
}
