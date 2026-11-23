<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * **画面と配布物に出る名前は SiteGrowKit**（2026-08-25 に改名）。
 *
 * 🔴 2026-08-31 に管理画面のタブと左上が「Agent CMS」のままなのを
 * メンテナが見つけた。**試験は全部緑だった** — 名前は動作に影響しないので、
 * 誰も見に行かなければ古いまま出続ける。**利用者が最初に見る文字である。**
 *
 * ▷ `AgentCms\Core` の名前空間とフォルダ名は**内部の識別子**なので触らない。
 *   ここで見るのは**人が読む場所だけ**。
 */
final class ProductNameTest extends TestCase
{
    /** 人が読む場所 */
    private const VISIBLE = [
        '.env.example',
        'resources',
        'lang',
        'config',
    ];

    private const OLD = ['Agent CMS', 'AgentCMS', 'Agent-CMS'];

    public function test_画面と配布物に古い名前が残っていない(): void
    {
        $offenders = [];

        foreach (self::VISIBLE as $target) {
            $path = base_path($target);

            if (is_file($path)) {
                $files = [$path];
            } else {
                $files = [];
                $it = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    if ($f->isFile() && ! str_contains($f->getPathname(), '/node_modules/')) {
                        $files[] = $f->getPathname();
                    }
                }
            }

            foreach ($files as $file) {
                $body = (string) file_get_contents($file);

                foreach (self::OLD as $old) {
                    if (str_contains($body, $old)) {
                        $offenders[] = str_replace(base_path().'/', '', $file).' → 「'.$old.'」';
                    }
                }
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($offenders)),
            "古い商品名が人の読む場所に残っている:\n  ".implode("\n  ", array_unique($offenders)),
        );
    }

    /**
     * 名前は小文字で書く。`SiteGrowKit` と書くと `php_unit_method_casing` が
     * `site_grow_kit` へ潰し、**読めない名前になったまま緑で通る**（2026-09-02 に実測）。
     */
    public function test_アプリ名が_sitegrowkit(): void
    {
        $this->assertSame('SiteGrowKit', config('app.name'));
    }
}
