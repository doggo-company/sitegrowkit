<?php

declare(strict_types=1);

namespace Tests\Feature\Wxr;

use AgentCms\Core\Wxr\Plan\CommentFields;
use AgentCms\Core\Wxr\Plan\ImportOptions;
use AgentCms\Core\Wxr\Plan\PlanBuilder;
use AgentCms\Core\Wxr\WxrReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use RuntimeException;
use Tests\Feature\Wxr\Concerns\WatchesDatabaseWrites;
use Tests\TestCase;

/**
 * コメントを入れる（Phase 9.14 / STAGE 27D-5b・SPEC §C.4・§E.1・§G）。
 *
 * ## 9.13 の表へ直接入れる
 *
 * 公開面の受付は spam 判定と rate limit を通るが、**移行は人の操作ではない**（§E.1）。
 * 十年前に人が承認したコメントを、いま機械が spam に落とすことはしない。
 * **元の判定をそのまま写す。**
 *
 * ## 他人が書いた文章である
 *
 * 記事は移行する人のものだが、コメントはその人のものではない。
 * 捨てない・切らない・状態を変えない。
 */
final class ApplyWxrCommentTest extends TestCase
{
    use RefreshDatabase;
    use WatchesDatabaseWrites;

    /** この段で書いてよい表 */
    private const ALLOWED = [
        'import_batches', 'audit_logs', 'import_records', 'news',
        'content_entry_revisions', 'content_schema_manifests', 'content_comments',
        'content_comment_settings',
    ];

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/wxr-apply-comment-'.bin2hex(random_bytes(6));
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

    public function test_コメントが入る(): void
    {
        $this->importing($this->entry('11', [
            ['id' => '1', 'author' => '書いた人', 'approved' => '1'],
        ]));

        $comment = DB::table('content_comments')->first();

        $this->assertNotNull($comment);
        $this->assertSame('news', $comment->content_type);
        $this->assertSame((int) DB::table('news')->value('id'), (int) $comment->content_id);
        $this->assertSame('書いた人', $comment->author_name);
        $this->assertSame('approved', $comment->status);
        $this->assertSame('コメント本文', $comment->body);
    }

    public function test_4値がそのまま写る(): void
    {
        $this->importing($this->entry('11', [
            ['id' => '1', 'approved' => '1'],
            ['id' => '2', 'approved' => '0'],
            ['id' => '3', 'approved' => 'spam'],
            ['id' => '4', 'approved' => 'trash'],
            ['id' => '5', 'approved' => 'post-trashed'],
        ]));

        $this->assertSame(
            ['1' => 'approved', '2' => 'pending', '3' => 'spam', '4' => 'trash', '5' => 'trash'],
            $this->statusByExternalId(),
        );
    }

    public function test_台帳へ記録して所有する(): void
    {
        $this->importing($this->entry('11', [['id' => '1']]));

        $record = DB::table('import_records')->where('kind', 'comment')->first();

        $this->assertNotNull($record);
        $this->assertSame('comment', $record->target_type);
        $this->assertSame('1', $record->external_id);
        $this->assertSame((string) DB::table('content_comments')->value('id'), $record->target_id);
        $this->assertSame(1, (int) $record->created_by_import);
    }

    public function test_2回流しても二重に入らない(): void
    {
        $xml = $this->entry('11', [['id' => '1']]);

        $this->importing($xml);
        $this->importing($xml, fresh: true);

        $this->assertSame(1, DB::table('content_comments')->count());
        $this->assertSame(1, DB::table('import_records')->where('kind', 'comment')->count());
    }

    // ── 個人情報（§G） ──────────────────────────────────────

    public function test_i_pを持たない(): void
    {
        /*
         * WXR には `wp:comment_author_IP` が入っているが、**読んだ時点で捨てている**。
         * 元の IP が無いのだから、**取り込み由来のハッシュも作れない** — 作れば嘘になる。
         */
        $this->importing($this->entry('11', [['id' => '1', 'ip' => '203.0.113.9']]));

        $comment = DB::table('content_comments')->first();

        $this->assertNotNull($comment);
        $this->assertNull($comment->ip_hash);
        $this->assertNull($comment->user_agent);
        $this->assertNull($comment->author_user_id);
        // WordPress は承認時刻を記録しないので、入れられる値は代理でしかない
        $this->assertNull($comment->approved_at);
        $this->assertSame(0, (int) $comment->is_staff);
    }

