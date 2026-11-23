<?php

declare(strict_types=1);

namespace Tests\Feature\Wxr;

use AgentCms\Core\Wxr\Plan\ImportOptions;
use AgentCms\Core\Wxr\Plan\PlanBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `agentcms:wxr-plan`（WP 一周 2026-09-03 / 2 周目の前チェック）。
 *
 * ## なぜ足したか
 *
 * `agentcms:wxr-apply` は `--plan-hash` を必須のゲートにしていて、値が無いと
 * 「`php artisan agentcms:wxr-plan <file>` を実行してください」と案内する。
 * **その案内どおりに打つと `Command "agentcms:wxr-plan" is not defined` になっていた。**
 * つまり取り込みは、案内された道をなぞる限り最後まで到達できない。
 *
 * これまで hash を得ていたのはテストだけで、`PlanBuilder` を PHP から直に叩いていた
 * （`ApplyWxrContentTest::hashFor()`）。買った人はそれをできない。
 *
 * ## 何もしないコマンドである
 *
 * plan は**読むだけ**で、行を 1 つも書かない。`apply` と同じ `PlanBuilder` を
 * 同じ `ImportOptions` で叩き、出た結果を見せるだけである。
 * 判断は増やさない — 増やすと apply と plan で答えが割れる。
 */
