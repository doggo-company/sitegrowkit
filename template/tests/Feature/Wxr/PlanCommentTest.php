<?php

declare(strict_types=1);

namespace Tests\Feature\Wxr;

use AgentCms\Core\Wxr\Plan\ImportOptions;
use AgentCms\Core\Wxr\Plan\PlanBuilder;
use AgentCms\Core\Wxr\Plan\WxrPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * コメントについて、入れる前に言うこと（Phase 9.14 / STAGE 27D-5b・SPEC §C.4・§A.3）。
 *
 * ## 他人が書いた文章である
 *
 * 記事は移行する人のものだが、コメントは**その人のものではない**。
 * 黙って落とす・黙って切る・黙って状態を変える、のどれもしない。
 */
final class PlanCommentTest extends TestCase
{
    use RefreshDatabase;

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/wxr-comment-'.bin2hex(random_bytes(6));
        mkdir($this->dir);

        DB::table('content_types')->updateOrInsert(
            ['content_type_id' => 'news'],
            [
                'status' => 'active',
                'public_slug' => 'news',
                'registration' => json_encode(['public' => true], JSON_THROW_ON_ERROR),
                'comments_enabled' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);

        parent::tearDown();
    }

    // ── 状態 ────────────────────────────────────────────────

    public function test_4値はそのまま通る(): void
    {
        $plan = $this->plan($this->entry('11', [
            ['id' => '1', 'approved' => '1'],
            ['id' => '2', 'approved' => '0'],
            ['id' => '3', 'approved' => 'spam'],
            ['id' => '4', 'approved' => 'trash'],
            ['id' => '5', 'approved' => 'post-trashed'],
        ]));

        $this->assertArrayNotHasKey('comment_status_unreadable', $plan->findings());
        $this->assertTrue($plan->isUsable(), json_encode($plan->findings(), JSON_UNESCAPED_UNICODE));
    }

    public function test_知らない状態は止める(): void
    {
        /*
         * **勝手に承認待ちへ寄せない**（§C.8.4 と同じ考え）。
         * 寄せると、公開されていたコメントが黙って消えるか、
         * 隠されていたものが黙って出る。どちらも他人の文章である。
         */
        $plan = $this->plan($this->entry('11', [['id' => '1', 'approved' => 'unknown']]));

        $this->assertFalse($plan->isUsable());
        $this->assertNull($plan->fingerprint());

        $found = $plan->findings()['comment_status_unreadable'];

        $this->assertSame(1, $found['total']);
        $this->assertSame('1', $found['shown'][0]['external_id']);
    }

    // ── 桁 ──────────────────────────────────────────────────

    public function test_名前が桁を超えたら止める(): void
    {
        // §C.4.1 — **他人の名前を黙って切らない**
        $plan = $this->plan($this->entry('11', [
            ['id' => '1', 'author' => str_repeat('あ', 81)],
        ]));

        $this->assertFalse($plan->isUsable());

        $found = $plan->findings()['comment_field_too_long'];

        $this->assertSame(1, $found['total']);
        $this->assertSame('1', $found['shown'][0]['external_id']);
        $this->assertSame(['author' => 1], $found['by_source']);
    }

    public function test_桁は文字で見る(): void
    {
        /*
         * varchar(80) は **80 文字**であって 80 byte ではない。
         * byte で見ると、日本語の名前は **26 文字**で止まることになる。
         */
        $plan = $this->plan($this->entry('11', [['id' => '1', 'author' => str_repeat('あ', 80)]]));

        $this->assertArrayNotHasKey('comment_field_too_long', $plan->findings());
    }

    public function test_どの欄が長いのかを分けて数える(): void
    {
        $plan = $this->plan($this->entry('11', [
            ['id' => '1', 'author' => str_repeat('あ', 81)],
            ['id' => '2', 'email' => str_repeat('a', 250).'@example.com'],
            ['id' => '3', 'url' => 'https://example.com/'.str_repeat('a', 250)],
        ]));

        $this->assertSame(
            ['author' => 1, 'email' => 1, 'url' => 1],
            $plan->findings()['comment_field_too_long']['by_source'],
        );
    }

