<?php

declare(strict_types=1);

namespace Tests\Feature\Wxr;

use AgentCms\Core\Wxr\Plan\ImportOptions;
use AgentCms\Core\Wxr\Plan\PlanBuilder;
use App\Support\OperationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Tests\Feature\Wxr\Concerns\WatchesDatabaseWrites;
use Tests\TestCase;

/**
 * `apply` の入口と、batch の記録（Phase 9.14 / STAGE 27D-2・SPEC §D.4・§D.5・§E.2）。
 *
 * ## この段では利用者のデータへ 1 件も触らない
 *
 * 記事も分類項目もコメントも作らない。作るのは `import_batches` の 1 行と、監査の 1 行だけである。
 * **入口の守りを、失うもののない状態で固める。**
 *
 * ## 止まるときは batch を作らない
 *
 * 作ると「何もしていない `running` の batch」が残り、次に実行する人が
 * 「前回どこまで行ったのか」を読み違える。
 */
final class ApplyWxrEntryTest extends TestCase
{
    use RefreshDatabase;
    use WatchesDatabaseWrites;

    /** batch と監査だけは変わってよい */
    private const ALLOWED = ['import_batches', 'audit_logs'];

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/wxr-apply-'.bin2hex(random_bytes(6));
        mkdir($this->dir);

        /*
         * **取り込み先は「公開できる」状態にしておく。**
         *
         * 設定行が無い型は `public` が既定 false で、そのままだとどの取り込みも
         * §C.2 で止まる。ここで確かめたいのは入口の守りであって、そこではない。
         */
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

    // ── backup を先に要求する ───────────────────────────────

    public function test_backupが無ければ動かない(): void
    {
        $this->apply($this->write('a.xml', $this->wxr()))
            ->expectsOutputToContain('先に backup を取ってください')
            ->assertExitCode(1);

        $this->assertSame(0, DB::table('import_batches')->count());
    }

    public function test_backupの判定はファイルを開く前に来る(): void
    {
        /*
         * **backup を持っていない人に、数百 MB を読ませない。**
         * 読めないファイルでも backup の文面が出ることで、順序が固定される。
         */
        $this->artisan('agentcms:wxr-apply', ['file' => $this->dir.'/does-not-exist.xml'])
            ->expectsOutputToContain('先に backup を取ってください')
            ->assertExitCode(1);
    }

    // ── plan の結果を照合する ───────────────────────────────

    public function test_plan_hashが無ければ動かない(): void
    {
        $this->apply($this->write('a.xml', $this->wxr()), backup: true)
            ->expectsOutputToContain('先に plan を実行し')
            ->assertExitCode(1);

        $this->assertSame(0, DB::table('import_batches')->count());
    }

    public function test_plan_hashが合わなければ動かない(): void
    {
        $this->apply($this->write('a.xml', $this->wxr()), backup: true, hash: str_repeat('0', 64))
            ->expectsOutputToContain('plan の結果と一致しません')
            ->assertExitCode(1);

        $this->assertSame(0, DB::table('import_batches')->count());
    }

    public function test_どちらのhashも出力に出さない(): void
    {
        /*
         * **期待している値を出せば、貼れば通るだけのゲートになる**（§D.4）。
         * 照合の意味が無くなる。
         */
        $path = $this->write('a.xml', $this->wxr());
        $wrong = str_repeat('0', 64);

        $this->apply($path, backup: true, hash: $wrong)
            ->doesntExpectOutputToContain($this->hashFor($path))
            ->doesntExpectOutputToContain($wrong)
            ->assertExitCode(1);
    }

    public function test_planが使えなければ動かない(): void
    {
        // 対応先の無い型は plan が止める（§C.2）
        $path = $this->write('a.xml', $this->wxr('<item>'.$this->entry('11', 'does_not_exist').'</item>'));

        $this->apply($path, backup: true, hash: str_repeat('0', 64))->assertExitCode(1);

        $this->assertSame(0, DB::table('import_batches')->count());
    }

    // ── batch の記録 ────────────────────────────────────────

    public function test_通れば記録が完了になる(): void
    {
        $path = $this->write('a.xml', $this->wxr('<item>'.$this->entry('11', 'news').'</item>'));

        $this->apply($path, backup: true, hash: $this->hashFor($path))->assertExitCode(0);

        $batch = DB::table('import_batches')->first();

        $this->assertNotNull($batch);
        $this->assertSame('completed', $batch->status);
        $this->assertNotNull($batch->finished_at);
        $this->assertNull($batch->failed_at);
        $this->assertNull($batch->rolled_back_at);
    }

    public function test_記録はファイル名だけを持ちパスを持たない(): void
    {
        // **パスは持たない**（§D.2 / §G）
        $path = $this->write('export.xml', $this->wxr());

        $this->apply($path, backup: true, hash: $this->hashFor($path))->assertExitCode(0);

        $this->assertSame('export.xml', DB::table('import_batches')->value('source'));
    }

    public function test_数はまだ作った件数ではなくplanの件数(): void
    {
        /*
         * **この段ではまだ 1 件も作らない。** 入るのは plan が数えた件数である。
         * 27D-3 で「作った件数」へ変わるので、そのときこのテストは書き換わる。
         */
        $path = $this->write('a.xml', $this->wxr(
            '<item>'.$this->entry('11', 'news').'</item>'
            .'<item>'.$this->entry('12', 'news').'</item>',
        ));

        $this->apply($path, backup: true, hash: $this->hashFor($path))->assertExitCode(0);

        $counts = json_decode((string) DB::table('import_batches')->value('counts'), true);

        $this->assertSame(2, $counts['content']['create'] ?? 0);
    }

    // ── 利用者のデータへ触らない ────────────────────────────

