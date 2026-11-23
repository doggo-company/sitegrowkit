<?php

declare(strict_types=1);

namespace Tests\Feature\Wxr;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 詰まったときの案内が、**いまの状態を見て言う**（WP 一周 5 周目 2026-09-03）。
 *
 * ## 何が起きていたか
 *
 * 実物の WXR で `--create-taxonomies` を付けて実行すると、こう出た:
 *
 * ```
 * --create-taxonomies を付けましたが、--taxonomy= の対応づけがありません。
 * ...
 * この WXR は取り込めません。上の理由を先に片づけてください。
 *   --create-taxonomies を付けて、もう一度この plan を実行すると作ります。  ← いま付けた
 *   そのあと agentcms:taxonomy-sync を実行してから、また plan を実行してください。
 * ```
 *
 * 末尾が**固定文**で、いまの状態を見ていない。受け皿を作り終えたあとも、
 * `taxonomy-sync` を済ませたあとも、同じ 3 行が出続ける。
 * **案内どおりに打って、同じ所へ戻ってくる。**
 *
 * ## ここで固定すること
 *
 * 1. **もう付いているものを勧めない**（`--create-taxonomies` を付けた回に、
 *    それを勧め直さない）
 * 2. **いま作ったなら、次の 1 歩だけを言う**（`taxonomy-sync` → もう一度 plan）
 * 3. **`--create-taxonomies` では片づかない理由を、片づくかのように言わない**
 *    （`term_definition_missing` は書き出し直しが要る）
 */
final class PlanGuidanceTest extends TestCase
{
    use RefreshDatabase;

    private string $dir = '';

    /** **この試験はプロジェクトのファイルを書き換える。** 必ず元へ戻す */
    private string $schemaBackup = '';

    /** `--create-taxonomies` は AI 向け索引も書く。**戻し忘れるとリポジトリが汚れる** */
    private string $registryBackup = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/wxr-guide-'.bin2hex(random_bytes(6));
        mkdir($this->dir);

