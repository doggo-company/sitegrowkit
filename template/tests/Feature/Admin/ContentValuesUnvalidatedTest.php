<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use AgentCms\Core\Application\Content\ContentValues;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\News\Models\News;
use Tests\TestCase;

/**
 * `ContentValues::split()` は **検証されていない値を渡されても安全に扱う**
 * （Phase 9.15 / STAGE 28B・SPEC §C）。
 *
 * ## なぜこの試験が要るのか
 *
 * 以前この関数の docblock は「**検証済みの値**」と書いていた。
 * 自動保存は**書きかけの値**をそのまま渡すので、その契約は事実でなくなった。
 *
 * **docblock を直しただけでは守りにならない。** 次に読む人が
 * 「検証済みなんだから、この `is_string` は要らない」と外せてしまう。
 * だから**主張のほうを検査で固定する**。
 */
final class ContentValuesUnvalidatedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * **richtext は、検証されていなくても Purifier を通る。**
     *
     * ここが自動保存の安全の 1 本目である（§C の 3 つのうちの 1 つ)。
     */
    public function test_検証していないrichtextもpurifierを通る(): void
    {
        $split = app(ContentValues::class)->split(News::schema(), [
            'body' => '<p>のこる</p><script>alert(1)</script>',
        ]);

        $body = (string) ($split['columns']['body'] ?? '');

        $this->assertStringContainsString('のこる', $body);
        $this->assertStringNotContainsString('<script>', $body, 'script がそのまま残った');
    }

    /**
     * 型が想定と違っても、**落ちずに倒す**。
     *
     * 書きかけの画面からは、text の欄に配列が来ることも、
     * richtext に数値が来ることもある（送信の途中経過・壊れた JS）。
     * **例外を投げると自動保存が動かなくなる**ので、倒して受け取る。
     */
    public function test_型が違っても落ちない(): void
    {
        $split = app(ContentValues::class)->split(News::schema(), [
            // richtext に文字列でないもの
            'body' => ['配列が来た'],
            // text に配列
            'title' => ['配列が来た'],
            // 日付に数値でないもの
            'published_at' => ['壊れた'],
        ]);

        // 落ちずに返ること自体がこの試験の主張である
        $this->assertArrayHasKey('columns', $split);
        $this->assertNull($split['columns']['body'], 'richtext は文字列でなければ null へ倒す');
    }

    /**
     * **schema に無いキーは通さない。**
     *
     * 書きかけの画面は余計なキーを送ってくることがある（UI 内部の状態など）。
     * それが列や data へ紛れ込むと、**版に schema 外のものが積まれる**。
     */
    public function test_schemaに無いキーは落とす(): void
    {
        $split = app(ContentValues::class)->split(News::schema(), [
            'title' => '見出し',
            '_ui_scroll_position' => 420,
            'password' => 'ひみつ',
        ]);

        $this->assertSame(['title' => '見出し'], $split['columns']);
    }
}
