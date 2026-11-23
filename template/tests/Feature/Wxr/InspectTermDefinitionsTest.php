<?php

declare(strict_types=1);

namespace Tests\Feature\Wxr;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * 「定義」と「参照」を分けて数える（WP 一周 2026-09-03・実物の WXR で踏んだ）。
 *
 * ## 何が起きていたか
 *
 * `inspect` は「分類 category … 延べ 87 件 / 項目 4 種」と出していた。
 * この「4 種」は**記事が指している種類の数**であって、定義の数ではない。
 *
 * 実物の書き出しには `<channel>` 直下の `<wp:category>` が **1 件も無かった**のに、
 * inspect は「項目 4 種」と出す。読んだ人は定義が在ると思う。
 * 気づくのは plan まで進んで `term_definition_missing` が 87 件出たときで、
 * **そこまで来ないと分からない**。だから先に言う。
 *
 * ## 決定の変更（2026-09-04）
 *
 * 「分類の名前は定義にしかない。定義が無ければ取り込めない」は**事実と違った**。
 * 記事側の `<category domain nicename>` には名前が入っている
 * （属性 `nicename`・CDATA が表示名）。無いのは**親子関係だけ**である。
 * いまは「取り込めません」と止めず、平らな項目として作ることを既定にした
 * （`FlatTermDefinitionSource`）。ここでは**それを事前に言う**。
 */
final class InspectTermDefinitionsTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/wxr-inspect-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);

        parent::tearDown();
    }

    public function test_定義の数を参照と分けて出す(): void
    {
        $path = $this->write($this->wxr($this->definition('oshirase', 'お知らせ').$this->item('11', 'oshirase')));

        Artisan::call('agentcms:wxr-inspect', ['file' => $path]);
        $out = Artisan::output();

        $this->assertStringContainsString('定義 1 件', $out);
        $this->assertStringContainsString('参照 1 種', $out);
    }

    /**
     * **定義が 0 件なら、その場で言う。** plan まで行かないと分からない状態にしない。
     *
     * 🔴 「取り込めません」ではない（2026-09-04 変更）。平らな項目として
     * 取り込めるので、**それを言う**。
     */
    public function test_定義が無いときは平らに作ることを警告する(): void
    {
        $path = $this->write($this->wxr($this->item('11', 'oshirase').$this->item('12', 'event')));

        Artisan::call('agentcms:wxr-inspect', ['file' => $path]);
        $out = Artisan::output();

        $this->assertStringContainsString('定義 0 件', $out);
        $this->assertStringNotContainsString('取り込めません', $out, '取り込めるようになったので、この文言は出ない');
        $this->assertStringContainsString('平らな項目', $out);
    }

    public function test_json_にも定義の数を出す(): void
    {
        $path = $this->write($this->wxr($this->definition('oshirase', 'お知らせ').$this->item('11', 'oshirase')));

        Artisan::call('agentcms:wxr-inspect', ['file' => $path, '--json' => true]);

        /** @var array<string, mixed> $data */
        $data = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(['category' => 1], $data['termDefinitions']);
        $this->assertSame(['category' => 1], $data['terms']);
    }

    /** 分類を 1 つも使っていない WXR で、余計なことを言わない */
    public function test_分類が無い_wxr_では何も言わない(): void
    {
        $path = $this->write($this->wxr($this->item('11', null)));

        Artisan::call('agentcms:wxr-inspect', ['file' => $path]);

        $this->assertStringNotContainsString('定義 0 件', Artisan::output());
    }

    // ── ヘルパー ────────────────────────────────────────────

    private function definition(string $slug, string $name): string
    {
        return '<wp:category>'
            .'<wp:term_id>1</wp:term_id>'
            ."<wp:category_nicename><![CDATA[{$slug}]]></wp:category_nicename>"
            .'<wp:category_parent><![CDATA[]]></wp:category_parent>'
            ."<wp:cat_name><![CDATA[{$name}]]></wp:cat_name>"
            .'</wp:category>';
    }

    private function item(string $id, ?string $category): string
    {
        $term = $category === null
            ? ''
            : "<category domain=\"category\" nicename=\"{$category}\"><![CDATA[{$category}]]></category>";

        return "<item><title>記事 {$id}</title><wp:post_id>{$id}</wp:post_id>"
            .'<wp:post_type>post</wp:post_type><wp:status>publish</wp:status>'
            ."<content:encoded><![CDATA[本文]]></content:encoded>{$term}</item>";
    }

    private function wxr(string $body): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"'
            .' xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"'
            .' xmlns:wp="http://wordpress.org/export/1.2/">'
            .'<channel><title>移行元</title><wp:wxr_version>1.2</wp:wxr_version>'
            .$body
            .'</channel></rss>';
    }

    private function write(string $contents): string
    {
        $path = $this->dir.'/a.xml';
        file_put_contents($path, $contents);

        return $path;
    }
}