    public function test_名前が空でも止めない(): void
    {
        // §C.4.1 — 空は `(名前なし)` で埋まる。数えると直しようのない理由で止まる
        $plan = $this->plan($this->entry('11', [['id' => '1', 'author' => '']]));

        $this->assertArrayNotHasKey('comment_field_too_long', $plan->findings());
        $this->assertTrue($plan->isUsable(), json_encode($plan->findings(), JSON_UNESCAPED_UNICODE));
    }

    // ── 返信 ────────────────────────────────────────────────

    public function test_2段目は付け替えとして数える(): void
    {
        // §C.4 — 2 段目以降は**親を 1 段目へ付け替える**（捨てない）
        $plan = $this->plan($this->entry('11', [
            ['id' => '1'],
            ['id' => '2', 'parent' => '1'],
            ['id' => '3', 'parent' => '2'],
        ]));

        $found = $plan->findings()['comment_reply_flattened'];

        // **付け替わるのは 3 だけ。** 2 は元から 1 段目への返信である
        $this->assertSame(1, $found['total']);
        $this->assertSame('3', $found['shown'][0]['external_id']);
        $this->assertTrue($plan->isUsable(), json_encode($plan->findings(), JSON_UNESCAPED_UNICODE));
    }

    public function test_1段目の返信は付け替えではない(): void
    {
        $plan = $this->plan($this->entry('11', [
            ['id' => '1'],
            ['id' => '2', 'parent' => '1'],
        ]));

        $this->assertArrayNotHasKey('comment_reply_flattened', $plan->findings());
    }

    public function test_親が居ないコメントは1段目として数える(): void
    {
        /*
         * 親がファイルに無い（別の記事のコメントを指している・書き出しが欠けている）。
         * **捨てない。** 1 段目として入れる — 他人の文章を消す方が悪い。
         */
        $plan = $this->plan($this->entry('11', [['id' => '2', 'parent' => '99']]));

        $found = $plan->findings()['comment_parent_missing'];

        $this->assertSame(1, $found['total']);
        $this->assertSame('2', $found['shown'][0]['external_id']);
        $this->assertTrue($plan->isUsable(), json_encode($plan->findings(), JSON_UNESCAPED_UNICODE));
    }

    public function test_輪になった親は止める(): void
    {
        /*
         * 1 の親が 2・2 の親が 1。辿ると**止まらない**。
         * `TermGraph` の循環と同じで、**入れる前に**止める。
         */
        $plan = $this->plan($this->entry('11', [
            ['id' => '1', 'parent' => '2'],
            ['id' => '2', 'parent' => '1'],
        ]));

        $this->assertFalse($plan->isUsable());
        $this->assertSame(2, $plan->findings()['comment_parent_cycle']['total']);
    }

    public function test_自分が親でも止める(): void
    {
        $plan = $this->plan($this->entry('11', [['id' => '1', 'parent' => '1']]));

        $this->assertFalse($plan->isUsable());
        $this->assertSame(1, $plan->findings()['comment_parent_cycle']['total']);
    }

    // ── 記事がコメントを受け付けるか（§C.4.3） ──────────────

    public function test_知らない受付設定は数えるが止めない(): void
    {
        /*
         * **要素が無いことと、知らない値が書いてあることは別である**（§C.4.3）。
         * 後者は「こちらが読めていない何かがそこにある」なので、
         * 未知の `wp:status` と同じ作法（§A.3）で数える。
         * 取り込みの動きは変わらない（行を書かない）ので**止めない**。
         */
        $plan = $this->plan($this->entry('11', [], '<wp:comment_status>registered_only</wp:comment_status>'));

        $found = $plan->findings()['comment_setting_unknown'];

        $this->assertSame(1, $found['total']);
        $this->assertSame([['external_id' => '11', 'source_type' => 'news']], $found['shown']);
        $this->assertTrue($plan->isUsable(), json_encode($plan->findings(), JSON_UNESCAPED_UNICODE));
    }

