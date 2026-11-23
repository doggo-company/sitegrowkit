<?php

declare(strict_types=1);

namespace Tests\Feature\Wxr;

use AgentCms\Core\Wxr\Plan\ImportOptions;
use AgentCms\Core\Wxr\Plan\PlanBuilder;
use AgentCms\Core\Wxr\WxrItem;
use AgentCms\Core\Wxr\WxrReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 取り込んだ日時が 9 時間ずれない（2026-09-06 実測 / F-17）。
 *
 * ## 何が起きていたか
 *
 * `wp:post_date_gmt` は UTC である。`WxrReader` はそれを**変換せずそのまま**返し、
 * 受け取った側はアプリの時間帯（既定 `Asia/Tokyo`）で解釈していた。
 * WordPress で 10:00 に出した記事が、管理画面に **01:00** と出る。
 *
 * **エラーは出ない。** 表示も保存も同じずれ方をするので再保存しても二重にはずれず、
 * **一方向の恒久ずれ**として残る。日付を覚えている人にしか見つからない壊れ方である。
 *
 * ## なぜ時間帯を test の中で決めているのか
 *
 * 試験環境の `app.timezone` は既定の `UTC` である。**そこでは UTC → UTC で
 * 何も動かないので、バグが再現しない。** 配って動くのは `APP_TIMEZONE=Asia/Tokyo`
 * （`.env.example`）の側なので、**配る側の時間帯をここで名指しして**確かめる。
 *
 * 併せて、OS の `TZ` が何であっても結果が変わらないことを固定する
 * （`TZ=UTC` と `TZ=Asia/Tokyo` の両方で回して同じ値になること）。
 */