    public function test_メールアドレスは取り込む(): void
    {
        // 取り込むが**公開しない**（9.13 §G.1）。ここでは入っていることだけを見る
        $this->importing($this->entry('11', [['id' => '1', 'email' => 'kaita@example.com']]));

        $this->assertSame('kaita@example.com', DB::table('content_comments')->value('author_email'));
    }

    public function test_名前が空なら名前なしで入る(): void
    {
        // §C.4.1 — 永続化される既定値
        $this->importing($this->entry('11', [['id' => '1', 'author' => '']]));

        $this->assertSame('(名前なし)', DB::table('content_comments')->value('author_name'));
    }

    // ── 返信 ────────────────────────────────────────────────

    public function test_返信が親に付く(): void
    {
        $this->importing($this->entry('11', [
            ['id' => '1'],
            ['id' => '2', 'parent' => '1'],
        ]));

        $ids = $this->idByExternalId();

        $this->assertSame(
            $ids['1'],
            (string) DB::table('content_comments')->where('id', $ids['2'])->value('parent_id'),
        );
    }

    public function test_2段目は1段目へ付け替えて捨てない(): void
    {
        // §C.4 — 9.13 は 2 段目を断る。**捨てずに 1 段目へ付け替える**
        $this->importing($this->entry('11', [
            ['id' => '1'],
            ['id' => '2', 'parent' => '1'],
            ['id' => '3', 'parent' => '2'],
        ]));

        $this->assertSame(3, DB::table('content_comments')->count(), '2 段目が捨てられています');

        $ids = $this->idByExternalId();

        $this->assertSame(
            $ids['1'],
            (string) DB::table('content_comments')->where('id', $ids['3'])->value('parent_id'),
        );
    }

    public function test_親が居ないものは1段目として入る(): void
    {
        $this->importing($this->entry('11', [['id' => '2', 'parent' => '99']]));

        $this->assertSame(1, DB::table('content_comments')->count());
        $this->assertNull(DB::table('content_comments')->value('parent_id'));
    }

    // ── 記事の状態で決めない ────────────────────────────────

    public function test_記事が既にあってもコメントは入る(): void
    {
        /*
         * 半端に終わった取り込みを続けるとき、「記事は入っているがコメントは入っていない」が
         * 普通に起こる。記事が済んでいるからとまとめて飛ばすと、**その分が黙って落ちる**。
         */
        $this->importing($this->entry('11', []));
        $this->assertSame(0, DB::table('content_comments')->count());

        $this->importing($this->entry('11', [['id' => '1']]), fresh: true);

        $this->assertSame(1, DB::table('news')->count(), '記事が二重に入っています');
        $this->assertSame(1, DB::table('content_comments')->count(), 'コメントが落ちています');
    }

    // ── 台帳に無いコメントを作らない ────────────────────────

    public function test_台帳が書けなければコメントも残らない(): void
    {
        /*
         * 本体と台帳を**別の transaction**で書くと、その間に落ちたときに
         * **台帳に無いコメント**が残る。台帳に無いものは取り消しから見えないので、
         * **消せないコメントが十年分**できる。
         *
         * 落ちる場所を作れないと確かめられないので、台帳への書き込みが起きた時点で落とす。
         */
        DB::listen(function ($query): void {
            $sql = (string) $query->sql;

            // **書き込みだけ**を狙う（台帳は読む側でも `comment` を鍵に引く）
            if (str_starts_with(strtolower(ltrim($sql)), 'insert into')
                && str_contains($sql, 'import_records')
                && in_array('comment', $query->bindings, true)) {
                throw new RuntimeException('台帳の書き込みで落ちた');
            }
        });

        $path = $this->write('a.xml', $this->wxr($this->entry('11', [['id' => '1']])));

        $this->apply($path)->assertExitCode(1);

        $this->assertSame(0, DB::table('content_comments')->count(), '台帳に無いコメントが残っています');
    }

