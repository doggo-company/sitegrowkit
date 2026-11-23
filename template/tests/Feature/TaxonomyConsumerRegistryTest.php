<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Taxonomy\ConsumerRegistry;
use AgentCms\Core\Taxonomy\Sync\TaxonomySynchronizer;
use AgentCms\Core\Taxonomy\Taxonomy;
use AgentCms\Core\Taxonomy\TaxonomyDefinitionRegistry;
use AgentCms\Core\Taxonomy\TaxonomyTerm;
use AgentCms\Core\Taxonomy\TaxonomyTermAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Phase 9.3B / STAGE 3C CORRECTION — **F-3C-2**。
 *
 * AI 向け索引（`.agentcms/registry/taxonomies.json`）の `consumers` は手書きの値であり、
 * **正は `content/schemas/*.json` の `taxonomies[]`**。
 * 両方を個別に assert するだけでは「片方だけ直した」状態を検出できないため、
 * `agentcms:taxonomy-check` が **read-only** で乖離を検出する。
 *
 * ## テストの安全性
 *
 * 索引の壊し方を試すが、**リポジトリのファイルは一切書き換えない**。
 * config の参照先を一時ファイルへ差し替え、tearDown で実ファイルが
 * 変わっていないことまで確認する（失敗しても dirty tree を残さない）。
 */
final class TaxonomyConsumerRegistryTest extends TestCase
{
    use RefreshDatabase;

    private string $realPath = '';

    private string $realHash = '';

    private string $tempPath = '';