    public function test_batchと監査以外は1行も変わらない(): void
    {
        /*
         * **入れるものが無い WXR で確かめる。**
         *
         * 記事が 1 件でもあれば、27D-3 以降はそれが入る（入るのが正しい）。
         * ここで見たいのは**入口を通っただけでは何も動かない**ことなので、
         * 入れるものを 0 件にして、batch と監査以外が動かないことを固定する。
         */
        $path = $this->write('a.xml', $this->wxr());

        $before = $this->snapshot(self::ALLOWED);
        $writes = $this->watchWrites(self::ALLOWED);

        $this->apply($path, backup: true, hash: $this->hashFor($path))->assertExitCode(0);

        $this->assertSame([], $writes(), '許していない表への書き込みが出ています');
        $this->assertSame($before, $this->snapshot(self::ALLOWED), '許していない表の中身が変わっています');
    }

    public function test_実際に変わったのはbatchと監査だけ(): void
    {
        /*
         * 上の検査は許した表を**見ないことで**通る。
         * こちらは全部を見て、**変わったのが本当にその 2 つだけ**かを確かめる。
         *
         * 名前どおり 2 つとも変わることを求める。片方しか変わらないなら、
         * **名前が実態と食い違っている**ということである。
         *
         * こちらも入れるものが 0 件の WXR で見る（上と同じ理由）。
         */
        $path = $this->write('a.xml', $this->wxr());

        $before = $this->snapshot();

        $this->apply($path, backup: true, hash: $this->hashFor($path))->assertExitCode(0);

        $changed = array_keys(array_diff_assoc($this->snapshot(), $before));
        sort($changed);

        $this->assertSame(['audit_logs', 'import_batches'], $changed);
    }

    // ── 監査に残す ──────────────────────────────────────────

    public function test_取り込みを監査に残す(): void
    {
        // §E.2 — 監査には残す。**対象は batch id**
        $path = $this->write('a.xml', $this->wxr('<item>'.$this->entry('11', 'news').'</item>'));

        $this->apply($path, backup: true, hash: $this->hashFor($path))->assertExitCode(0);

        $log = DB::table('audit_logs')->latest('id')->first();
        $batch = DB::table('import_batches')->value('id');

        $this->assertNotNull($log);
        $this->assertSame('cli', $log->interface);
        $this->assertSame($batch, $log->resource_id);
    }

    public function test_監査に本文も氏名もメールも入らない(): void
    {
        /*
         * §E.2 — **本文・投稿者名・メールを入れない。**
         * 監査は残り続けるので、ここへ入れたものは消せない。
         */
        $path = $this->write('a.xml', $this->wxr(
            '<item><title>山田太郎さんの記事</title><wp:post_id>11</wp:post_id>'
            .'<wp:post_type>news</wp:post_type><wp:status>publish</wp:status>'
            .'<content:encoded><![CDATA[連絡先は yamada@example.com です]]></content:encoded></item>',
        ));

        $this->apply($path, backup: true, hash: $this->hashFor($path))->assertExitCode(0);

        $logs = (string) json_encode(DB::table('audit_logs')->get(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('山田太郎', $logs);
        $this->assertStringNotContainsString('yamada@example.com', $logs);
        $this->assertStringNotContainsString('連絡先', $logs);
    }

    public function test_止まったときも監査に残す(): void
    {
        /*
         * **通らなかったことも記録に残す。** 残さないと、
         * 「誰も実行していない」と「実行して断られた」が同じに見える。
         */
        $path = $this->write('a.xml', $this->wxr());

        $this->apply($path, backup: true, hash: str_repeat('0', 64))->assertExitCode(1);

        $log = DB::table('audit_logs')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame('cli', $log->interface);
        // batch はまだ無いので、対象の id も無い
        $this->assertNull($log->resource_id);
    }

    public function test_監査にファイル名もパスも入らない(): void
    {
        /*
         * ファイル名は `import_batches.source` が持つ。監査は batch id で辿れるので、
         * **同じものを 2 か所へ書かない**。パスは §G のとおりどこにも持たない。
         */
        $path = $this->write('yamada-blog.xml', $this->wxr());

        // **通った経路と断った経路の両方**を見る。metadata は断る側にしか無い
        $this->apply($path, backup: true, hash: $this->hashFor($path))->assertExitCode(0);
        $this->apply($path, backup: true, hash: str_repeat('0', 64))->assertExitCode(1);
        $this->apply($path)->assertExitCode(1);

        $logs = (string) json_encode(DB::table('audit_logs')->get(), JSON_UNESCAPED_UNICODE);

        $this->assertGreaterThanOrEqual(3, DB::table('audit_logs')->count());
        $this->assertStringNotContainsString('yamada-blog', $logs);
        $this->assertStringNotContainsString($this->dir, $logs);
    }

    // ── AI へは 1 つも出さない ──────────────────────────────

    public function test_取り込みはaiへ1つも出さない(): void
    {
        /*
         * §E.3 — 取り込みは**他所のサイトの全部を書き込む**操作で、
         * 確認も backup も要る。AI に渡してよい形にならない。
         *
         * ここには「件数は 38 のまま」と書いた pin があったが、**その隣の
         * assert は 39 を見ていた**。数は名前の代わりにならないので落とした。
         * 公開範囲そのものは `McpBoundaryTest` が名前で固定している。
         */
        foreach (OperationCatalog::ids() as $id) {
            $this->assertStringNotContainsString('wxr', $id);
            $this->assertStringNotContainsString('import', $id);
        }
    }

    // ── ヘルパー ────────────────────────────────────────────

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

    private function entry(string $id, string $type): string
    {
        return "<title>記事 {$id}</title><wp:post_id>{$id}</wp:post_id>"
            ."<wp:post_type>{$type}</wp:post_type><wp:status>publish</wp:status>"
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