    // ── 記事がコメントを受け付けるか（§C.4.3） ──────────────

    public function test_閉じていた記事は閉じたまま入る(): void
    {
        /*
         * 取り込まないと、**元のサイトで閉じていた記事が移行後は開いた状態になる**。
         * 十年前の記事に新しいコメントが付き始める。閉じたのは持ち主の判断である。
         */
        $this->importing($this->entry('11', [], '<wp:comment_status>closed</wp:comment_status>'));

        $row = DB::table('content_comment_settings')->first();

        $this->assertNotNull($row, '閉じていた記事が開いています');
        $this->assertSame('news', $row->content_type);
        $this->assertSame((int) DB::table('news')->value('id'), (int) $row->content_id);
        $this->assertSame(0, (int) $row->is_open);
    }

    public function test_開いていた記事には行を書かない(): void
    {
        /*
         * 9.13 は「**既定値を全記事へ書き込まない**」を採っている。
         * 全記事へ書くと、型の設定を変えたときに**過去の記事だけ古い値のまま**になる。
         */
        $this->importing($this->entry('11', [], '<wp:comment_status>open</wp:comment_status>'));

        $this->assertSame(0, DB::table('content_comment_settings')->count());
    }

    public function test_知らない値でも行を書かない(): void
    {
        // 数えて報告するのは plan の仕事で、**取り込みの動きは変えない**（§C.4.3）
        $this->importing($this->entry('11', [], '<wp:comment_status>registered_only</wp:comment_status>'));

        $this->assertSame(0, DB::table('content_comment_settings')->count());
    }

    public function test_設定が無ければ行を書かない(): void
    {
        $this->importing($this->entry('11', []));

        $this->assertSame(0, DB::table('content_comment_settings')->count());
    }

    public function test_既にある記事の設定は触らない(): void
    {
        /*
         * §D.2.1 と同じ。**作るときにしか書かない。**
         * 触ると、移行後に人が開き直した記事を**再実行が黙って閉じる**。
         */
        $body = $this->entry('11', [], '<wp:comment_status>closed</wp:comment_status>');

        $this->importing($body);
        $this->assertSame(1, DB::table('content_comment_settings')->count());

        // 人が開き直した
        DB::table('content_comment_settings')->delete();

        $this->importing($body, fresh: true);

        $this->assertSame(0, DB::table('content_comment_settings')->count(), '再実行が設定を戻しています');
    }

    // ── 対応表を 2 か所に持たない ──────────────────────────

    public function test_対応表の値がそのまま入る(): void
    {
        /*
         * `CommentFields::of()` は plan と apply の両方が読む。
         * 表へ欄を足したのに入れる側へ足し忘れると、**その値は黙って落ちる** —
         * F-4・F-9 と同じ形なので、**最初から**両者の一致を固定する。
         */
        $this->importing($this->entry('11', [
            ['id' => '1', 'author' => '書いた人', 'email' => 'kaita@example.com'],
        ]));

        $comment = DB::table('content_comments')->first();

        $this->assertNotNull($comment);

        $source = null;

        foreach ((new WxrReader($this->dir.'/a.xml'))->items() as $item) {
            $source = $item->comments[0];
        }

        $this->assertNotNull($source);

        foreach (CommentFields::of($source) as $column => $value) {
            if ($column === 'created_at') {
                // 桁と書式は DB 側で決まるので、**指している時刻**で比べる
                $this->assertSame(
                    Carbon::parse((string) $value)->toDateTimeString(),
                    Carbon::parse((string) $comment->created_at)->toDateTimeString(),
                );

                continue;
            }

            $this->assertSame($value, $comment->{$column}, "{$column} が入っていません");
        }
    }

    // ── 件数 ────────────────────────────────────────────────