    private string $tempDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->realPath = (string) config('agentcms.taxonomy_registry_path');
        $this->realHash = hash('sha256', (string) File::get($this->realPath));
    }

    protected function tearDown(): void
    {
        if ($this->tempPath !== '' && is_file($this->tempPath)) {
            @unlink($this->tempPath);
        }

        if ($this->tempDir !== '' && is_dir($this->tempDir)) {
            File::deleteDirectory($this->tempDir);
        }

        // 実ファイルを 1 バイトも変えていないこと（失敗時も dirty tree を残さない）
        if ($this->realPath !== '') {
            $this->assertSame(
                $this->realHash,
                hash('sha256', (string) File::get($this->realPath)),
                '実際の .agentcms/registry/taxonomies.json を書き換えた',
            );
        }

        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────
    // 1. 一致していれば従来どおり
    // ─────────────────────────────────────────────────────────────

    /** A-1 / A-12: 正常時の出力と終了コードを壊さない */
    public function test_a1_宣言と索引が一致すればpassする(): void
    {
        app(TaxonomySynchronizer::class)->sync();

        $this->artisan('agentcms:taxonomy-check')
            ->expectsOutputToContain('分類の定義 1 件 / コンテンツタイプからの参照 1 件')
            ->expectsOutputToContain('分類の定義とデータベースは一致しています')
            ->expectsOutputToContain('参照もすべて解決できました')
            ->assertExitCode(0);
    }

    /** 同梱の索引はそのまま整合している */
    public function test_同梱の索引は宣言と一致している(): void
    {
        $this->assertSame([], app(ConsumerRegistry::class)->problems(
            ['news_category'],
            ['news_category' => ['news']],
        ));
    }

    // ─────────────────────────────────────────────────────────────
    // 2〜8. 壊した索引はすべて FAIL
    // ─────────────────────────────────────────────────────────────

    /** A-2: schema にある consumer が索引に無い */
    public function test_a2_consumerの欠落を検出する(): void
    {
        $this->useRegistry(function (array $registry): array {
            $registry['taxonomies'][0]['consumers'] = [];

            return $registry;
        });

        $output = $this->runCheckExpectingFailure();

        $this->assertStringContainsString('news_category', $output);
        $this->assertStringContainsString('コンテンツタイプの宣言: news', $output);
        $this->assertStringContainsString('この索引の記録: （なし）', $output);
        $this->assertStringContainsString('.agentcms/registry/taxonomies.json', $output);
    }

    /** A-3: schema に無い consumer が索引にある */
    public function test_a3_余分なconsumerを検出する(): void
    {
        $this->useRegistry(function (array $registry): array {
            $registry['taxonomies'][0]['consumers'] = ['news', 'works'];

            return $registry;
        });

        $output = $this->runCheckExpectingFailure();

        $this->assertStringContainsString('この索引の記録: news / works', $output);
    }

    /** A-4: consumers の重複 */
    public function test_a4_consumersの重複を検出する(): void
    {
        $this->useRegistry(function (array $registry): array {
            $registry['taxonomies'][0]['consumers'] = ['news', 'news'];

            return $registry;
        });

        $this->assertStringContainsString('重複', $this->runCheckExpectingFailure());
    }

    /** A-5: consumers が文字列 list でない */
    public function test_a5_consumersがlistでない場合を検出する(): void
    {
        foreach ([
            'string' => 'news',
            'map' => ['0' => 'news', 'x' => 'works'],
            'null' => null,
            'number' => [1],
            'nested' => [['news']],
            'missing' => null,
        ] as $label => $value) {
            $this->useRegistry(static function (array $registry) use ($label, $value): array {
                if ($label === 'missing') {
                    unset($registry['taxonomies'][0]['consumers']);

                    return $registry;
                }

                $registry['taxonomies'][0]['consumers'] = $value;

                return $registry;
            });

            $this->assertStringContainsString(
                'consumers', $this->runCheckExpectingFailure(), "[{$label}] を通した",
            );
        }
    }

    /** A-6: 定義の無い taxonomy key が索引にある */
    public function test_a6_未知のtaxonomy_keyを検出する(): void
    {
        $this->useRegistry(static function (array $registry): array {
            $registry['taxonomies'][] = [
                'key' => 'unknown_tax', 'managed_by' => 'code', 'hierarchical' => true,
                'definition' => 'content/taxonomies/unknown_tax.json', 'consumers' => [],
            ];

            return $registry;
        });

        $output = $this->runCheckExpectingFailure();

        $this->assertStringContainsString('unknown_tax', $output);
        $this->assertStringContainsString('定義がありません', $output);
    }

    /** A-7: 定義があるのに索引の項目が無い */
    public function test_a7_定義に対応する項目の欠落を検出する(): void
    {
        $this->useRegistry(static function (array $registry): array {
            $registry['taxonomies'] = [];

            return $registry;
        });

        $output = $this->runCheckExpectingFailure();

        $this->assertStringContainsString('news_category', $output);
        $this->assertStringContainsString('索引に載っていません', $output);
    }

    /** A-8: JSON / 構造が壊れている */
    public function test_a8_不正な_j_s_o_nと構造を検出する(): void
    {
        foreach ([
            'broken json' => '{ "taxonomies": [',
            'not an object' => '[]',
            'missing key' => '{}',
            'not a list' => '{"taxonomies": {"news_category": []}}',
            'entry without key' => '{"taxonomies": [{"consumers": []}]}',
        ] as $label => $contents) {
            $this->writeRegistry($contents);

            $this->assertStringContainsString(
                '.agentcms/registry/taxonomies.json',
                $this->runCheckExpectingFailure(),
                "[{$label}] を通した",
            );
        }
    }

    /** 索引ファイルそのものが無い */
    public function test_索引ファイルの欠落を検出する(): void
    {
        $this->tempPath = sys_get_temp_dir().'/agentcms-registry-missing-'.uniqid().'.json';
        config(['agentcms.taxonomy_registry_path' => $this->tempPath]);
        app()->forgetInstance(ConsumerRegistry::class);

        $this->assertStringContainsString('見つかりません', $this->runCheckExpectingFailure());
    }

    // ─────────────────────────────────────────────────────────────
    // F-3C-3: 定義も参照も 0 件でも索引を検証する
    //
    // 早期 return で索引検査を飛ばすと、「taxonomy を全部やめたのに索引へ
    // 古い項目が残っている」状態を verify が見逃す。
    // ─────────────────────────────────────────────────────────────

    /** 1 / 8: 定義 0・参照 0・索引 0 なら SUCCESS（DB へ接続もしない） */
    public function test_f3c3_すべて空ならsuccessで_d_bへ接続しない(): void
    {
        $this->useEmptyTaxonomySetup('{"taxonomies": []}');

        $statements = $this->captureSql(function (): void {
            $exit = Artisan::call('agentcms:taxonomy-check');
            $output = Artisan::output();

            $this->assertSame(0, $exit, $output);
            $this->assertStringContainsString('分類の定義はありません', $output);
        });

        $this->assertSame([], $statements, 'DB へ接続した: '.implode(' / ', $statements));
    }

    /** 2 / 5 / 6 / 7: 定義 0・参照 0 でも索引に古い項目が残っていれば FAIL */
    public function test_f3c3_定義0でも古い索引項目を検出する(): void
    {
        $this->useEmptyTaxonomySetup((string) json_encode([
            'taxonomies' => [[
                'key' => 'retired_tax', 'managed_by' => 'code', 'hierarchical' => true,
                'definition' => 'content/taxonomies/retired_tax.json', 'consumers' => ['news'],
            ]],
        ]));

        $output = '';
        $statements = $this->captureSql(function () use (&$output): void {
            $output = $this->runCheckExpectingFailure();
        });

        $this->assertStringContainsString('retired_tax', $output);
        $this->assertStringContainsString('定義がありません', $output);
        $this->assertReadOnly($statements);
    }

    /** 3: 定義 0・参照 0 で索引の JSON が壊れていれば FAIL */
    public function test_f3c3_定義0でも不正な_j_s_o_nを検出する(): void
    {
        $this->useEmptyTaxonomySetup('{ "taxonomies": [');

        $output = '';
        $statements = $this->captureSql(function () use (&$output): void {
            $output = $this->runCheckExpectingFailure();
        });

        $this->assertStringContainsString('JSON を読み取れません', $output);
        $this->assertReadOnly($statements);
    }

    /** 4: 定義 0・参照 0 で索引ファイルが無ければ FAIL */
    public function test_f3c3_定義0でも索引ファイルの欠落を検出する(): void
    {
        $this->useEmptyTaxonomySetup(null);

        $output = '';
        $statements = $this->captureSql(function () use (&$output): void {
            $output = $this->runCheckExpectingFailure();
        });

        $this->assertStringContainsString(ConsumerRegistry::LABEL, $output);
        $this->assertStringContainsString('見つかりません', $output);
        $this->assertReadOnly($statements);
    }

    // ─────────────────────────────────────────────────────────────
    // 9 / 10. read-only であること
    // ─────────────────────────────────────────────────────────────

    /** A-9 / A-10: 不一致時に DB へ接続も書き込みもせず、sync も走らせない */
    public function test_a9_不一致時に_d_bへ触れずsyncも走らせない(): void
    {
        // 事前に同期しておき、「sync が走ったか」を件数で見分けられるようにする
        Taxonomy::query()->delete();

        $this->useRegistry(static function (array $registry): array {
            $registry['taxonomies'][0]['consumers'] = [];

            return $registry;
        });

        $statements = [];
        DB::listen(static function ($query) use (&$statements): void {
            $statements[] = strtolower($query->sql);
        });

        $this->assertSame(1, Artisan::call('agentcms:taxonomy-check'));

        $this->assertSame([], $statements, 'DB へ触れた: '.implode(' / ', $statements));
        $this->assertSame(0, Taxonomy::query()->count(), 'sync が走って taxonomy が作られた');
        $this->assertSame(0, TaxonomyTerm::query()->count());
        $this->assertSame(0, TaxonomyTermAssignment::query()->count());
    }

    /** エラー表示に秘密情報・絶対パス・SQL・接続情報を出さない */
    public function test_エラー表示に絶対パスや接続情報を出さない(): void
    {
        $this->useRegistry(static function (array $registry): array {
            $registry['taxonomies'][0]['consumers'] = ['works'];

            return $registry;
        });

        $output = $this->runCheckExpectingFailure();

        foreach (['SQLSTATE', 'mysql:', 'DB_', 'password', 'select ', base_path(), sys_get_temp_dir()] as $leak) {
            $this->assertStringNotContainsString($leak, $output, "[{$leak}] を表示した");
        }
    }

    /** A-15 相当: 判定ロジックへ taxonomy key / Content Type を hardcode しない */
    public function test_検証ロジックにkeyやtypeをhardcodeしない(): void
    {
        $code = (string) File::get(base_path('core/src/Taxonomy/ConsumerRegistry.php'));

        foreach (["'news'", "'news_category'", "'works'", "'product'"] as $literal) {
            $this->assertStringNotContainsString($literal, $code, "{$literal} を埋め込んでいる");
        }
    }

    // ─────────────────────────────────────────────────────────────
    // helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * 「taxonomy を 1 つも使っていないプロジェクト」を一時ディレクトリで作る。
     *
     * 定義ディレクトリを空に、schema から `taxonomies` 宣言を外し、
     * 索引だけを差し替える。**リポジトリのファイルは触らない。**
     *
     * @param  string|null  $registryContents  null なら索引ファイルを置かない
     */
    private function useEmptyTaxonomySetup(?string $registryContents): void
    {
        $base = sys_get_temp_dir().'/agentcms-empty-taxonomy-'.uniqid();
        $this->tempDir = $base;

        mkdir($base.'/taxonomies', 0777, true);
        mkdir($base.'/schemas/overrides', 0777, true);

        foreach (glob(base_path('content/schemas').'/*.json') ?: [] as $file) {
            /** @var array<string, mixed> $data */
            $data = json_decode((string) File::get($file), true, 32, JSON_THROW_ON_ERROR);
            unset($data['taxonomies']);

            File::put(
                $base.'/schemas/'.basename($file),
                (string) json_encode($data, JSON_UNESCAPED_UNICODE),
            );
        }

        foreach (glob(base_path('content/schemas/overrides').'/*.json') ?: [] as $file) {
            File::copy($file, $base.'/schemas/overrides/'.basename($file));
        }

        config([
            'agentcms.taxonomy_path' => $base.'/taxonomies',
            'agentcms.schema_path' => $base.'/schemas',
        ]);

        app()->forgetInstance(TaxonomyDefinitionRegistry::class);
        app()->forgetInstance(SchemaRegistry::class);

        if ($registryContents === null) {
            config(['agentcms.taxonomy_registry_path' => $base.'/missing-registry.json']);
            app()->forgetInstance(ConsumerRegistry::class);

            return;
        }

        $this->writeRegistry($registryContents);
    }

    /**
     * 実行された SQL を集める（0 件なら DB へ 1 度も触れていない）。
     *
     * @return list<string>
     */
    private function captureSql(callable $body): array
    {
        $statements = [];
        DB::listen(static function ($query) use (&$statements): void {
            $statements[] = strtolower($query->sql);
        });

        $body();

        return $statements;
    }

    /** @param  list<string>  $statements */
    private function assertReadOnly(array $statements): void
    {
        $this->assertSame([], $statements, 'DB へ触れた: '.implode(' / ', $statements));
        $this->assertSame(0, Taxonomy::query()->count(), 'sync が走って taxonomy が作られた');
        $this->assertSame(0, TaxonomyTerm::query()->count());
        $this->assertSame(0, TaxonomyTermAssignment::query()->count());
    }

    /**
     * 実ファイルを**変更せず**、加工したコピーを参照させる。
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
     */
    private function useRegistry(callable $mutate): void
    {
        /** @var array<string, mixed> $registry */
        $registry = json_decode((string) File::get($this->realPath), true, 32, JSON_THROW_ON_ERROR);

        $this->writeRegistry(
            (string) json_encode($mutate($registry), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    private function writeRegistry(string $contents): void
    {
        if ($this->tempPath === '') {
            $this->tempPath = sys_get_temp_dir().'/agentcms-registry-'.uniqid().'.json';
        }

        File::put($this->tempPath, $contents);

        config(['agentcms.taxonomy_registry_path' => $this->tempPath]);
        app()->forgetInstance(ConsumerRegistry::class);
    }

    private function runCheckExpectingFailure(): string
    {
        $exit = Artisan::call('agentcms:taxonomy-check');
        $output = Artisan::output();

        $this->assertSame(1, $exit, "FAIL していない:\n{$output}");

        return $output;
    }
}
