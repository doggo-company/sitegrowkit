<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use AgentCms\Core\Application\ActorType;
use AgentCms\Core\Application\OperationContext;
use AgentCms\Core\Application\OperationInterface;
use AgentCms\Core\Application\OperationStatus;
use AgentCms\Core\Application\Pages\DuplicatePage;
use AgentCms\Core\Application\Pages\PatchPageDocument;
use AgentCms\Core\Application\Pages\PublishPage;
use AgentCms\Core\Backups\DatabaseDump;
use AgentCms\Core\Components\ComponentRegistry;
use AgentCms\Core\Pages\Page;
use AgentCms\Core\Pages\PageStatus;
use AgentCms\Core\Pages\PageVersion;
use AgentCms\Core\Settings\SettingsStore;
use App\Console\Commands\ImportPageCommand;
use App\Models\ImportedPageLayout;
use Database\Seeders\TemplateContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * 取り込んだトップをページとして起こし、**元の URL（`/`）で画面から直せる**ことを固定する（R03 / B-42）。
 *
 * CLI（`agentcms template apply`）が書き出すのは
 *   - `content/components/imported_<page>_<節>.json`（部品の定義）
 *   - `resources/views/components/document/imported-<page>-<節>.blade.php`（原本の markup そのまま）
 *   - `resources/views/layouts/preserved/home.blade.php`（原本の枠そのまま。**そのページ専用**）
 * で、そのあと `agentcms:import-page` → `agentcms:site-setting front_page_id` を呼ぶ。
 * ここではその**書き出された状態**を一時ディレクトリに作り、CMS 側の残り 2 手と表示を実物で通す。
 *
 * ここで固定するのは 4 つ:
 *   1. **`/` がページを出す** — 静的な Blade（`public.home`）が編集ページを隠さない
 *   2. **意匠は原本のまま** — 枠（style / nav / footer）と節の `id`（ナビのアンカー）が保たれる
 *   3. **管理画面の編集は、公開して初めて `/` に出る**（下書きは出ない）
 *   4. **`/home` も同じ中身を出し、正規 URL は `/`**
 *   5. **取り込んだ枠は、取り込んだページにしか当たらない**（新規・WXR のページは kit 既定のまま）
 *   6. **複製は見た目も本文も元と一致し、独立に編集でき、結び付きはバックアップで戻る**
 *
 * ## 🔴 kit の `resources/views` / `content/components` にファイルを置かない
 *
 * 同梱物を書き換えると他の試験を巻き込む（`ImportPageCommandTest` で実際に 39 件落とした）。
 * 部品は写した一時ディレクトリを registry に見せ、view は一時ディレクトリを **finder の
 * 探索先に足す**（kit に無い名前だけがそこへ落ちる）。
 */
final class ImportedFrontPageTest extends TestCase
{
    use RefreshDatabase;

    private const ACTOR = 'template-import';

    private const TYPE = 'imported_home_concept';

    private const VIEW = 'components.document.imported-home-concept';