final class PlanWxrCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/wxr-plan-'.bin2hex(random_bytes(6));
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

    public function test_取り込める_wxr_なら_plan_が_hash_を出す(): void
    {
        $path = $this->write('a.xml', $this->wxr('<item>'.$this->entry('11', 'news').'</item>'));

        $code = Artisan::call('agentcms:wxr-plan', ['file' => $path]);
        $output = Artisan::output();

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString($this->expectedHash($path), $output, 'plan-hash が出ていない');
    }

    public function test_plan_は行を1つも書かない(): void
    {
        $path = $this->write('a.xml', $this->wxr('<item>'.$this->entry('11', 'news').'</item>'));

        Artisan::call('agentcms:wxr-plan', ['file' => $path]);

        $this->assertSame(0, DB::table('news')->count(), 'plan が記事を作っている');
        $this->assertSame(0, DB::table('import_batches')->count(), 'plan が batch を作っている');
        $this->assertSame(0, DB::table('import_records')->count(), 'plan が台帳へ書いている');
    }

    /**
     * **買った人がなぞる道が、最後まで繋がっていること。**
     *
     * inspect（何が入っているか）→ plan（hash）→ apply（取り込む）→ rollback（戻す）。
     * どこか 1 つでも切れていたら、移行は机上のものになる。
     */
    public function test_inspect_から_rollback_まで一周する(): void
    {
        $path = $this->write('a.xml', $this->wxr('<item>'.$this->entry('11', 'news').'</item>'));

        $this->assertSame(0, Artisan::call('agentcms:wxr-inspect', ['file' => $path]));

        $this->assertSame(0, Artisan::call('agentcms:wxr-plan', ['file' => $path]));
        $hash = $this->hashFrom(Artisan::output());

        $this->assertSame(0, Artisan::call('agentcms:wxr-apply', [
            'file' => $path,
            '--i-have-a-backup' => true,
            '--plan-hash' => $hash,
        ]), Artisan::output());

        $this->assertSame(1, DB::table('news')->count(), '記事が入っていない');

        $batch = (string) DB::table('import_batches')->value('id');

        $this->assertSame(0, Artisan::call('agentcms:wxr-rollback', [
            'batch' => $batch,
            '--i-have-a-backup' => true,
        ]), Artisan::output());

        $this->assertSame(0, DB::table('news')->count(), '取り消しても記事が残っている');
    }

    /**
     * **印字した行を、1 文字も足さずにそのまま打つ**（WP 一周 2026-09-06 / F-19）。
     *
     * plan は以前 `--type` / `--taxonomy` を載せずに apply の行を出していた。
     * `<file>` は打ち直しが要ると分かるが、指定が抜けているのは見て分からない。
     * そのまま打つと apply は `missing_taxonomy` /
     * `taxonomy_not_declared_for_type` で止まる。**分類はちゃんと在るのに
     * 「無い」と言われる**ので、指定の付け忘れだと気づけない。
     *
     * だからここは「`--type=` が出ているか」を見ない。**出た行をそのまま
     * `Artisan::call()` へ渡す。** 印字から指定が 1 つでも落ちれば、
     * apply がその場で止まってこのテストが落ちる。
     */
    public function test_印字された_apply_の行をそのまま打つと通る(): void
    {
        $this->artisan('agentcms:taxonomy-sync')->assertExitCode(0);

        $path = $this->write('mapped.xml', $this->wxr(
            $this->category('2', 'oshirase', 'お知らせ')
            .'<item>'
            .$this->entry('11', 'post')
            .'<category domain="category" nicename="oshirase"><![CDATA[お知らせ]]></category>'
            .'</item>',
        ));

        $code = Artisan::call('agentcms:wxr-plan', [
            'file' => $path,
            '--type' => ['post:news'],
            '--taxonomy' => ['category:news_category'],
        ]);
        $planned = Artisan::output();

        $this->assertSame(0, $code, $planned);

        $command = $this->applyCommandFrom($planned);

        // 打ち直す所を残していないこと（`<file>` のような穴を出さない）
        $this->assertStringNotContainsString('<', $command, "apply の行に打ち直す穴がある:\n".$command);

        $this->assertSame(0, Artisan::call($command), "コピペした行が通らない:\n".$command."\n".Artisan::output());

        $this->assertSame(1, DB::table('news')->count(), '印字された行をそのまま打っても記事が入らない');

        // `--taxonomy=` が届いていなければ、ここは WXR 側の綴り（`category`）になる
        $this->assertSame(
            'news_category',
            DB::table('import_records')->where('kind', 'term')->value('target_type'),
            '分類項目が --taxonomy= で指した先に入っていない',
        );
    }

    /**
     * 旗（`--media` / `--strict-term-definitions`）も落とさない。
     *
     * ## ここだけ「打って通るか」で確かめられない
     *
     * `--media` は**取りに行く候補が実在したときだけ** hash に載る
     * （`PlanScan::effective()`）。つまり効き目を出すには添付を持つ WXR が要り、
     * そのまま apply すると**添付を実際に取りに行く**。テストから外へ出す訳には
     * いかないので、ここは**印字の形**を見る。
     *
     * それでも落とせない旗である: 添付を持つ WXR を `--media` 付きで下見して、
     * 旗の無い行を貼ると、apply は「plan の結果と一致しません」で止まる。
     * 言葉は正しいが、**何も変えた覚えが無いのに一致しない**ので辿れない。
     */
    public function test_付けた旗も印字された行に載る(): void
    {
        // 添付を持つ WXR（`--media` が hash に載る条件）。取り込みはしない
        $path = $this->write('flags.xml', $this->wxr(
            '<item>'.$this->entry('11', 'news').'</item>'
            .'<item><title>写真</title><wp:post_id>13</wp:post_id>'
            .'<wp:post_type>attachment</wp:post_type><wp:status>inherit</wp:status>'
            .'<wp:attachment_url>https://old.example.test/photo.jpg</wp:attachment_url></item>',
        ));

        $code = Artisan::call('agentcms:wxr-plan', [
            'file' => $path,
            '--media' => true,
            '--strict-term-definitions' => true,
        ]);
        $planned = Artisan::output();

        $this->assertSame(0, $code, $planned);

        $command = $this->applyCommandFrom($planned);

        $this->assertStringContainsString('--media', $command, '--media が印字された行に無い');
        $this->assertStringContainsString(
            '--strict-term-definitions',
            $command,
            '--strict-term-definitions が印字された行に無い',
        );
    }

    /**
     * 空白の入った道を裸で出すと、貼った所で **2 つの引数**になって別の理由で止まる。
     * 「コピペしたのに動かない」は原因に辿り着けない失敗なので、ここで引用符を付ける。
     */
    public function test_空白の入った道でもコピペして通る(): void
    {
        $path = $this->write('wp export.xml', $this->wxr('<item>'.$this->entry('11', 'news').'</item>'));

        $code = Artisan::call('agentcms:wxr-plan', ['file' => $path]);
        $planned = Artisan::output();

        $this->assertSame(0, $code, $planned);

        $command = $this->applyCommandFrom($planned);

        $this->assertStringContainsString("'".$path."'", $command, '空白の入った道に引用符が付いていない');
        $this->assertSame(0, Artisan::call($command), "コピペした行が通らない:\n".$command."\n".Artisan::output());
        $this->assertSame(1, DB::table('news')->count());
    }

    /**
     * **取り込めない plan に hash を出さない。**
     *
     * 出せば利用者はそれを apply へ貼る。「問題はあるが通した plan」を
     * 一度でも許すと、次からは全部それになる（`PlanFindings::blocksPlan()`）。
     */
    public function test_取り込めない_wxr_では_hash_を出さず_理由を出す(): void
    {
        $path = $this->write('b.xml', $this->wxr('<item>'.$this->entry('11', 'shiranai_kata').'</item>'));

        $code = Artisan::call('agentcms:wxr-plan', ['file' => $path]);
        $output = Artisan::output();

        $this->assertNotSame(0, $code, '取り込めないのに 0 で終わっている');
        $this->assertDoesNotMatchRegularExpression('/[0-9a-f]{64}/', $output, '使えない plan の hash を出している');
        $this->assertStringContainsString('取り込めません', $output);
    }

    public function test_読めないファイルは理由を出して止まる(): void
    {
        $path = $this->write('c.xml', 'これは XML ではありません');

        $this->assertNotSame(0, Artisan::call('agentcms:wxr-plan', ['file' => $path]));
    }

    // ── ヘルパー ────────────────────────────────────────────

    private function expectedHash(string $path): string
    {
        return (string) app(PlanBuilder::class)
            ->build($path, ImportOptions::fromCli([], [], false))
            ->fingerprint();
    }

    /**
     * plan が印字した apply の行を、**shell へ貼ったときと同じ 1 行**に戻す。
     *
     * 行末の `\` は shell の継続なので、ここで畳む。`php artisan ` は
     * `Artisan::call()` が受け取らない前置きなので落とす。それ以外は触らない
     * （引用符も含めてそのまま渡す — `StringInput` が shell と同じ読み方をする）。
     */
    private function applyCommandFrom(string $output): string
    {
        $command = null;

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $line = trim($line);

            if ($command === null && ! str_starts_with($line, 'php artisan agentcms:wxr-apply')) {
                continue;
            }

            $continues = str_ends_with($line, '\\');
            $command = ($command === null ? '' : $command.' ').trim(rtrim($line, '\\'));

            if (! $continues) {
                break;
            }
        }

        $this->assertNotNull($command, "plan の出力に apply の行が無い:\n".$output);

        return trim(substr($command, strlen('php artisan ')));
    }

    private function category(string $id, string $slug, string $name, string $parent = ''): string
    {
        return "<wp:category><wp:term_id>{$id}</wp:term_id>"
            ."<wp:category_nicename>{$slug}</wp:category_nicename>"
            ."<wp:category_parent>{$parent}</wp:category_parent>"
            ."<wp:cat_name><![CDATA[{$name}]]></wp:cat_name></wp:category>";
    }

    private function hashFrom(string $output): string
    {
        preg_match('/[0-9a-f]{64}/', $output, $m);

        $this->assertNotEmpty($m, "plan の出力に hash が無い:\n".$output);

        return $m[0];
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
