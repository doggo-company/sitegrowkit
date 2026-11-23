<?php

declare(strict_types=1);

namespace Tests\Feature\Wxr;

use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Wxr\Plan\ImportOptions;
use AgentCms\Core\Wxr\Plan\PlanBuilder;
use AgentCms\Core\Wxr\Plan\WxrPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 必須の分類が付かない記事を、入れる前に言う（Phase 9.14 / STAGE 27D-5a・SPEC §F・§C.3）。
 *
 * ## なぜ plan で言うのか
 *
 * `ContentTaxonomyAssignments` は、作成時に **required 宣言の分類が渡って来なければ
 * validation error** を返す。型が分類を必須にしていて WXR の記事にその割り当てが無いと、
 * **十年分を流し始めてから 1 件目で落ちる**。
 *
 * これは 27D-3b で塞いだ「必須の欄」と**同じ形の穴**である。
 * 欄は `ItemFields` が、分類はここが見る。
 */
final class PlanRequiredTaxonomyTest extends TestCase
{
    use RefreshDatabase;

    private string $dir = '';

    private string $schemaPath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/wxr-required-taxonomy-'.bin2hex(random_bytes(6));
        mkdir($this->dir);

        $this->artisan('agentcms:taxonomy-sync')->assertExitCode(0);

        $this->useSchemaWithRequiredTaxonomy();

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

        array_map('unlink', glob($this->schemaPath.'/*.json') ?: []);
        array_map('unlink', glob($this->schemaPath.'/overrides/*.json') ?: []);
        @rmdir($this->schemaPath.'/overrides');
        @rmdir($this->schemaPath);

