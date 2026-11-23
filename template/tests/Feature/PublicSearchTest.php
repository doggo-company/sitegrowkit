<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Content\PublicSearch;
use AgentCms\Core\Schema\ContentSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\News\Models\News;
use Tests\TestCase;

/**
 * 公開サイト内の検索（P1 ④）。
 *
 * ## LIKE で始める
 *
 * MySQL の FULLTEXT は既定の parser が空白区切りで、**日本語ではほぼ何も返さない**
 * （`ngram` parser が要る）。試験は SQLite で回っており、
 * **driver ごとに別の道を持つのは今日消したばかりの負債**である。
 * 速さが問題になった日に移す（`BACKLOG.md` B-10）。
 *
 * ## 一番怖いのは、出てはいけないものが出ること
 *
 * 検索は**普段は誰も見ない行**まで引き当てる。下書き・ゴミ箱・予約公開が
 * **検索の結果としてだけ漏れる**のが、この機能の最悪の壊れ方である。
 */
final class PublicSearchTest extends TestCase
{
    use RefreshDatabase;

    private function news(string $title, string $body, string $status = 'published'): void
    {
        DB::table('news')->insert([
            'title' => $title,
            'slug' => 'q-'.uniqid(),
            'body' => $body,
            'status' => $status,
            'published_at' => $status === 'published' ? now()->subDay() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_見出しで見つかる(): void
    {
        $this->news('春の新作について', '<p>本文</p>');
        $this->news('別のお知らせ', '<p>本文</p>');

        $html = (string) $this->get('/news?q='.urlencode('春の新作'))->assertOk()->getContent();

        $this->assertStringContainsString('春の新作について', $html);
        $this->assertStringNotContainsString('別のお知らせ', $html);
    }

    /** 宣言（`search`）が `body` を含むので、**本文でも見つかる** */
    public function test_本文でも見つかる(): void
    {
        $this->news('見出しには無い', '<p>ここにだけ「陶器市」と書いてある</p>');

        $html = (string) $this->get('/news?q='.urlencode('陶器市'))->assertOk()->getContent();

        $this->assertStringContainsString('見出しには無い', $html);
    }

    /**
     * **出てはいけないものが出ない。**
     *
     * ここが崩れると、**検索の結果としてだけ下書きが漏れる**。
     * 一覧を見ても気づけないので、**一番見つけにくい漏れ方**である。
     */
    public function test_下書きは検索でも出ない(): void
    {
        $this->news('未公開の秘密', '<p>まだ出せない話</p>', 'draft');

        $html = (string) $this->get('/news?q='.urlencode('秘密'))->assertOk()->getContent();

        $this->assertStringNotContainsString('未公開の秘密', $html);

        // 本文の語でも漏れない
        $this->assertStringNotContainsString(
            '未公開の秘密',
            (string) $this->get('/news?q='.urlencode('まだ出せない'))->assertOk()->getContent(),
        );
    }

    /** **0 件は 0 件と言う。** 黙って空の一覧を出さない */
    public function test_0件なら見つからないと言う(): void
    {
        $this->news('関係のない記事', '<p>本文</p>');

        $html = (string) $this->get('/news?q='.urlencode('該当しない語'))->assertOk()->getContent();

        $this->assertStringContainsString('見つかりません', $html);
        // 探し直せる導線
        $this->assertStringContainsString('/news', $html);
    }

    /** 検索語を入れていなければ、いつもの一覧 */
    public function test_検索語が無ければ全件(): void
    {
        $this->news('ひとつめ', '<p>本文</p>');
        $this->news('ふたつめ', '<p>本文</p>');

        $html = (string) $this->get('/news')->assertOk()->getContent();

        $this->assertStringContainsString('ひとつめ', $html);
        $this->assertStringContainsString('ふたつめ', $html);
    }

    /** **実績側も同じ形で探せる**（型ごとに別の検索を作らない） */
    public function test_実績も探せる(): void
    {
        DB::table('works')->insert([
            'title' => '陶芸工房のサイト', 'slug' => 'w-'.uniqid(),
            'excerpt' => '概要', 'body' => '<p>本文</p>', 'category' => 'Web',
            'status' => 'published', 'published_at' => now()->subDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('works')->insert([
            'title' => '別の実績', 'slug' => 'w-'.uniqid(),
            'excerpt' => '概要', 'body' => '<p>本文</p>', 'category' => 'Web',
            'status' => 'published', 'published_at' => now()->subDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $html = (string) $this->get('/works?q='.urlencode('陶芸'))->assertOk()->getContent();

        $this->assertStringContainsString('陶芸工房のサイト', $html);
        $this->assertStringNotContainsString('別の実績', $html);
    }

    /**
     * **`%` と `_` は記号のまま働く**（いまは塞げない・`BACKLOG.md` B-13）。
     *
     * 塞ぐには `LIKE ? ESCAPE '…'` が要り、それは raw SQL になる。
     * driver 差もある（SQLite は backslash を escape 文字にしない / MySQL では
     * `ESCAPE '\\'` が構文誤り）。**両方とも実測した。**
     *
     * ## 前の形は、両方の世界で緑だった
     *
     * 「`100%` で `100%の自信` が見つかる」は、**記号を塞いでも塞がなくても通る**
     * （文字どおり含まれているため）。**守りを入れても気づけない検査**は、
     * 検査ではなく飾りである。
     *
     * ここで固定するのは**記号でしか成立しない例**だけにした。
     * B-13 を閉じた日に、**この試験は必ず赤くなる** — それが要る。
     */
    public function test_記号は記号のまま働く(): void
    {
        $this->news('新しい作品', '<p>本文</p>');

        // `%` は「何文字でも」。**塞げば当たらない**語で確かめる
        $this->assertStringContainsString(
            '新しい作品',
            (string) $this->get('/news?q='.urlencode('新%作'))->assertOk()->getContent(),
            '% がワイルドカードとして働いていない（B-13 を閉じたならこの試験を書き換える）',
        );

        // `_` は「1 文字だけ」。**別の記号でも同じことが起きる**
        $this->assertStringContainsString(
            '新しい作品',
            (string) $this->get('/news?q='.urlencode('新_い'))->assertOk()->getContent(),
            '_ がワイルドカードとして働いていない（B-13 を閉じたならこの試験を書き換える）',
        );
    }

    /**
     * **宣言が空なら、絞られずに全件が出る**（B-14）。
     *
     * `search` を失った型では `PublicSearch::apply()` が何もしないので、
     * 検索語を入れても**一覧がそのまま出る**。**画面からは壊れて見えない** —
     * 「検索できないこと」と「全部が該当したこと」が同じ見た目になる。
     *
     * ここでは**いまの本当の振る舞いを固定する**だけにする。
     * 見せ方（0 件と言うか、検索窓を出さないか）は B-14 で決める。
     */
    public function test_宣言が空なら絞られない(): void
    {
        $this->news('ひとつめ', '<p>本文</p>');
        $this->news('ふたつめ', '<p>本文</p>');

        $schema = ContentSchema::fromArray([
            'id' => 'news',
            'label' => 'お知らせ',
            'fields' => [
                ['key' => 'title', 'type' => 'text', 'label' => '見出し'],
                ['key' => 'body', 'type' => 'richtext', 'label' => '本文'],
            ],
            // **`search` を書かない**（宣言を失った型）
        ]);

        $this->assertSame([], $schema->searchable);

        $query = News::query();
        PublicSearch::apply($query, $schema, '該当しない語');

        // **絞られない** = 検索語に当たらない行まで残る
        $this->assertSame(2, $query->count(), '宣言が空のとき、絞り込みが起きている');
    }
}
