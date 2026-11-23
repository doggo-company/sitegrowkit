<?php

declare(strict_types=1);

namespace Tests\Feature\Wxr;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 同じキーで衝突したとき、**どうすれば抜けられるか**まで言う
 * （WP 一周 2026-09-03・移行の下ごしらえで実際に詰まった）。
 *
 * ## 何が起きたか
 *
 * 移行の案内が古く、先に管理画面で分類を作ってしまった。そのあと
 * 取り込みに必要なファイル定義を同じキーで置くと、`agentcms:taxonomy-sync` が
 * 「管理画面が管理している分類と key が重なっています。定義ファイル側では
 * 変更できません。」で止まる。
 *
 * **何が起きたかは分かるが、どうすれば進めるかが分からない。**
 * キーは後から変えられず、一度でも使った分類は消せない。
 * 実際にここで詰まって、DB を直接触って抜けた。**買った人はそれをできない。**
 */
final class TaxonomyCollisionMessageTest extends TestCase
{
    use RefreshDatabase;

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = base_path('content/taxonomies');
    }

    protected function tearDown(): void
    {
        @unlink($this->dir.'/kasanari_test.json');

        parent::tearDown();
    }

    public function test_衝突したら抜け方まで言う(): void
    {
        DB::table('taxonomies')->insert([
            // id は ULID（自動採番ではない）
            'id' => (string) Str::ulid(),
            'key' => 'kasanari_test',
            'label' => '管理画面で作ったもの',
            'hierarchical' => false,
            'managed_by' => 'cms',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        file_put_contents($this->dir.'/kasanari_test.json', json_encode([
            'key' => 'kasanari_test',
            'label' => 'ファイルで作ったもの',
            'hierarchical' => false,
            'managed_by' => 'code',
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        Artisan::call('agentcms:taxonomy-sync');
        $out = Artisan::output();

        // 何が起きたか（これは前からある）
        $this->assertStringContainsString('kasanari_test', $out);

        // 🔴 どうすれば抜けられるか（ここが無かった）
        $this->assertStringContainsString('別のキー', $out, '抜け方が書かれていない');
        $this->assertStringContainsString('分類管理', $out, 'どこを見ればよいかが書かれていない');
    }
}