    /** 原本の見出し。**既定のトップには出てこない文字列** */
    private const HEADING = 'CONCEPT ORIGINAL';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TemplateContentSeeder::class);

        $this->dir = sys_get_temp_dir().'/agentcms-imported-front-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->dir.'/components');
        File::ensureDirectoryExists($this->dir.'/views/components/document');
        File::ensureDirectoryExists($this->dir.'/views/layouts/preserved');

        // ---- 部品の定義（CLI の buildImportedSectionComponent() が書く形）----
        File::copyDirectory(base_path('content/components'), $this->dir.'/components');
        File::put($this->dir.'/components/'.self::TYPE.'.json', (string) json_encode([
            'type' => self::TYPE,
            'label' => 'concept（CONCEPT）',
            'category' => 'imported',
            'since' => 1,
            'propsSchema' => [
                'heading_1' => ['type' => 'string', 'label' => '見出し（CONCEPT）', 'max' => 200],
                'body_1' => ['type' => 'string', 'label' => '本文（植物が好き。）', 'max' => 2000],
            ],
            'styleSchema' => ['visibility'],
            'bindable' => [],
            'allowedChildren' => [],
            'allowedParents' => ['page'],
            'renderer' => ['kind' => 'blade', 'view' => self::VIEW],
            'capabilities' => ['responsive' => ['visibility'], 'container' => false],
        ], JSON_UNESCAPED_UNICODE));

        // ---- 部品の view（原本の markup そのもの。id は原本のまま）----
        File::put(
            $this->dir.'/views/components/document/imported-home-concept.blade.php',
            '<section id="concept" class="concept-block"><h2>{{ $props[\'heading_1\'] ?? \'\' }}</h2>'
            .'<p>{{ $props[\'body_1\'] ?? \'\' }}</p><a href="#contact">詳しく見る</a></section>'."\n",
        );

        // ---- 原本の枠（buildImportedLayout() が書く形の最小版）----
        File::put(
            $this->dir.'/views/layouts/preserved/home.blade.php',
            '<!DOCTYPE html><html lang="ja"><head><title>{{ $title ?? \'\' }}</title>'
            .'<style>.concept-block{color:#0f4c46}</style>'
            .'<link rel="canonical" href="{{ $canonical ?? url()->current() }}">'
            ."@stack('head')</head>"
            .'<body class="imported-shell"><nav id="navbar"><a href="#concept">こだわり</a></nav>'
            ."@yield('content')"
            .'<footer class="footer">みどり造園</footer></body></html>'."\n",
        );

        $this->app->instance(ComponentRegistry::class, new ComponentRegistry($this->dir.'/components'));
        $this->app['view']->addLocation($this->dir.'/views');

        $dir = $this->dir;
        $this->beforeApplicationDestroyed(static fn () => File::deleteDirectory($dir));
    }

    // ── 1 + 2. / がページを、原本の枠と id で出す ──────────

    public function test_トップに指定すると_slash_が原本の枠と節を出す(): void
    {
        $id = $this->importFrontPage();

        $response = $this->get('/')->assertOk();

        // 中身（原本の文言）が出ている。題だけだと <title> で緑になるので中身を見る
        $response->assertSee(self::HEADING);
        $response->assertSee('植物が好き。');

        // 節の id は原本のまま = ナビのアンカー `#concept` が死なない
        $response->assertSee('<section id="concept" class="concept-block">', false);

        // 枠は原本のまま（style / nav / footer）
        $response->assertSee('.concept-block{color:#0f4c46}', false);
        $response->assertSee('class="imported-shell"', false);
        $response->assertSee('<nav id="navbar">', false);
        $response->assertSee('<footer class="footer">みどり造園</footer>', false);

        // kit 既定の layout（layouts.public）にしか無いものは出ない
        $response->assertDontSee('本文へスキップ');

        // 🔴 静的なトップ（public.home）が編集ページを隠していない
        $response->assertDontSee('class="hero"', false);

        $this->assertSame((string) $id, (string) app(SettingsStore::class)->get('front_page_id'));
    }

    // ── 4. /home も同じ。正規 URL は / ─────────────────────

    public function test_slug_の_url_でも同じ中身を出し_正規_url_はルート(): void
    {
        $this->importFrontPage();

        $canonical = '<link rel="canonical" href="'.url('/').'">';

        $this->get('/home')->assertOk()
            ->assertSee(self::HEADING)
            ->assertSee('<section id="concept"', false)
            ->assertSee($canonical, false);

        $this->get('/')->assertOk()->assertSee($canonical, false);
    }

    // ── 3. 編集は公開して初めて / に出る ────────────────────

    public function test_管理画面の編集は公開後に_slash_へ反映される(): void
    {
        $id = $this->importFrontPage();
        $node = $this->firstChildId($id);

        $edited = app(PatchPageDocument::class)->execute($id, [
            'operation' => 'component.update',
            'target' => $node,
            'changes' => ['props' => ['heading_1' => '新しい見出し']],
        ], OperationContext::cli('editor'));

        $this->assertSame(OperationStatus::Success, $edited->status, json_encode($edited->errors, JSON_UNESCAPED_UNICODE) ?: '');

        // 下書きの段では、公開面はまだ原本のまま
        $this->get('/')->assertOk()->assertSee(self::HEADING)->assertDontSee('新しい見出し');

        $this->publish($id);

        // 公開すると / に反映され、原本の文言は消える。枠と id はそのまま
        $this->get('/')->assertOk()
            ->assertSee('新しい見出し')
            ->assertDontSee(self::HEADING)
            ->assertSee('<section id="concept" class="concept-block">', false)
            ->assertSee('.concept-block{color:#0f4c46}', false);
    }

    public function test_取り込んだ部品は_generic_の入れ物ではなく_原本の_view_で描く(): void
    {
        $this->importFrontPage();

        // kit の section renderer は `doc-section` を出す。取り込んだ節はそれを通らない
        $this->get('/')->assertOk()->assertDontSee('doc-section', false);
    }

    // ── 5. 取り込んだ枠は、取り込んだページにしか当たらない ────

    public function test_別のページは_kit_既定の枠のまま(): void
    {
        $this->importFrontPage();

        // 管理画面で新しく作ったページ（取り込みとは無関係）
        $out = $this->importPage([
            'page' => [
                'title' => '会社概要',
                'slug' => 'company',
                'publish' => true,
                'blocks' => [['type' => 'section', 'props' => ['background' => '{colors.bg}']]],
            ],
        ]);
        $this->assertSame('created', $out['action']);

        // 🔴 Codex 指摘 ②: 1 本の layouts.preserved を全ページに当てると、ここが原本の枠に載る
        $this->get('/company')->assertOk()
            ->assertSee('本文へスキップ')
            ->assertDontSee('class="imported-shell"', false)
            ->assertDontSee('.concept-block{color:#0f4c46}', false);

        // 取り込んだ方は変わらず原本の枠
        $this->get('/')->assertOk()->assertSee('class="imported-shell"', false);
    }

    // ── 6. 枠が当たるのは、取り込みが作ったそのページだけ（id で結ぶ） ────

    public function test_取り込みの前から在った同名ページには枠が当たらない(): void
    {
        // 運用者が先に「home」を作っていた世界。取り込みは kept で触らない — 枠も当てない
        $existing = $this->importPage([
            'page' => [
                'title' => '以前からのホーム',
                'slug' => 'home',
                'publish' => true,
                'blocks' => [['type' => 'section', 'props' => ['background' => '{colors.bg}']]],
            ],
        ]);
        $this->assertSame('created', $existing['action']);

        $kept = $this->importPage([
            'page' => [
                'title' => 'ホーム',
                'slug' => 'home',
                'publish' => true,
                'layout' => 'layouts.preserved.home',
                'blocks' => [['type' => self::TYPE, 'props' => ['heading_1' => self::HEADING, 'body_1' => 'x']]],
            ],
        ]);
        $this->assertSame('kept', $kept['action']);

        // 🔴 Codex 続き ①: ファイル layouts/preserved/home.blade.php は在るが、このページには当たらない
        $this->get('/home')->assertOk()
            ->assertSee('本文へスキップ')
            ->assertDontSee('class="imported-shell"', false);
    }

    public function test_slug_を変えても枠は外れない(): void
    {
        $id = $this->importFrontPage();

        Page::query()->whereKey($id)->update(['slug' => 'top']);

        $this->get('/top')->assertOk()
            ->assertSee(self::HEADING)
            ->assertSee('class="imported-shell"', false);
        // 旧 slug は無くなる（ファイルの名前は元のままでよい）
        $this->get('/home')->assertNotFound();
    }

    public function test_複製したページは同じ枠と同じ本文で出る(): void
    {
        $id = $this->importFrontPage();
        $copy = $this->duplicate($id);
        $this->publish($copy->id);

        // 🔴 複製は見た目も内容も元と一致する（Codex 続き (3)）。枠の結び付きも写る
        $this->assertSame(
            'layouts.preserved.home',
            ImportedPageLayout::query()->where('page_id', $copy->id)->value('view'),
        );
        $this->get('/'.$copy->slug)->assertOk()
            ->assertSee(self::HEADING)
            ->assertSee('<section id="concept" class="concept-block">', false)
            ->assertSee('class="imported-shell"', false)
            ->assertSee('.concept-block{color:#0f4c46}', false)
            ->assertDontSee('本文へスキップ');
    }

    public function test_複製は元と独立に編集でき_slug_を変えても枠は残る(): void
    {
        $id = $this->importFrontPage();
        $copy = $this->duplicate($id);

        // 複製だけを直して公開 → 複製にだけ反映。元（/）は原本の文言のまま
        $node = $this->firstChildId($copy->id);
        $edited = app(PatchPageDocument::class)->execute($copy->id, [
            'operation' => 'component.update',
            'target' => $node,
            'changes' => ['props' => ['heading_1' => '複製だけの見出し']],
        ], OperationContext::cli('editor'));
        $this->assertSame(OperationStatus::Success, $edited->status);
        $this->publish($copy->id);

        Page::query()->whereKey($copy->id)->update(['slug' => 'home-2026']);

        $this->get('/home-2026')->assertOk()
            ->assertSee('複製だけの見出し')
            ->assertSee('class="imported-shell"', false);
        $this->get('/')->assertOk()
            ->assertSee(self::HEADING)
            ->assertDontSee('複製だけの見出し');
    }

    public function test_結び付きの無いページの複製は_kit_既定のまま(): void
    {
        $plain = $this->importPage([
            'page' => [
                'title' => '会社概要', 'slug' => 'company', 'publish' => true,
                'blocks' => [['type' => 'section', 'props' => ['background' => '{colors.bg}']]],
            ],
        ]);
        $copy = $this->duplicate((int) $plain['id']);
        $this->publish($copy->id);

        $this->assertSame(0, ImportedPageLayout::query()->where('page_id', $copy->id)->count());
        $this->get('/'.$copy->slug)->assertOk()->assertSee('本文へスキップ');
    }

    public function test_複製の枠の写しに失敗したら複製ごと巻き戻る(): void
    {
        $id = $this->importFrontPage();

        // 枠の結び付きを書く瞬間に落とす
        ImportedPageLayout::creating(static function (): void {
            throw new \RuntimeException('killed');
        });

        try {
            app(DuplicatePage::class)->execute($id, OperationContext::cli('editor'));
            $this->fail('例外が外へ出ていない');
        } catch (\RuntimeException $e) {
            $this->assertSame('killed', $e->getMessage());
        }

        // 枠だけ欠けた複製が残っていない
        $this->assertSame(1, Page::query()->count());
        $this->assertSame(1, ImportedPageLayout::query()->count());
    }

    public function test_結び付きはバックアップの取得と復元で戻る(): void
    {
        $id = $this->importFrontPage();
        $path = sys_get_temp_dir().'/agentcms-layouts-'.bin2hex(random_bytes(6)).'.jsonl';
        $dump = app(DatabaseDump::class);

        // 取得の対象に入っている（除外表ではない）
        $this->assertContains('imported_page_layouts', $dump->tables());
        $counts = $dump->write($path);
        $this->assertSame(1, $counts['imported_page_layouts'] ?? null, '取得に 1 行も入っていない');

        ImportedPageLayout::query()->delete();
        $this->get('/')->assertOk()->assertSee('本文へスキップ');

        /*
         * この表の replay で行が戻る。
         *
         * ▷ ここでは `imported_page_layouts` だけを戻す。全表の復元（`pages` を消して入れ直す）は
         *   sqlite の**取引の中**では `PRAGMA foreign_keys = OFF` が効かず、`pages` の delete が
         *   cascade で子行（この表・`page_versions`）を消してしまう — sqlite の制約で、
         *   MySQL（`FOREIGN_KEY_CHECKS=0` は cascade も止める）では起きない。
         *   全表の順序を含む復元は隔離 MySQL の `BackupRestore*Test` が見ている。
         */
        DB::transaction(fn () => $dump->restore($path, ['imported_page_layouts']));
        @unlink($path);

        $this->assertSame(
            'layouts.preserved.home',
            ImportedPageLayout::query()->where('page_id', $id)->value('view'),
        );
        $this->get('/')->assertOk()->assertSee('class="imported-shell"', false);
    }

    // ── 7. トップも「取り下げたら見えない」 ───────────────────

    public function test_トップを取り下げたら_slash_は_404_で静的な本文も出ない(): void
    {
        $id = $this->importFrontPage();
        $this->get('/')->assertOk()->assertSee(self::HEADING);

        Page::query()->whereKey($id)->update(['status' => PageStatus::Draft->value, 'published_version_id' => null]);

        // 🔴 front_page_id が外れて静的な public.home に落ちると、取り下げた元の本文が見える
        $this->get('/')->assertNotFound();
        $this->get('/home')->assertNotFound();
    }

    public function test_トップをゴミ箱に入れたら_slash_は_404(): void
    {
        $id = $this->importFrontPage();
        Page::query()->whereKey($id)->update(['status' => PageStatus::Trashed->value, 'trashed_at' => now()]);

        $this->get('/')->assertNotFound();
    }

    public function test_運用者が別のページをトップに選べば_そちらが_slash_に出る(): void
    {
        $this->importFrontPage();
        $other = $this->importPage(['page' => [
            'title' => '別のトップ', 'slug' => 'top2', 'publish' => true,
            'blocks' => [['type' => 'section', 'props' => ['background' => '{colors.bg}']]],
        ]]);
        $setting = $this->artisan('agentcms:site-setting', ['key' => 'front_page_id', 'value' => (string) $other['id']]);
        $this->assertSame(0, $setting instanceof PendingCommand ? $setting->run() : $setting);

        // front_page_id の選択が勝つ。取り込みのトップは /home にだけ出る
        $this->get('/')->assertOk()->assertDontSee(self::HEADING)->assertSee('本文へスキップ');
        $this->get('/home')->assertOk()->assertSee(self::HEADING);
    }

    // ── 道具 ──────────────────────────────────────────────

    /** CLI が apply の最後にやる 2 手（import-page → site-setting）を、同じ口で通す */
    private function importFrontPage(): int
    {
        $out = $this->importPage([
            'page' => [
                'title' => 'ホーム',
                'slug' => 'home',
                'publish' => true,
                'layout' => 'layouts.preserved.home',
                'blocks' => [[
                    'type' => self::TYPE,
                    'props' => ['heading_1' => self::HEADING, 'body_1' => '植物が好き。'],
                ]],
            ],
        ]);

        $this->assertSame('created', $out['action'] ?? null, json_encode($out, JSON_UNESCAPED_UNICODE) ?: '');
        $this->assertTrue($out['published']);

        $id = (int) $out['id'];

        $setting = $this->artisan('agentcms:site-setting', ['key' => 'front_page_id', 'value' => (string) $id]);
        $this->assertSame(0, $setting instanceof PendingCommand ? $setting->run() : $setting);

        return $id;
    }

    private function duplicate(int $pageId): Page
    {
        $copied = app(DuplicatePage::class)->execute($pageId, OperationContext::cli('editor'));
        $this->assertSame(OperationStatus::Success, $copied->status, json_encode($copied->errors, JSON_UNESCAPED_UNICODE) ?: '');

        /** @var array<string, mixed> $presented */
        $presented = $copied->data['page'] ?? [];

        return Page::query()->findOrFail((int) ($presented['id'] ?? 0));
    }

    private function firstChildId(int $pageId): string
    {
        $page = Page::query()->findOrFail($pageId);
        $version = PageVersion::query()->findOrFail($page->draft_version_id);

        /** @var array<string, mixed> $document */
        $document = $version->document;
        $children = $document['root']['children'] ?? [];

        $this->assertNotEmpty($children);

        return (string) $children[0]['id'];
    }

    /** `ImportPageCommand::publish()` と同じ作法（確認 token を同じ呼び出し元で提示する） */
    private function publish(int $pageId): void
    {
        $publish = app(PublishPage::class);
        $result = $publish->execute($pageId, OperationContext::cli(self::ACTOR));

        if ($result->status === OperationStatus::ConfirmationRequired) {
            $result = $publish->execute($pageId, new OperationContext(
                actorType: ActorType::System,
                interface: OperationInterface::Cli,
                actorLabel: self::ACTOR,
                confirmationToken: $result->confirmation?->token,
            ));
        }

        $this->assertSame(OperationStatus::Success, $result->status, json_encode($result->errors, JSON_UNESCAPED_UNICODE) ?: '');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function importPage(array $payload): array
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

        $this->assertSame(0, $exit, "終了コードが違う: {$raw}");

        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, "JSON を返していない: {$raw}");

        return $decoded;
    }
}
