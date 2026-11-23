<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Concerns\ChecksViews;
use App\Support\ContactEntry;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Tests\TestCase;

/**
 * **view の有無の調べ方を 1 か所にする**（D-2）。
 *
 * 🔴 **同じ知見が 4 か所に書き写されていた。**しかも中身が 2 通りに割れていた:
 *
 * ```
 * $this->views->exists()   BundledPages / DocumentPageView   ← 注入されたもの
 * view()->exists()         ContactEntry / PublicFormController ← 毎回引く
 * ```
 *
 * ▷ **割れていると、半分だけ試験が書けない。**注入していない口は
 * 「view が無いとき」を作れない（`BundledPagesTest` は偽の Factory を差し込んでいる）。
 * `readStdin()` が 1 つだけ `StreamableInputInterface` を見ていなかったのと同じ形。
 *
 * ▷ **docblock も 4 通りに書き分けられ、2 つは在りもしない関数を指していた**
 * （`PublicSiteController::importedViewExists()`）。**知見のコピーは腐る。**
 */
final class ViewExistenceContractTest extends TestCase
{
    /** view の有無を見るもの */
    private const HOLDERS = [
        'app/Support/BundledPages.php',
        'app/Support/ContactEntry.php',
        'app/Support/DocumentPageView.php',
        'app/Http/Controllers/PublicFormController.php',
    ];

    public function test_自前の_view_exists_を持つものが無い(): void
    {
        $offenders = [];

        foreach (self::HOLDERS as $path) {
            $body = (string) file_get_contents(base_path($path));

            if (preg_match('/private function viewExists\(/', $body) === 1) {
                $offenders[] = $path;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "view の有無の調べ方を自前で持っている:\n  ".implode("\n  ", $offenders),
        );
    }

    /**
     * 🔴 **綴りを数えるだけでは足りない。**
     * 1 か所に寄せた時点で、**何を見ているかを見なくなる**。
     * 注入した Factory を実際に使うことを確かめる — ここが `view()` に戻ると、
     * **試験から「view が無い」を作れなくなる**（気づけないまま半分が死ぬ）。
     */
    public function test_注入された_factory_を使う(): void
    {
        // Factory を独立して作り、PHP 8.5 の整形器でも匿名クラスを正しく解析できる形にする。
        $views = new class implements ViewFactory
        {
            public function exists($view): bool
            {
                return $view === 'ある.view';
            }

            public function file($path, $data = [], $mergeData = []): mixed
            {
                return null;
            }

            public function make($view, $data = [], $mergeData = []): mixed
            {
                return null;
            }

            public function share($key, $value = null): mixed
            {
                return null;
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

        $subject = new class($views)
        {
            use ChecksViews;

            public function __construct(private readonly ViewFactory $views) {}

            public function ask(string $name): bool
            {
                return $this->viewExists($name);
            }
        };

        $this->assertTrue($subject->ask('ある.view'));
        $this->assertFalse($subject->ask('ない.view'), '注入した Factory を見ていない');
    }

    /**
     * 🔴 **寄せた意味はここにある。**
     *
     * `ContactEntry` は 2026-08-29 まで `view()` を毎回引いていたので、
     * **「取り込んだ view がまだ無い」を試験から作れなかった。**
     * 注入に変えたので、いま作れる。
     *
     * これが作れないと、**「原稿が来る前に取り込み済みの画面へ切り替わる」**
     * という事故を試験で押さえられない。
     */
    public function test_view_が無いときを_試験から作れる(): void
    {
        $missing = new class implements ViewFactory
        {
            public function exists($view): bool
            {
                return false;
            }

            public function file($path, $data = [], $mergeData = []): mixed
            {
                return null;
            }

            public function make($view, $data = [], $mergeData = []): mixed
            {
                return null;
            }

            public function share($key, $value = null): mixed
            {
                return null;
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

        $entry = new ContactEntry($missing);

        $this->assertFalse(
            $entry->hasImportedView(),
            '取り込んだ view が無いのに、在る扱いになっている',
        );
    }
}