    public function test_件数は種類ごとに出る(): void
    {
        /*
         * 種類を混ぜた合計にすると、**十年分ではコメントが桁で上回る**ので
         * 合計はほぼコメントの数になり、記事が何本入ったのかが読めない。
         */
        $this->importing($this->entry('11', [['id' => '1'], ['id' => '2']]));

        $counts = json_decode((string) DB::table('import_batches')->value('counts'), true);

        $this->assertSame(['comment' => ['create' => 2], 'content' => ['create' => 1]], $counts);
    }

    public function test_無い種類は出さない(): void
    {
        // 渡さない種類へ空の内訳を足すと、何も変わっていないのに出力が変わる
        $this->importing($this->entry('11', []));

        $this->assertSame(
            ['content' => ['create' => 1]],
            json_decode((string) DB::table('import_batches')->value('counts'), true),
        );
    }

    // ── 書いてよい表だけを触る ──────────────────────────────

    public function test_許した表以外は1行も変わらない(): void
    {
        $path = $this->write('a.xml', $this->wxr($this->entry('11', [['id' => '1']])));

        $before = $this->snapshot(self::ALLOWED);
        $writes = $this->watchWrites(self::ALLOWED);

        $this->apply($path)->assertExitCode(0);

        $this->assertSame([], $writes(), '許していない表への書き込みが出ています');
        $this->assertSame($before, $this->snapshot(self::ALLOWED), '許していない表の中身が変わっています');
    }

    // ── ヘルパー ────────────────────────────────────────────

    /** @return array<string, string> wp:comment_id => 状態 */
    private function statusByExternalId(): array
    {
        $statuses = [];

        foreach ($this->idByExternalId() as $external => $id) {
            $statuses[$external] = (string) DB::table('content_comments')->where('id', $id)->value('status');
        }

        return $statuses;
    }

    /** @return array<string, string> wp:comment_id => こちらの id */
    private function idByExternalId(): array
    {
        $ids = [];

        foreach (DB::table('import_records')->where('kind', 'comment')->get() as $record) {
            $ids[(string) $record->external_id] = (string) $record->target_id;
        }

        return $ids;
    }

    private function importing(string $body, bool $fresh = false): void
    {
        $path = $this->write($fresh ? 'b.xml' : 'a.xml', $this->wxr($body));

        $this->apply($path)->assertExitCode(0);
    }

    private function apply(string $path): PendingCommand
    {
        /** @var PendingCommand */
        return $this->artisan('agentcms:wxr-apply', [
            'file' => $path,
            '--i-have-a-backup' => true,
            '--plan-hash' => $this->hashFor($path),
        ]);
    }

    private function hashFor(string $path): string
    {
        return (string) app(PlanBuilder::class)
            ->build($path, ImportOptions::fromCli([], [], false))
            ->fingerprint();
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
        $ip = $comment['ip'] ?? '198.51.100.1';

        return '<wp:comment>'
            .'<wp:comment_id>'.$comment['id'].'</wp:comment_id>'
            ."<wp:comment_author><![CDATA[{$author}]]></wp:comment_author>"
            ."<wp:comment_author_email>{$email}</wp:comment_author_email>"
            .'<wp:comment_author_url></wp:comment_author_url>'
            ."<wp:comment_author_IP>{$ip}</wp:comment_author_IP>"
            .'<wp:comment_date>2016-01-01 09:00:00</wp:comment_date>'
            .'<wp:comment_date_gmt>2016-01-01 00:00:00</wp:comment_date_gmt>'
            .'<wp:comment_content><![CDATA[コメント本文]]></wp:comment_content>'
            .'<wp:comment_approved>'.($comment['approved'] ?? '1').'</wp:comment_approved>'
            .'<wp:comment_parent>'.($comment['parent'] ?? '0').'</wp:comment_parent>'
            .'</wp:comment>';
    }

    private function wxr(string $body = ''): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"'
            .' xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"'
            .' xmlns:wp="http://wordpress.org/export/1.2/">'
            .'<channel><title>移行元</title><wp:wxr_version>1.2</wp:wxr_version>'
            .$body
            .'</channel></rss>';
    }

    private function write(string $name, string $contents): string
    {
        $path = $this->dir.'/'.$name;
        file_put_contents($path, $contents);

        return $path;
    }
}