    public function test_openとclosedは数えない(): void
    {
        $plan = $this->plan(
            $this->entry('11', [], '<wp:comment_status>open</wp:comment_status>')
            .$this->entry('12', [], '<wp:comment_status>closed</wp:comment_status>'),
        );

        $this->assertArrayNotHasKey('comment_setting_unknown', $plan->findings());
    }

    public function test_受付設定が無ければ数えない(): void
    {
        // **正常な不在**。数えると、書いていないだけの WXR が十年分の理由で埋まる
        $plan = $this->plan($this->entry('11', []));

        $this->assertArrayNotHasKey('comment_setting_unknown', $plan->findings());
    }

    // ── 個人情報 ────────────────────────────────────────────

    public function test_診断にメールアドレスも本文も名前も出さない(): void
    {
        // §G — 明細は画面にも台帳にも出る
        $plan = $this->plan($this->entry('11', [
            ['id' => '1', 'approved' => 'unknown', 'author' => '山田太郎', 'email' => 'yamada@example.com'],
        ]));

        $flat = json_encode($plan->findings(), JSON_UNESCAPED_UNICODE);

        $this->assertIsString($flat);
        $this->assertStringNotContainsString('山田太郎', $flat);
        $this->assertStringNotContainsString('yamada@example.com', $flat);
        $this->assertStringNotContainsString('コメント本文', $flat);
    }

    // ── ヘルパー ────────────────────────────────────────────

    /**
     * @param  list<array<string, string>>  $comments
     */
    private function plan(string $body): WxrPlan
    {
        $path = $this->dir.'/a.xml';

        file_put_contents(
            $path,
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"'
            .' xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"'
            .' xmlns:wp="http://wordpress.org/export/1.2/">'
            .'<channel><title>移行元</title><wp:wxr_version>1.2</wp:wxr_version>'
            .$body
            .'</channel></rss>',
        );

        return app(PlanBuilder::class)->build($path, ImportOptions::fromCli([], [], false));
    }

    /**
     * @param  list<array<string, string>>  $comments
     */
    private function entry(string $id, array $comments, string $extra = ''): string
    {
        $xml = "<item><title>記事 {$id}</title><wp:post_id>{$id}</wp:post_id>"
            .'<wp:post_type>news</wp:post_type><wp:status>publish</wp:status>'
            ."<wp:post_name>kiji-{$id}</wp:post_name>"
            ."<content:encoded><![CDATA[本文 {$id}]]></content:encoded>"
            .$extra;

        foreach ($comments as $comment) {
            $xml .= $this->comment($comment);
        }

        return $xml.'</item>';
    }

    /**
     * @param  array<string, string>  $comment
     */
    private function comment(array $comment): string
    {
        $author = $comment['author'] ?? '書いた人';
        $email = $comment['email'] ?? 'kaita@example.com';
        $url = $comment['url'] ?? '';

        return '<wp:comment>'
            .'<wp:comment_id>'.$comment['id'].'</wp:comment_id>'
            ."<wp:comment_author><![CDATA[{$author}]]></wp:comment_author>"
            ."<wp:comment_author_email>{$email}</wp:comment_author_email>"
            ."<wp:comment_author_url>{$url}</wp:comment_author_url>"
            .'<wp:comment_date>2016-01-01 09:00:00</wp:comment_date>'
            .'<wp:comment_date_gmt>2016-01-01 00:00:00</wp:comment_date_gmt>'
            .'<wp:comment_content><![CDATA[コメント本文]]></wp:comment_content>'
            .'<wp:comment_approved>'.($comment['approved'] ?? '1').'</wp:comment_approved>'
            .'<wp:comment_parent>'.($comment['parent'] ?? '0').'</wp:comment_parent>'
            .'</wp:comment>';
    }
}
