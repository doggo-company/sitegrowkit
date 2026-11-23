<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use AgentCms\Core\Menus\MenuItem;
use AgentCms\Core\Menus\MenuTree;
use App\Console\Commands\ImportMenuCommand;
use Database\Seeders\MenuSeeder;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * 取り込んだナビを CMS のメニューとして起こす（B-56）。
 *
 * ここで固定するのは 4 つ:
 *   1. **作ると本当に公開面へ出せる状態になる**（`MenuTree` が項目を返す）
 *   2. **押したら 404 になるものは入れない**（route が在るだけでは足りない）
 *   3. **既に在れば触らない**（運用者が組み替えたものを上書きしない）
 *   4. **外したものは黙って落とさず名前を返す**
 */
final class ImportMenuCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * メニューの器（`header` / `footer`）は同梱の seeder が作る。
         * **`SaveMenu` は器を作らない**ので、無い状態は「取り込み以前の問題」である。
         */
        $this->seed(MenuSeeder::class);

        // 器だけ在って項目が 0 件の状態から始める（seeder は見本の項目を入れる）
        MenuItem::query()->delete();

        /*
         * 案件が持つページの route を 1 本立てる。
         * **同梱の kit には無い**（案件ごとに生成されるもの）ので、
         * ここで作らないと「行き先がありません」で外れてしまう。
         */
        $this->addRoute('company');
    }

    // ── 1. 作れる ────────────────────────────────────────

    public function test_作ると公開面へ出せる状態になる(): void
    {
        $out = $this->importMenu($this->payload());

        $this->assertSame('created', $out['action']);
        $this->assertSame(2, $out['items']);

        // **ここが本題** — `MenuTree` が返さなければ公開面には何も出ない
        $items = app(MenuTree::class)->forKey('header', '/');
        $this->assertSame(['HOME', 'COMPANY'], array_column($items, 'title'));
        $this->assertSame('/#home', $items[0]['url']);
    }

    public function test_アンカーも入れられる(): void
    {
        // 画面内リンクは `custom` で表せる（`MenuLinkType::Custom` の定義どおり）
        $this->importMenu($this->payload());

        $this->assertSame('/#home', app(MenuTree::class)->forKey('header', '/')[0]['url']);
    }

    // ── 2. 押したら 404 になるものは入れない ─────────────

    public function test_route_が無い項目は外す(): void
    {
        $out = $this->importMenu($this->payload([
            ['title' => 'どこか', 'link_type' => 'route', 'link_route' => 'nowhere'],
        ]));

        $this->assertSame(1, $out['items']);
        $this->assertSame(['どこか（行き先がありません）'], $out['skipped']);
    }

    public function test_route_は在るが中身が無い項目も外す(): void
    {
        // 🔴 **route が在ることと、開けることは別。**
        //    入り口だけ残して view を消したページは、押すと 404 になる
        $this->addRoute('about');
        $this->withMissingView('public.about');

        $out = $this->importMenu($this->payload([
            ['title' => '私たちについて', 'link_type' => 'route', 'link_route' => 'about'],
        ]));

        $this->assertSame(1, $out['items']);
        $this->assertSame(['私たちについて（中身がまだありません）'], $out['skipped']);
    }

    public function test_中身が在る_route_は入る(): void
    {
        $out = $this->importMenu($this->payload([
            ['title' => '会社概要', 'link_type' => 'route', 'link_route' => 'company'],
        ]));

        $this->assertSame(2, $out['items']);
        $this->assertSame([], $out['skipped']);
    }

    public function test_入れられる項目が_1_つも無ければ断る(): void
    {
        $out = $this->importMenu([
            'menu' => 'header',
            'items' => [['title' => 'どこか', 'link_type' => 'route', 'link_route' => 'nowhere']],
        ], expectFailure: true);

        $this->assertStringContainsString('1 つもありません', $out['message']);
        $this->assertStringContainsString('どこか', $out['message']);
    }

    // ── 3. 既に在れば触らない ────────────────────────────

    public function test_項目が在るメニューは触らない(): void
    {
        $this->importMenu($this->payload());
        $again = $this->importMenu([
            'menu' => 'header',
            'items' => [['title' => '別の項目', 'link_type' => 'custom', 'link_url' => '/#x']],
        ]);

        $this->assertSame('kept', $again['action']);
        $this->assertSame(
            ['HOME', 'COMPANY'],
            array_column(app(MenuTree::class)->forKey('header', '/'), 'title'),
        );
    }

    // ── 4. 通らないものは黙って直さない ──────────────────

    public function test_受け付けない種類は断る(): void
    {
        $out = $this->importMenu([
            'menu' => 'header',
            'items' => [['title' => 'X', 'link_type' => 'page']],
        ], expectFailure: true);

        $this->assertStringContainsString('link_type', $out['message']);
    }

    public function test_受け付けないキーは断る(): void
    {
        $out = $this->importMenu([
            'menu' => 'header',
            'items' => [['title' => 'X', 'link_type' => 'custom', 'link_url' => '/#x', 'page_id' => 1]],
        ], expectFailure: true);

        $this->assertStringContainsString('page_id', $out['message']);
    }

    public function test_項目が空なら断る(): void
    {
        $out = $this->importMenu(['menu' => 'header', 'items' => []], expectFailure: true);

        $this->assertStringContainsString('1 つ以上', $out['message']);
    }

    public function test_項目が多すぎれば断る(): void
    {
        $out = $this->importMenu([
            'menu' => 'header',
            'items' => array_fill(0, 51, ['title' => 'X', 'link_type' => 'custom', 'link_url' => '/#x']),
        ], expectFailure: true);

        $this->assertStringContainsString('多すぎ', $out['message']);
        $this->assertSame(0, MenuItem::query()->count(), '項目を入れてから断っている');
    }

    public function test_断るときは原因が読める(): void
    {
        $out = $this->importMenu([
            'menu' => 'header',
            'items' => [['title' => 'X', 'link_type' => 'custom', 'link_url' => 'javascript:alert(1)']],
        ], expectFailure: true);

        $this->assertNotEmpty($out['errors'] ?? [], '原因が返っていない');
    }

    // ── 道具 ──────────────────────────────────────────────

    /**
     * その view が「無い」ことにする。
     *
     * 🔴 **ファイルを消して試さない。**同梱物を消すとほかの試験を巻き込む
     * （同じ日に 39 件落とした）。**view の有無だけを差し替える。**
     */
    private function withMissingView(string $missing): void
    {
        $real = $this->app->make(ViewFactory::class);

        $this->app->instance(ViewFactory::class, new class($real, $missing) implements ViewFactory
        {
            public function __construct(
                private readonly ViewFactory $inner,
                private readonly string $missing,
            ) {}

            public function exists($view): bool
            {
                return $view !== $this->missing && $this->inner->exists($view);
            }

            /**
             * @param  array<string, mixed>  $data
             * @param  array<string, mixed>  $mergeData
             */
            public function make($view, $data = [], $mergeData = []): View
            {
                return $this->inner->make($view, $data, $mergeData);
            }

            /**
             * @param  array<string, mixed>  $data
             * @param  array<string, mixed>  $mergeData
             */
            public function file($path, $data = [], $mergeData = []): View
            {
                return $this->inner->file($path, $data, $mergeData);
            }

            public function share($key, $value = null): mixed
            {
                return $this->inner->share($key, $value);
            }

            /** @return array<int, callable> */
            public function composer($views, $callback): array
            {
                return $this->inner->composer($views, $callback);
            }

            /** @return array<int, callable> */
            public function creator($views, $callback): array
            {
                return $this->inner->creator($views, $callback);
            }

            /** @param  string|array<int, string>  $hints */
            public function addNamespace($namespace, $hints): self
            {
                $this->inner->addNamespace($namespace, $hints);

                return $this;
            }

            /** @param  string|array<int, string>  $hints */
            public function replaceNamespace($namespace, $hints): self
            {
                $this->inner->replaceNamespace($namespace, $hints);

                return $this;
            }
        });
    }

    /**
     * 試験の中で route を 1 本足す。
     *
     * 🔴 **名前の索引を作り直さないと `Route::has()` が false のまま**である
     * （索引は起動時に作られる）。足しただけでは効かない。
     */
    private function addRoute(string $name): void
    {
        Route::get('/'.$name, static fn () => '')->name($name);
        Route::getRoutes()->refreshNameLookups();
    }

    /**
     * @param  list<array<string, mixed>>  $extra
     * @return array<string, mixed>
     */
    private function payload(array $extra = []): array
    {
        return ['menu' => 'header', 'items' => array_merge([
            ['title' => 'HOME', 'link_type' => 'custom', 'link_url' => '/#home'],
        ], $extra === [] ? [
            ['title' => 'COMPANY', 'link_type' => 'route', 'link_route' => 'company'],
        ] : $extra)];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function importMenu(array $payload, bool $expectFailure = false): array
    {
        $stream = fopen('php://memory', 'r+');
        $this->assertNotFalse($stream);
        fwrite($stream, (string) json_encode($payload, JSON_UNESCAPED_UNICODE));
        rewind($stream);

        $input = new ArrayInput([]);
        $input->setStream($stream);
        $output = new BufferedOutput;

        $command = app(ImportMenuCommand::class);
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
