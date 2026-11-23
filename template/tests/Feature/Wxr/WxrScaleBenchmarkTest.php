<?php

declare(strict_types=1);

namespace Tests\Feature\Wxr;

use AgentCms\Core\Wxr\Media\HostResolver;
use AgentCms\Core\Wxr\Plan\ImportOptions;
use AgentCms\Core\Wxr\Plan\PlanBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 実データ規模で測る（Phase 9.14 / STAGE 27F）。
 *
 * ## 既定では走らない
 *
 * 数千記事の取り込みを毎回の `verify` で回すと、誰も verify を回さなくなる。
 * 環境変数を渡したときだけ走る（`ImportLedgerMysqlTest` と同じ作法）。
 * **skip を合格と読ませない** — 明示的な検証手順として実行する。
 *
 * ```bash
 * php tests/fixtures/wxr/generate.php /tmp/scale.xml 3000 12000 200 500
 *
 * WXR_BENCHMARK=1 WXR_BENCHMARK_FIXTURE=/tmp/scale.xml \
 * WXR_BENCHMARK_OUT=/tmp/wxr-metrics.json \
 *   ./vendor/bin/pest tests/Feature/Wxr/WxrScaleBenchmarkTest.php
 * ```
 *
 * ## 計測のための分岐を本体に足さない
 *
 * 数えるのは `DB::listen` で、本体は 1 行も知らない。
 * 計測用のフラグを本体へ入れると、**本番の経路が計測の都合で変わる**。
 *
 * ## メディアは取りに行かない
 *
 * 相手のサーバー次第の数字を成果物へ載せても意味が無い。`Http::fake` で
 * 即座に返し、測るのは**保存・本文の置換・取り消し**のこちら側だけにする。
 */
final class WxrScaleBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $queries = [];

    private bool $recording = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('WXR_BENCHMARK') !== '1') {
            $this->markTestSkipped('WXR_BENCHMARK=1 のときだけ走ります（実データ規模の計測）');
        }

        $this->artisan('agentcms:taxonomy-sync')->assertExitCode(0);

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

        DB::listen(function ($query): void {
            if ($this->recording) {
                $this->queries[] = (string) $query->sql;
            }
        });
    }

    public function test_実データ規模で取り込み_取り消し_再取り込みが通る(): void
    {
        $path = (string) getenv('WXR_BENCHMARK_FIXTURE');

        $this->assertFileExists($path, 'WXR_BENCHMARK_FIXTURE を渡してください');

        $this->fakeMedia();

        $taxonomies = ['category:news_category'];
        $hash = (string) app(PlanBuilder::class)
            ->build($path, ImportOptions::fromCli([], $taxonomies, true))
            ->fingerprint();

        $metrics = [
            'fixture' => [
                'bytes' => filesize($path),
                'posts' => 0,
                'comments' => 0,
                'terms' => 0,
                'media' => 0,
            ],
            'driver' => DB::connection()->getDriverName(),
        ];

        // ── plan ───────────────────────────────────────────
        $metrics['plan'] = $this->measure(function () use ($path, $taxonomies): void {
            app(PlanBuilder::class)->build($path, ImportOptions::fromCli([], $taxonomies, true));
        });

        // ── apply ──────────────────────────────────────────
        $metrics['apply'] = $this->measure(function () use ($path, $hash, $taxonomies): void {
            $this->artisan('agentcms:wxr-apply', [
                'file' => $path,
                '--i-have-a-backup' => true,
                '--plan-hash' => $hash,
                '--media' => true,
                '--taxonomy' => $taxonomies,
            ])->assertExitCode(0);
        });

        $metrics['fixture']['posts'] = DB::table('news')->count();
        $metrics['fixture']['comments'] = DB::table('content_comments')->count();
        $metrics['fixture']['terms'] = DB::table('taxonomy_terms')->count();
        $metrics['fixture']['media'] = DB::table('media')->count();

        $this->assertGreaterThan(0, $metrics['fixture']['posts'], '記事が 1 件も入っていません');

        // **F-5** — 1 記事あたりの台帳照会数
        $metrics['apply']['ledger_selects_per_post'] = $metrics['fixture']['posts'] === 0
            ? null
            : round($metrics['apply']['ledger_selects'] / $metrics['fixture']['posts'], 3);

        $id = (string) DB::table('import_batches')->value('id');

        // ── rollback ───────────────────────────────────────
        $metrics['rollback'] = $this->measure(function () use ($id): void {
            $this->artisan('agentcms:wxr-rollback', [
                'batch' => $id,
                '--i-have-a-backup' => true,
            ])->assertExitCode(0);
        });

        $metrics['after_rollback'] = [
            'posts' => DB::table('news')->count(),
            'comments' => DB::table('content_comments')->count(),
            'terms' => DB::table('taxonomy_terms')->count(),
            'media' => DB::table('media')->count(),
            'records' => DB::table('import_records')->count(),
        ];

        // ── 再 apply（F-10 が閉じたことを実データ規模で）──────
        $metrics['reapply'] = $this->measure(function () use ($path, $hash, $taxonomies): void {
            $this->artisan('agentcms:wxr-apply', [
                'file' => $path,
                '--i-have-a-backup' => true,
                '--plan-hash' => $hash,
                '--media' => true,
                '--taxonomy' => $taxonomies,
            ])->assertExitCode(0);
        });

        $metrics['after_reapply'] = [
            'posts' => DB::table('news')->count(),
            'comments' => DB::table('content_comments')->count(),
        ];

        // **入り直した分が二重になっていない**
        $this->assertSame(
            $metrics['fixture']['posts'],
            $metrics['after_reapply']['posts'],
            '再取り込みで記事が二重になっています',
        );

        $out = (string) getenv('WXR_BENCHMARK_OUT');

        if ($out !== '') {
            file_put_contents($out, json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }

        // 走ったことが読めるように、素の数字も残す
        fwrite(STDERR, "\n".json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    /**
     * 1 区間を測る。**時間・ピークメモリ・問い合わせの数**。
     *
     * @return array<string, float|int>
     */
    private function measure(callable $run): array
    {
        $this->queries = [];
        $this->recording = true;

        $before = memory_get_peak_usage(true);
        $start = hrtime(true);

        $run();

        $elapsed = (hrtime(true) - $start) / 1_000_000_000;
        $peak = memory_get_peak_usage(true);

        $this->recording = false;

        return [
            'seconds' => round($elapsed, 3),
            'peak_memory_mb' => round($peak / 1024 / 1024, 1),
            'peak_memory_delta_mb' => round(max(0, $peak - $before) / 1024 / 1024, 1),
            'queries' => count($this->queries),
            // **F-5** の材料
            'ledger_selects' => $this->countMatching('/^select .*\bimport_records\b/i'),
            // BundleJudge の二重問い合わせの材料
            'comment_selects' => $this->countMatching('/^select .*\bcontent_comments\b/i'),
        ];
    }

    private function countMatching(string $pattern): int
    {
        $count = 0;

        foreach ($this->queries as $sql) {
            if (preg_match($pattern, ltrim($sql)) === 1) {
                $count++;
            }
        }

        return $count;
    }

    /** 1x1 の PNG を即座に返す。**外へは繋がない** */
    private function fakeMedia(): void
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );

        Http::fake(['*' => Http::response((string) $png, 200, ['Content-Type' => 'image/png'])]);

        $this->app->instance(HostResolver::class, new class implements HostResolver
        {
            public function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
    }
}
