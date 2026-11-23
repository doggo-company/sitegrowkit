<?php

declare(strict_types=1);

namespace Tests\Feature\Wxr;

use AgentCms\Core\Wxr\Plan\ImportOptions;
use AgentCms\Core\Wxr\Plan\PlanBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Tests\Feature\Wxr\Concerns\WatchesDatabaseWrites;
use Tests\TestCase;

/**
 * 記事を入れる（Phase 9.14 / STAGE 27D-3・SPEC §E.1・§D.2・§D.5）。
 *
 * ## 既存の Application Service を通す
 *
 * `CreateContent` を通る（§E.1）。**取り込み専用の書き込み経路を作らない** —
 * 作ると validation・policy・監査のどれかが抜け、「import だけ通る穴」になる。
 *
 * ## 台帳に無い記事を作らない
 *
 * 記事の作成と台帳の記録は**同じ transaction**で行う。分けると、その間に落ちたときに
 * **台帳に無い記事**が残る。台帳に無い記事は取り消しから見えないので、
 * **消せない記事が十年分**できる。
 */
final class ApplyWxrContentTest extends TestCase
{
    use RefreshDatabase;
    use WatchesDatabaseWrites;

    /** この段で書いてよい表 */
    private const ALLOWED = [
        'import_batches', 'audit_logs', 'import_records', 'news',
        // `CreateContent` は版を 1 件作り、その定義の指紋も残す
        'content_entry_revisions', 'content_schema_manifests',
    ];

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/wxr-content-'.bin2hex(random_bytes(6));
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
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);

        parent::tearDown();
    }

    public function test_作る予定の記事を作る(): void
    {
        $this->importing($this->wxr('<item>'.$this->entry('11', 'news').'</item>'));

        $this->assertSame(1, DB::table('news')->count());
        $this->assertSame('記事 11', DB::table('news')->value('title'));
    }

    public function test_作った記事を台帳へ記録する(): void
    {
        $this->importing($this->wxr('<item>'.$this->entry('11', 'news').'</item>'));

        $record = DB::table('import_records')->first();

        $this->assertNotNull($record);
        $this->assertSame('content', $record->kind);
        $this->assertSame('news', $record->target_type);
        $this->assertSame('11', $record->external_id);
        $this->assertSame((string) DB::table('news')->value('id'), $record->target_id);
        // **こちらが作ったので所有する**（取り消しで消してよい）
        $this->assertSame(1, (int) $record->created_by_import);
    }

    public function test_元の公開状態を残す(): void
    {
        // §C.8.3 — schema に宣言しない列へ、呼び出しの隣で書く（§E.1.1）
        $this->importing($this->wxr('<item>'.$this->entry('11', 'news', 'private').'</item>'));

        $this->assertSame('private', DB::table('news')->value('wp_status'));
        // 写像は 2 値へ畳む（§C.8.2）
        $this->assertSame('draft', DB::table('news')->value('status'));
    }

    public function test_公開だったものは公開で入る(): void
    {
        $this->importing($this->wxr('<item>'.$this->entry('11', 'news', 'publish').'</item>'));

        $this->assertSame('publish', DB::table('news')->value('wp_status'));
        $this->assertSame('published', DB::table('news')->value('status'));
    }

    public function test_台帳にあるものは作らない(): void
    {
        // V1 は既にあるものへ書かない（`PlanAction` の docblock）
        $xml = $this->wxr('<item>'.$this->entry('11', 'news').'</item>');

        $this->importing($xml);
        $this->assertSame(1, DB::table('news')->count());

        // **2 回流しても二重に作らない**（§D.2）
        $this->importing($xml, fresh: true);
        $this->assertSame(1, DB::table('news')->count());
        $this->assertSame(1, DB::table('import_records')->count());
    }

    public function test_2回目は作った件数が0になる(): void
    {
        $xml = $this->wxr('<item>'.$this->entry('11', 'news').'</item>');

        $this->importing($xml);
        $first = (string) DB::table('import_batches')->value('id');

        $this->importing($xml, fresh: true);

        $rows = DB::table('import_batches')->where('id', '!=', $first)->get();

        $this->assertCount(1, $rows, (string) json_encode(DB::table('import_batches')->get()));

        // **種類ごとに数える**（混ぜた合計だと、記事が何本入ったのかが読めない）
        $counts = json_decode((string) $rows[0]->counts, true);

        $this->assertSame(0, $counts['content']['create'] ?? 0, (string) $rows[0]->counts);
        $this->assertSame(1, $counts['content']['skip_existing'] ?? 0, (string) $rows[0]->counts);
    }

    public function test_数は作った件数になる(): void
    {
        /*
         * 27D-2 では plan の件数がそのまま入っていた。**この段からは作った件数**である。
         */
        $this->importing($this->wxr(
            '<item>'.$this->entry('11', 'news').'</item>'
            .'<item>'.$this->entry('12', 'news').'</item>',
        ));

        $counts = json_decode((string) DB::table('import_batches')->value('counts'), true);

        $this->assertSame(2, $counts['content']['create'] ?? 0);
        $this->assertSame(2, DB::table('news')->count());
    }

    public function test_入れないと決まっている型は作らない(): void
    {
        // メニュー項目は入れない（§A.2）。plan は数えるだけで止めない
        $this->importing($this->wxr(
            '<item>'.$this->entry('11', 'news').'</item>'
            .'<item>'.$this->entry('12', 'nav_menu_item').'</item>',
        ));

        $this->assertSame(1, DB::table('news')->count());
        $this->assertSame(1, DB::table('import_records')->count());
    }

    public function test_ページは記事の型へ入らない(): void
    {
        // release-20260909 / wxr-pages: ページは `pages` へ。記事の表は増えない
        $this->importing($this->wxr(
            '<item>'.$this->entry('11', 'news').'</item>'
            .'<item>'.$this->entry('12', 'page').'</item>',
        ));

        $this->assertSame(1, DB::table('news')->count());
        $this->assertSame(1, DB::table('pages')->count());
        $this->assertSame(2, DB::table('import_records')->count());
    }

    public function test_題名が空なら無題として入れる(): void
    {
        /*
         * §F — 空の `title` は `(無題)`。空のまま入れると、一覧が空行の並びになって
         * どれがどれだか分からなくなる。
         */
        $this->importing($this->wxr(
            '<item><title></title><wp:post_id>11</wp:post_id>'
            .'<wp:post_type>news</wp:post_type><wp:status>publish</wp:status>'
            .'<wp:post_name>aru-slug</wp:post_name>'
            .'<content:encoded><![CDATA[本文]]></content:encoded></item>',
        ));

        $this->assertSame('(無題)', DB::table('news')->value('title'));
    }

    public function test_slugが空ならidから作る(): void
    {
        // §F — 空の `post_name` は id から作る。空だと URL を作れない
        $this->importing($this->wxr(
            '<item><title>題名あり</title><wp:post_id>11</wp:post_id>'
            .'<wp:post_type>news</wp:post_type><wp:status>publish</wp:status>'
            .'<content:encoded><![CDATA[本文]]></content:encoded></item>',
        ));

        $this->assertSame('11', DB::table('news')->value('slug'));
    }

    // ── 書いてよい表だけを触る ──────────────────────────────

    public function test_許した表以外は1行も変わらない(): void
    {
        $path = $this->write('a.xml', $this->wxr('<item>'.$this->entry('11', 'news').'</item>'));

        $before = $this->snapshot(self::ALLOWED);
        $writes = $this->watchWrites(self::ALLOWED);

        $this->apply($path, backup: true, hash: $this->hashFor($path))->assertExitCode(0);

        $this->assertSame([], $writes(), '許していない表への書き込みが出ています');
        $this->assertSame($before, $this->snapshot(self::ALLOWED), '許していない表の中身が変わっています');
    }

    // ── 途中で落ちたとき ────────────────────────────────────

    public function test_落ちたら記録が失敗になる(): void
    {
        // 既にある綴りとぶつかる記事は `CreateContent` が断る
        $path = $this->write('a.xml', $this->wxr($this->duplicateSlug('11')));

        $plan = app(PlanBuilder::class)->build($path, ImportOptions::fromCli([], [], false));
        $this->assertTrue($plan->isUsable(), json_encode($plan->findings(), JSON_UNESCAPED_UNICODE));

        $this->apply($path, backup: true, hash: $this->hashFor($path))->assertExitCode(1);

        $batch = DB::table('import_batches')->first();

        $this->assertNotNull($batch);
        $this->assertSame('failed', $batch->status);
        $this->assertNotNull($batch->failed_at);
        $this->assertNull($batch->rolled_back_at);
    }

    public function test_落ちたことを監査に残す(): void
    {
        /*
         * **終わり方 3 通りのうち、落ちた場合だけ何も残らない**のは避ける。
         * 半端に入っているので、後から見る人はまず「何が起きたか」を知りたい。
         */
        $path = $this->write('a.xml', $this->wxr($this->duplicateSlug('11')));

        $this->apply($path, backup: true, hash: $this->hashFor($path))->assertExitCode(1);

        $log = DB::table('audit_logs')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame('cli', $log->interface);
        $this->assertSame((string) DB::table('import_batches')->value('id'), $log->resource_id);
    }

    // ── ヘルパー ────────────────────────────────────────────

    private function importing(string $xml, bool $fresh = false): void
    {
        $path = $this->write($fresh ? 'b.xml' : 'a.xml', $xml);

        $this->apply($path, backup: true, hash: $this->hashFor($path))->assertExitCode(0);
    }

    private function apply(string $path, bool $backup = false, ?string $hash = null): PendingCommand
    {
        $options = ['file' => $path];

        if ($backup) {
            $options['--i-have-a-backup'] = true;
        }

        if ($hash !== null) {
            $options['--plan-hash'] = $hash;
        }

        /** @var PendingCommand */
        return $this->artisan('agentcms:wxr-apply', $options);
    }

    private function hashFor(string $path): string
    {
        return (string) app(PlanBuilder::class)
            ->build($path, ImportOptions::fromCli([], [], false))
            ->fingerprint();
    }

    /**
     * `CreateContent` が断る中身。
     *
     * **plan が見ないところで断られる**ものを選ぶ。ここで見たいのは
     * 「途中で落ちたときの終わり方」であって、plan の検査ではない。
     * 綴りの重複は plan が見ない（既にある記事の綴りまでは突き合わせない）。
     */
    private function duplicateSlug(string $id): string
    {
        DB::table('news')->insert([
            'title' => '先に在る記事',
            'slug' => 'kaburu',
            'body' => '本文',
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return "<item><title>題名</title><wp:post_id>{$id}</wp:post_id>"
            .'<wp:post_type>news</wp:post_type><wp:status>publish</wp:status>'
            .'<wp:post_name>kaburu</wp:post_name>'
            .'<content:encoded><![CDATA[本文]]></content:encoded></item>';
    }

    private function entry(string $id, string $type, string $status = 'publish'): string
    {
        return "<title>記事 {$id}</title><wp:post_id>{$id}</wp:post_id>"
            ."<wp:post_type>{$type}</wp:post_type><wp:status>{$status}</wp:status>"
            ."<content:encoded><![CDATA[本文 {$id}]]></content:encoded>";
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