        parent::tearDown();
    }

    public function test_必須の分類に割り当てが無ければ止める(): void
    {
        $plan = $this->plan($this->entry('11', []));

        $this->assertFalse($plan->isUsable());
        $this->assertNull($plan->fingerprint());

        $found = $plan->findings()['required_taxonomy_missing'];

        $this->assertSame(1, $found['total']);
        $this->assertSame(
            [['external_id' => '11', 'source_type' => 'news']],
            $found['shown'],
        );
    }

    public function test_1件目で止めず全部数える(): void
    {
        // §A.3 — 直しては流し、を繰り返させない
        $body = '';

        for ($i = 1; $i <= 3; $i++) {
            $body .= $this->entry((string) $i, []);
        }

        $this->assertSame(3, $this->plan($body)->findings()['required_taxonomy_missing']['total']);
    }

    public function test_割り当てがあれば止めない(): void
    {
        $plan = $this->plan(
            $this->term('1', 'news_category', 'oshirase')
            .$this->entry('11', ['news_category' => 'oshirase']),
        );

        $this->assertArrayNotHasKey('required_taxonomy_missing', $plan->findings());
        $this->assertTrue($plan->isUsable(), json_encode($plan->findings(), JSON_UNESCAPED_UNICODE));
    }

    public function test_必須でない分類が無くても止めない(): void
    {
        /*
         * 同梱の `news` は `news_category` を**必須にしていない**。
         * 必須でない分類は、付いていなくても `CreateContent` は通る。
         * 数えると**直しようのない理由**で十年分が止まる。
         */
        $this->useStockSchema();

        $plan = $this->plan($this->entry('11', []));

        $this->assertArrayNotHasKey('required_taxonomy_missing', $plan->findings());
        $this->assertTrue($plan->isUsable(), json_encode($plan->findings(), JSON_UNESCAPED_UNICODE));
    }

    public function test_読み替えた後の受け皿で見る(): void
    {
        /*
         * `--taxonomy=cat:news_required` を付けたら、WXR 側の `cat` は
         * `news_required` として入る。**読み替える前の名前で見ると**、
         * 割り当てが在るのに「必須の分類が無い」になる。
         */
        $plan = $this->plan(
            $this->term('1', 'cat', 'oshirase')
            .$this->entry('11', ['cat' => 'oshirase']),
            ['cat:news_category'],
        );

        $this->assertArrayNotHasKey('required_taxonomy_missing', $plan->findings());
        $this->assertTrue($plan->isUsable(), json_encode($plan->findings(), JSON_UNESCAPED_UNICODE));
    }

    public function test_入らない記事では数えない(): void
    {
        /*
         * ページは入れないと決まっている（§A.2）。入らないものに
         * 「必須の分類が無い」と言っても直す先が無い。
         */
        $plan = $this->plan(
            '<item><title>固定</title><wp:post_id>11</wp:post_id>'
            .'<wp:post_type>page</wp:post_type><wp:status>publish</wp:status>'
            .'<wp:post_name>koteipage</wp:post_name>'
            .'<content:encoded><![CDATA[本文]]></content:encoded></item>',
        );

        $this->assertArrayNotHasKey('required_taxonomy_missing', $plan->findings());
    }

    // ── ヘルパー ────────────────────────────────────────────

    /**
     * @param  list<string>  $taxonomies  `--taxonomy` の書き方そのまま
     */
    private function plan(string $body, array $taxonomies = []): WxrPlan
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

        return app(PlanBuilder::class)->build($path, ImportOptions::fromCli([], $taxonomies, false));
    }

    /**
     * @param  array<string, string>  $assignments  domain => nicename
     */
    private function entry(string $id, array $assignments): string
    {
        $categories = '';

        foreach ($assignments as $domain => $nicename) {
            $categories .= "<category domain=\"{$domain}\" nicename=\"{$nicename}\">"
                ."<![CDATA[{$nicename}]]></category>";
        }

        return "<item><title>記事 {$id}</title><wp:post_id>{$id}</wp:post_id>"
            .'<wp:post_type>news</wp:post_type><wp:status>publish</wp:status>'
            ."<wp:post_name>kiji-{$id}</wp:post_name>"
            ."<content:encoded><![CDATA[本文 {$id}]]></content:encoded>"
            .$categories
            .'</item>';
    }

    private function term(string $termId, string $taxonomy, string $slug): string
    {
        return '<wp:term>'
            ."<wp:term_id>{$termId}</wp:term_id>"
            ."<wp:term_taxonomy>{$taxonomy}</wp:term_taxonomy>"
            ."<wp:term_slug>{$slug}</wp:term_slug>"
            ."<wp:term_name><![CDATA[{$slug}]]></wp:term_name>"
            .'<wp:term_parent></wp:term_parent>'
            .'</wp:term>';
    }

    /** 同梱 schema を複製し、`news` へ**必須の分類**を足した一時 schema へ差し替える */
    private function useSchemaWithRequiredTaxonomy(): void
    {
        $source = base_path('content/schemas');
        $this->schemaPath = sys_get_temp_dir().'/agentcms-wxr-schemas-'.bin2hex(random_bytes(6));

        mkdir($this->schemaPath.'/overrides', 0777, true);

        foreach (glob($source.'/*.json') ?: [] as $file) {
            /** @var array<string, mixed> $data */
            $data = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);

            if (($data['id'] ?? '') === 'news') {
                /*
                 * **同梱の分類をそのまま必須にする。**
                 * 新しい分類を足すと、その定義（`content/taxonomies`）と DB の行まで
                 * 用意することになり、**見たいものと関係の無い前提**がテストに入る。
                 */
                $data['taxonomies'] = [['key' => 'news_category', 'multiple' => true, 'required' => true]];
            }

            file_put_contents(
                $this->schemaPath.'/'.basename($file),
                json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            );
        }

        foreach (glob($source.'/overrides/*.json') ?: [] as $file) {
            copy($file, $this->schemaPath.'/overrides/'.basename($file));
        }

        config(['agentcms.schema_path' => $this->schemaPath]);
        app()->forgetInstance(SchemaRegistry::class);
    }

    /** 同梱のまま（`news_category` は必須ではない）へ戻す */
    private function useStockSchema(): void
    {
        config(['agentcms.schema_path' => base_path('content/schemas')]);
        app()->forgetInstance(SchemaRegistry::class);
    }
}
