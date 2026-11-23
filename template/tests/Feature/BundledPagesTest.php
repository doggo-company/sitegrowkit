<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\BundledPages;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 同梱ページを出してよいかの判断は **1 か所**（D-1）。
 *
 * ここで固定するのは 3 つ:
 *
 * 1. **`route` は在るが `view` が無いものを出さない**（押したら 404 になる）
 * 2. **同梱ページでないものは判断しない**（案件のページが黙って消えない）
 * 3. 🔴 **同じ問いに答える場所が 2 つに戻っていない**
 *
 * ▷ 元は **4 か所**が別々に答えていた（既定の導線 / 404 / sitemap / メニュー）。
 *   **3 つ目のページを足す日に 4 か所を思い出さないといけない**形で、
 *   忘れた場所は**黙って古い答えを出し続ける**。
 *   **4 つ目は 2026-08-28 に足したものだった**（数える側が増やしていた）。
 */
final class BundledPagesTest extends TestCase
{
    use RefreshDatabase;

    // ── 1. route は在るが view が無いものを出さない ──────

    public function test_中身が無い同梱ページは出さない(): void
    {
        $this->addRoute('about');
        $this->withMissingView('public.about');

        $this->assertFalse(app(BundledPages::class)->isAvailable('about'));
        $this->assertSame('中身がまだありません', app(BundledPages::class)->reason('about'));
    }

    public function test_中身が在る同梱ページは出す(): void
    {
        $this->addRoute('about');

        $this->assertTrue(app(BundledPages::class)->isAvailable('about'));
        $this->assertNull(app(BundledPages::class)->reason('about'));
    }

    public function test_route_が無ければ出さない(): void
    {
        $this->assertFalse(app(BundledPages::class)->isAvailable('nowhere'));
        $this->assertSame('行き先がありません', app(BundledPages::class)->reason('nowhere'));
    }

    // ── 2. 同梱ページでないものは判断しない ──────────────

    public function test_同梱ページでないものは_route_だけで決める(): void
    {
        // 🔴 判断できないものを false にすると、**案件のページが黙って消える**
        $this->addRoute('company');

        $this->assertTrue(app(BundledPages::class)->isAvailable('company'));
        $this->assertNull(app(BundledPages::class)->reason('company'));
    }

    public function test_sitemap_は中身が無い同梱ページを載せない(): void
    {
        // 🔴 載せると **sitemap から 404 へ誘導する**（検索エンジンにも人にも）
        $this->addRoute('about');
        $this->addRoute('service');
        $this->withMissingView('public.about');

        $xml = $this->get('/sitemap.xml')->assertOk()->getContent() ?: '';

        $this->assertStringNotContainsString('/about', $xml);
    }

    public function test_sitemap_は中身が在る同梱ページを載せる(): void
    {
        $this->addRoute('about');

        $this->assertStringContainsString(
            '/about',
            $this->get('/sitemap.xml')->assertOk()->getContent() ?: '',
        );
    }

    // ── 3. 判断が 1 か所に在る ───────────────────────────

    public function test_view_の名前を持っているのは_bundled_pages_だけ(): void
    {
        /*
         * 🔴 **同じ問いに答える場所が増えていないか**を見る。
         *
         * `public.about` / `public.service` という綴りが `app/` の中で
         * **`BundledPages` 以外に出てきたら、そこがもう 1 つの答えになっている。**
         */
        $offenders = [];

        foreach ($this->phpFiles(app_path()) as $file) {
            if (basename($file) === 'BundledPages.php') {
                continue;
            }

            $body = (string) file_get_contents($file);

            foreach (['public.about', 'public.service'] as $view) {
                if (str_contains($body, $view)) {
                    $offenders[] = basename($file).' → '.$view;
                }
            }
        }

        $this->assertSame([], $offenders, implode(' / ', $offenders));
    }

    // ── 道具 ──────────────────────────────────────────────

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        $out = [];

        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }

            $p = $dir.'/'.$e;
            $out = array_merge($out, is_dir($p) ? $this->phpFiles($p) : (str_ends_with($p, '.php') ? [$p] : []));
        }

        return $out;
    }

    /**
     * 試験の中で route を 1 本足す。
     *
     * 🔴 **名前の索引を作り直さないと `Route::has()` が false のまま**である
     * （索引は起動時に作られる）。
     */
    private function addRoute(string $name): void
    {
        Route::get('/'.$name, static fn () => '')->name($name);
        Route::getRoutes()->refreshNameLookups();
    }

    /**
     * その view が「無い」ことにする。
     *
     * 🔴 **ファイルを消して試さない。**同梱物を消すとほかの試験を巻き込む
     * （2026-08-28 に実際に 39 件落とした）。**view の有無だけを差し替える。**
     */
    private function withMissingView(string $missing): void
    {
        /*
         * **`BundledPages` は final なので継承しない。**
         * 見せる view の側を差し替える（判断そのものは本物を通す）。
         */
        $real = $this->app->make(ViewFactory::class);

        $this->app->instance(BundledPages::class, new BundledPages(
            new class($real, $missing) implements ViewFactory
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
            },
        ));
    }
}
