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
 * `apply` は**何件入ったか**を出す（WP 一周 2026-09-06 / F-21）。
 *
 * ## なぜ要るのか
 *
 * `apply` は batch id の 1 行しか出していなかった。`plan` は「`create` 8 件」と
 * **予告する**のに、実行した結果が画面に出ない。数は `import_batches.counts` に
 * 入っているが、**SQL を叩ける人にしか読めない**。WordPress から来た人には、
 * 入ったのか入らなかったのかを確かめる手立てが無かった。
 *
 * ## 並べて比べられること
 *
 * 予告と結果は**同じ書き方**で出す。ここだけ別の書き方にすると、
 * 「8 件と言ったのに 8 件入ったのか」を目で確かめられない。
 */
final class ApplyWxrSummaryTest extends TestCase
{
    use RefreshDatabase;

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/wxr-summary-'.bin2hex(random_bytes(6));
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

    public function test_入った件数の要約を出す(): void
    {
        $out = $this->applying($this->threeEntries());

        $this->assertStringContainsString('取り込みの結果:', $out);
        $this->assertMatchesRegularExpression('/^  create\s+3 件$/m', $out);
    }

    /** **種類ごとに読める。** 合計だけだと記事が何本入ったのか分からない */
    public function test_種類ごとの内訳も出す(): void
    {
        $out = $this->applying($this->threeEntries());

        $this->assertMatchesRegularExpression('/^    content\s+3 件$/m', $out);
    }

    /**
     * 🔴 **plan の予告と同じ行の形で出す。** 並べて比べられることが目的なので、
     * 桁の揃え方まで一致していること（片方だけ書き方を変えたら、ここで落ちる）。
     */
    public function test_planの予告と同じ書き方で出す(): void
    {
        $path = $this->write($this->threeEntries());

        Artisan::call('agentcms:wxr-plan', ['file' => $path]);
        $planLine = $this->lineFor(Artisan::output(), 'create');

        $out = $this->applying($this->threeEntries(), $path);
        $applyLine = $this->lineFor($out, 'create');

        $this->assertSame($planLine, $applyLine, 'plan の予告と apply の結果で書き方が違う');
    }

    /**
     * 2 回目は台帳に在るので 1 件も作らない。**そのことが読めること**
     * （`create` が消えて `skip_existing` になる）。
     */
    public function test_2回目は作らなかったことが読める(): void
    {
        $path = $this->write($this->threeEntries());

        $first = $this->applying($this->threeEntries(), $path);
        $this->assertMatchesRegularExpression('/^  create\s+3 件$/m', $first);

        $second = $this->applying($this->threeEntries(), $path);

        $this->assertMatchesRegularExpression('/^  skip_existing\s+3 件$/m', $second);
        $this->assertDoesNotMatchRegularExpression('/^  create\s/m', $second);
    }

    /** 何も入っていない WXR では、そう言って終わる */
    public function test_取り込むものが無ければそう言う(): void
    {
        $out = $this->applying($this->emptyWxr());

        $this->assertStringContainsString('取り込むものはありませんでした。', $out);
    }

    // ── 道具 ────────────────────────────────────────────────

    /** `create` などの行を、前後の空白を落とさずそのまま取り出す */
    private function lineFor(string $output, string $action): string
    {
        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^  '.preg_quote($action, '/').'\s/', $line) === 1) {
                return rtrim($line, "\r");
            }
        }

        $this->fail("[{$action}] の行が出力に無い:\n{$output}");
    }

    private function applying(string $xml, ?string $path = null): string
    {
        $path ??= $this->write($xml);

        $code = Artisan::call('agentcms:wxr-apply', [
            'file' => $path,
            '--i-have-a-backup' => true,
            '--plan-hash' => (string) app(PlanBuilder::class)
                ->build($path, ImportOptions::fromCli([], [], false))
                ->fingerprint(),
        ]);

        $out = Artisan::output();

        $this->assertSame(0, $code, $out);

        return $out;
    }

    private function emptyWxr(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"'
            .' xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"'
            .' xmlns:wp="http://wordpress.org/export/1.2/">'
            .'<channel><title>移行元</title><wp:wxr_version>1.2</wp:wxr_version>'
            .'</channel></rss>';
    }

    private function threeEntries(): string
    {
        $items = '';

        foreach (['11', '12', '13'] as $id) {
            $items .= "<item><title>記事 {$id}</title><wp:post_id>{$id}</wp:post_id>"
                .'<wp:post_type>news</wp:post_type><wp:status>publish</wp:status>'
                ."<wp:post_name>kiji-{$id}</wp:post_name>"
                ."<content:encoded><![CDATA[本文 {$id}]]></content:encoded></item>";
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"'
            .' xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"'
            .' xmlns:wp="http://wordpress.org/export/1.2/">'
            .'<channel><title>移行元</title><wp:wxr_version>1.2</wp:wxr_version>'
            .$items
            .'</channel></rss>';
    }

    private function write(string $contents): string
    {
        $path = $this->dir.'/a.xml';
        file_put_contents($path, $contents);

        return $path;
    }
}