        $this->schemaBackup = (string) file_get_contents(base_path('content/schemas/post.json'));
        $this->registryBackup = (string) file_get_contents(
            (string) config('agentcms.taxonomy_registry_path')
        );
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);

        @unlink(base_path('content/taxonomies/wp_category.json'));
        file_put_contents(base_path('content/schemas/post.json'), $this->schemaBackup);
        file_put_contents(
            (string) config('agentcms.taxonomy_registry_path'),
            $this->registryBackup,
        );

        parent::tearDown();
    }

    /**
     * 🔴 **いま付けたものを、もう一度付けろと言わない。**
     *
     * これが「ぐるぐる回る」の芯である。
     */
    public function test_受け皿を作った回は_create_taxonomies_を勧め直さない(): void
    {
        Artisan::call('agentcms:wxr-plan', [
            'file' => $this->wxrWithCategory(),
            '--type' => ['post:post'],
            '--taxonomy' => ['category:wp_category'],
            '--create-taxonomies' => true,
        ]);

        $out = Artisan::output();

        $this->assertStringContainsString('受け皿を作りました', $out);
        $this->assertStringNotContainsString(
            '--create-taxonomies を付けて、もう一度この plan を実行すると作ります',
            $out,
            'いま付けたフラグを、もう一度付けろと言っている',
        );
        $this->assertStringContainsString('agentcms:taxonomy-sync', $out, '次の 1 歩が出ていない');
    }

    /**
     * `--create-taxonomies` を付けたのに `--taxonomy=` が無いとき。
     *
     * 足りないのは対応づけであって、フラグではない。
     */
    public function test_対応づけが無いときは_taxonomy_の付け方だけを言う(): void
    {
        Artisan::call('agentcms:wxr-plan', [
            'file' => $this->wxrWithCategory(),
            '--create-taxonomies' => true,
        ]);

        $out = Artisan::output();

        $this->assertStringContainsString('--taxonomy=', $out);
        $this->assertStringNotContainsString(
            '--create-taxonomies を付けて、もう一度この plan を実行すると作ります',
            $out,
            'いま付けたフラグを、もう一度付けろと言っている',
        );
    }

    /**
     * 🔴 **分類に定義が 1 件も無いのは、もう「取り込めない」ではない**（2026-09-04 決定）。
     *
     * 実物の WXR（クライアント案件・87 記事）は `<wp:category>` を 1 件も持たない。
     * 以前はここで止めて書き出し直しを求めていたが、**サイトが既に閉じている案件・
     * 前の制作会社が鍵を持っている案件では、それができない。**
     * だから既定を変えた: 記事側の名前から**平らな項目**を作り、
     * 親子が分からないことだけを事前に言う。
     */
    public function test_定義が無いときは既定で平らな項目を作る(): void
    {
        $this->artisan('agentcms:taxonomy-sync')->assertExitCode(0);
        $this->publishNews();

        Artisan::call('agentcms:wxr-plan', [
            'file' => $this->wxrWithoutTermDefinitionUsingNewsCategory(),
        ]);

        $out = Artisan::output();

        $this->assertStringContainsString('取り込めます', $out);
        $this->assertStringContainsString('flat_taxonomy_term_created', $out);
        $this->assertStringContainsString('親子関係が分かりません', $out);
        $this->assertStringNotContainsString('term_definition_missing', $out);
    }

    /**
     * `--strict-term-definitions` を付ければ、従来どおり止まる。
     *
     * 平らに作ることを望まない人向けの逃げ道である。
     */
    public function test_strict_term_definitions_を付ければ従来どおり止める(): void
    {
        $this->artisan('agentcms:taxonomy-sync')->assertExitCode(0);

        Artisan::call('agentcms:wxr-plan', [
            'file' => $this->wxrWithoutTermDefinitionUsingNewsCategory(),
            '--strict-term-definitions' => true,
        ]);

        $out = Artisan::output();

        $this->assertStringContainsString('term_definition_missing', $out);
        $this->assertStringNotContainsString('flat_taxonomy_term_created', $out);
    }

    /**
     * 🔴 **`--create-taxonomies` で片づかないものを、片づくかのように言わない。**
     *
     * ここで止まるのは「同じ分類に他の定義はあるのに、この割り当てだけ対応が無い」
     * 場合である（分類そのものに定義が 1 件も無い場合は、平らに作られて通る —
     * 上の `test_定義が無いときは既定で平らな項目を作る`）。
     * 書き出しが壊れている・途中で切れているなどの疑いがあるので、
     * 要るのは WordPress からの書き出し直しである。
     */
    public function test_一部だけ定義が無いときは書き出し直しを案内する(): void
    {
        Artisan::call('agentcms:wxr-plan', [
            'file' => $this->wxrWithPartialTermDefinition(),
            '--type' => ['post:post'],
            '--taxonomy' => ['category:wp_category'],
        ]);

        $out = Artisan::output();

        $this->assertStringContainsString('term_definition_missing', $out);
        $this->assertStringContainsString('すべてのコンテンツ', $out, '書き出し直しの案内が出ていない');
        $this->assertStringNotContainsString(
            '--create-taxonomies を付けて、もう一度この plan を実行すると作ります',
            $out,
            'これでは片づかないものを、片づくかのように言っている',
        );
    }

    /**
     * 🔴 **「… ほか N 件」の数が合っていなかった。**
     *
     * 実物で `skipped_attachment 105 件` の下に 5 件並べて「… ほか 55 件」。
     * 5 + 55 = 60 で、**45 件がどこにも出てこない**。
     * 読んだ人は「105 件のうち 60 件しか説明されていない」と受け取る。
     *
     * （原因: 並べるのは 5 件だが、`omitted` は 50 件並べた前提で数えられている）
     */
    public function test_ほか何件の数が_並べた件数と足して総数になる(): void
    {
        Artisan::call('agentcms:wxr-plan', ['file' => $this->wxrWithAttachments(8)]);

        $out = Artisan::output();

        $this->assertMatchesRegularExpression('/skipped_attachment\s+8 件/', $out);
        $this->assertSame(
            5,
            substr_count($out, '      - attachment / '),
            '並べる件数が変わっている（この試験の前提）',
        );
        $this->assertStringContainsString('… ほか 3 件', $out, '出していない 3 件が数から消えている');
    }

    // ── ヘルパー ────────────────────────────────────────────

    /**
     * `news` を取り込み先として使える（公開できる）状態にする。
     *
     * 同梱の `news` は既定で非公開なので、これを呼ばないと
     * `type_not_public` で止まる（ここで見たいのはそこではない）。
     */
    private function publishNews(): void
    {
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

    /** 分類の定義も参照もある WXR（受け皿だけが足りない） */
    private function wxrWithCategory(): string
    {
        return $this->write(
            '<wp:category><wp:term_id>1</wp:term_id>'
            .'<wp:category_nicename><![CDATA[oshirase]]></wp:category_nicename>'
            .'<wp:category_parent><![CDATA[]]></wp:category_parent>'
            .'<wp:cat_name><![CDATA[お知らせ]]></wp:cat_name></wp:category>'
            .'<item><title>記事</title><wp:post_id>11</wp:post_id>'
            .'<wp:post_type>post</wp:post_type><wp:status>publish</wp:status>'
            .'<content:encoded><![CDATA[本文]]></content:encoded>'
            .'<category domain="category" nicename="oshirase"><![CDATA[お知らせ]]></category></item>',
        );
    }

    /**
     * 参照だけあって、分類の定義が **1 件も無い** WXR（実物と同じ形）。
     *
     * `news` / `news_category` はプロジェクトに元から在る受け皿なので、
     * `--taxonomy=` の対応づけも `--create-taxonomies` も要らない
     * （`taxonomy-sync` だけ先に実行すればよい）。
     */
    private function wxrWithoutTermDefinitionUsingNewsCategory(): string
    {
        return $this->write(
            '<item><title>記事</title><wp:post_id>11</wp:post_id>'
            .'<wp:post_type>news</wp:post_type><wp:status>publish</wp:status>'
            .'<content:encoded><![CDATA[本文]]></content:encoded>'
            .'<category domain="news_category" nicename="oshirase"><![CDATA[お知らせ]]></category></item>',
        );
    }

    /**
     * 同じ分類（`category`）に**他の定義はある**のに、この記事が指す
     * `oshirase` だけ定義が無い WXR。分類そのものに定義が無いのとは違う形である。
     */
    private function wxrWithPartialTermDefinition(): string
    {
        return $this->write(
            '<wp:category><wp:term_id>2</wp:term_id>'
            .'<wp:category_nicename><![CDATA[event]]></wp:category_nicename>'
            .'<wp:category_parent><![CDATA[]]></wp:category_parent>'
            .'<wp:cat_name><![CDATA[イベント]]></wp:cat_name></wp:category>'
            .'<item><title>記事</title><wp:post_id>11</wp:post_id>'
            .'<wp:post_type>post</wp:post_type><wp:status>publish</wp:status>'
            .'<content:encoded><![CDATA[本文]]></content:encoded>'
            .'<category domain="category" nicename="oshirase"><![CDATA[お知らせ]]></category></item>',
        );
    }

    /** 添付だけ n 件（`skipped_attachment` を n 件出す） */
    private function wxrWithAttachments(int $count): string
    {
        $items = '';

        for ($i = 1; $i <= $count; $i++) {
            $items .= '<item><title>画像 '.$i.'</title><wp:post_id>'.(100 + $i).'</wp:post_id>'
                .'<wp:post_type>attachment</wp:post_type><wp:status>inherit</wp:status>'
                .'<content:encoded><![CDATA[]]></content:encoded></item>';
        }

        return $this->write($items);
    }

    private function write(string $body): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"'
            .' xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"'
            .' xmlns:wp="http://wordpress.org/export/1.2/">'
            .'<channel><title>移行元</title><wp:wxr_version>1.2</wp:wxr_version>'
            .$body
            .'</channel></rss>';

        $path = $this->dir.'/'.bin2hex(random_bytes(4)).'.xml';
        file_put_contents($path, $xml);

        return $path;
    }
}