final class WxrPublishedAtTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private string $dir = '';

    private string $originalTimezone = 'UTC';

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalTimezone = date_default_timezone_get();

        $this->dir = sys_get_temp_dir().'/wxr-tz-'.bin2hex(random_bytes(6));
        mkdir($this->dir);

        DB::table('content_types')->updateOrInsert(
            ['content_type_id' => 'news'],
            [
                'status' => 'active',
                'public_slug' => 'news',
                'registration' => json_encode(['public' => true], JSON_THROW_ON_ERROR),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    protected function tearDown(): void
    {
        $this->useTimezone($this->originalTimezone);

        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);

        parent::tearDown();
    }

    // ── 読み取り ────────────────────────────────────────────

    public function test_gmtの公開日時をアプリの時間帯へ直す(): void
    {
        $this->useTimezone('Asia/Tokyo');

        // WordPress の書き出しそのまま: 現地 10:00 / GMT 01:00
        $item = $this->read($this->dated('2024-05-01 10:00:00', '2024-05-01 01:00:00'));

        // **01:00 のままだと 9 時間前の記事になる**
        $this->assertSame('2024-05-01 10:00:00', $item->publishedAt);
    }

    public function test_アプリがutcなら動かさない(): void
    {
        $this->useTimezone('UTC');

        $item = $this->read($this->dated('2024-05-01 10:00:00', '2024-05-01 01:00:00'));

        $this->assertSame('2024-05-01 01:00:00', $item->publishedAt);
    }

    /** 日付をまたぐ側（ずれの向きを取り違えていないことが、ここで初めて見える） */
    public function test_日付をまたぐgmtも正しく回る(): void
    {
        $this->useTimezone('Asia/Tokyo');

        $item = $this->read($this->dated('2024-05-02 08:00:00', '2024-05-01 23:00:00'));

        $this->assertSame('2024-05-02 08:00:00', $item->publishedAt);
    }

    /** 夏時間のある土地でも、その日の実際のずれで動く（+9 固定の実装だと落ちる） */
    public function test_夏時間のある時間帯でもその日のずれで動く(): void
    {
        $this->useTimezone('America/New_York');

        // 7 月は EDT（UTC-4）
        $summer = $this->read($this->dated('2024-07-01 08:00:00', '2024-07-01 12:00:00'));
        $this->assertSame('2024-07-01 08:00:00', $summer->publishedAt);

        // 1 月は EST（UTC-5）
        $winter = $this->read($this->dated('2024-01-01 07:00:00', '2024-01-01 12:00:00'));
        $this->assertSame('2024-01-01 07:00:00', $winter->publishedAt);
    }

    /**
     * `wp:post_date`（GMT の付かない方）は**既にサイトの現地時刻**なので動かさない。
     * WXR はそれがどの時間帯かを持っていないので、動かすと当てずっぽうになる。
     */
    public function test_gmtが無いときの現地時刻は動かさない(): void
    {
        $this->useTimezone('Asia/Tokyo');

        $item = $this->read($this->dated('2024-05-01 10:00:00', '0000-00-00 00:00:00'));

        $this->assertSame('2024-05-01 10:00:00', $item->publishedAt);
    }

    /** 日時として読めない GMT を「UTC だ」と言い張らない（現地時刻へ落ちる） */
    public function test_読めないgmtは現地時刻へ落ちる(): void
    {
        $this->useTimezone('Asia/Tokyo');

        $item = $this->read($this->dated('2024-05-01 10:00:00', 'いつか'));

        $this->assertSame('2024-05-01 10:00:00', $item->publishedAt);
    }

    public function test_公開日時が無ければnullのまま(): void
    {
        $this->useTimezone('Asia/Tokyo');

        $item = $this->read($this->dated('0000-00-00 00:00:00', ''));

        $this->assertNull($item->publishedAt);
    }

    /** コメントの日時も同じ経路を通る（同じ関数なので、片方だけ直っていないこと） */
    public function test_コメントの日時も直る(): void
    {
        $this->useTimezone('Asia/Tokyo');

        $item = $this->read($this->dated('2024-05-01 10:00:00', '2024-05-01 01:00:00').
            '<wp:comment>'
            .'<wp:comment_id>1</wp:comment_id>'
            .'<wp:comment_author>読者</wp:comment_author>'
            .'<wp:comment_content>こんにちは</wp:comment_content>'
            .'<wp:comment_approved>1</wp:comment_approved>'
            .'<wp:comment_date>2024-05-02 12:00:00</wp:comment_date>'
            .'<wp:comment_date_gmt>2024-05-02 03:00:00</wp:comment_date_gmt>'
            .'</wp:comment>');

        $this->assertSame('2024-05-02 12:00:00', $item->comments[0]->createdAt);
    }

    // ── 取り込んだ後（管理画面が読む値） ──────────────────────

    /**
     * **読み取りだけでは、買った人の目に映るものを確かめたことにならない。**
     * 実際に `apply` を通し、記事の行に入った値を見る。
     */
    public function test_取り込んだ記事の公開日時が管理画面の時間帯で正しい(): void
    {
        $this->useTimezone('Asia/Tokyo');

        $path = $this->write($this->wxr(
            '<item><title>記事 11</title><wp:post_id>11</wp:post_id>'
            .'<wp:post_type>news</wp:post_type><wp:status>publish</wp:status>'
            .'<content:encoded><![CDATA[本文]]></content:encoded>'
            .'<wp:post_date>2024-05-01 10:00:00</wp:post_date>'
            .'<wp:post_date_gmt>2024-05-01 01:00:00</wp:post_date_gmt>'
            .'</item>',
        ));

        $this->artisan('agentcms:wxr-apply', [
            'file' => $path,
            '--i-have-a-backup' => true,
            '--plan-hash' => (string) app(PlanBuilder::class)
                ->build($path, ImportOptions::fromCli([], [], false))
                ->fingerprint(),
        ])->assertExitCode(0);

        $stored = (string) DB::table('news')->value('published_at');

        // 保存された文字列は、そのままアプリの時間帯として読まれる
        $this->assertStringStartsWith('2024-05-01 10:00:00', $stored);
    }

    // ── 道具 ────────────────────────────────────────────────

    /**
     * **アプリの時間帯を名指しで決める。**
     *
     * `date_default_timezone_set()` は Laravel が起動時に `app.timezone` から
     * 設定するものと同じ入口で、Carbon / Eloquent が時間帯の付かない文字列を
     * 解釈するのに使う値でもある。config だけ書き換えると**両者がずれる**ので、
     * 2 つとも揃える。
     */
    private function useTimezone(string $timezone): void
    {
        config(['app.timezone' => $timezone]);
        date_default_timezone_set($timezone);
    }

    /** 1 件だけの WXR を読む */
    private function read(string $body): WxrItem
    {
        $path = $this->write($this->wxr('<item><title>記事 11</title>'
            .'<wp:post_id>11</wp:post_id><wp:post_type>news</wp:post_type>'
            .'<wp:status>publish</wp:status><wp:post_name>kiji-11</wp:post_name>'
            .'<content:encoded><![CDATA[本文]]></content:encoded>'
            .$body
            .'</item>'));

        foreach ((new WxrReader($path))->items() as $item) {
            return $item;
        }

        $this->fail('item を 1 件も読めませんでした');
    }

    private function dated(string $local, string $gmt): string
    {
        return "<wp:post_date>{$local}</wp:post_date>"
            ."<wp:post_date_gmt>{$gmt}</wp:post_date_gmt>";
    }

    private function wxr(string $body): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"'
            .' xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"'
            .' xmlns:wp="http://wordpress.org/export/1.2/">'
            .'<channel><title>移行元</title><wp:wxr_version>1.2</wp:wxr_version>'
            .$body
            .'</channel></rss>';
    }

    private function write(string $contents): string
    {
        $path = $this->dir.'/'.bin2hex(random_bytes(4)).'.xml';
        file_put_contents($path, $contents);

        return $path;
    }
}
