<?php

declare(strict_types=1);

namespace Tests\Feature\Wxr;

use AgentCms\Core\Wxr\Plan\ImportOptions;
use AgentCms\Core\Wxr\Plan\ItemFields;
use AgentCms\Core\Wxr\Plan\PlanBuilder;
use AgentCms\Core\Wxr\WxrItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/**
 * 実物の再現（沼津の書き出し・2026-09-05・PR #46 より前からあるバグ）。
 *
 * `<wp:post_name>` は URL エンコード済みで、日本語の見出しは1文字あたり9文字
 * （UTF-8 3byte * "%XX" 3文字）に膨らむ。実物の87記事は**全件これ**で、
 * 最初の1件で apply が止まる。理由は 2 つある——
 *
 * 1. `slug` 列の varchar(120) を超える（実物は最大212文字）
 * 2. `%` は slug の形式（`/^[a-z0-9]+(?:-[a-z0-9]+)*$/`）にそもそも合わない
 *    （削って120文字に収めても `%` が残るかぎり通らない）
 *
 * `ItemFields::slugFor()` はどちらも見た上で、使えないときは見出しから作り直し、
 * それも使えないなら `wp:post_id` に倒す（`TermGraph::fold()` が分類項目でやって
 * いるのと同じ判断）。
 */
final class ApplyWxrLongSlugTest extends TestCase
{
    use RefreshDatabase;

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/wxr-longslug-'.bin2hex(random_bytes(6));
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

    public function test_120文字を超える日本語見出しの記事も入る(): void
    {
        // 15文字の日本語見出し（「日」を15回）→ %XX 3連 * 15 = 135文字。実物と同じ形
        $slug = str_repeat('%e6%97%a5', 15);
        $this->assertSame(135, strlen($slug));

        $path = $this->write('a.xml', $this->wxr('<item>'.$this->entry('11', $slug).'</item>'));

        $this->apply($path)->assertExitCode(0);

        $this->assertSame(1, DB::table('news')->count());

        $stored = (string) DB::table('news')->value('slug');
        $this->assertLessThanOrEqual(120, strlen($stored));
        $this->assertMatchesRegularExpression('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $stored);
        // 見出しが日本語だけなので Str::slug() も空になり、post_id へ倒れる
        $this->assertSame('11', $stored);
    }

    public function test_有効な綴りはそのまま使う(): void
    {
        // 既に英字の綴りを持つ記事は、作り直さずそのまま入れる
        $path = $this->write('a.xml', $this->wxr('<item>'.$this->entry('11', 'hello-world').'</item>'));

        $this->apply($path)->assertExitCode(0);

        $this->assertSame('hello-world', DB::table('news')->value('slug'));
    }

    public function test_長すぎる見出しの記事が複数あってもぶつからない(): void
    {
        // 別の記事（別の post_id）が、同じ長さで壊れた綴りを持っていても
        // 綴りが post_id 由来なので絶対にかぶらない（乱数もハッシュも無しに衝突しない）
        $slugA = str_repeat('%e6%97%a5', 15);
        $slugB = str_repeat('%e6%9c%ac', 15);

        $path = $this->write('a.xml', $this->wxr(
            '<item>'.$this->entry('11', $slugA).'</item>'
            .'<item>'.$this->entry('12', $slugB).'</item>',
        ));

        $this->apply($path)->assertExitCode(0);

        $this->assertSame(2, DB::table('news')->count());

        $slugs = DB::table('news')->pluck('slug')->all();
        $this->assertCount(2, array_unique($slugs));
        $this->assertEqualsCanonicalizing(['11', '12'], $slugs);
    }

    public function test_同じ書き出しなら何度計算しても同じ綴りになる(): void
    {
        // apply を通さない、`ItemFields::of()` だけの純粋な確認（DB もランダム性も無い）
        $item = $this->makeItem('99', '見出しは日本語だけです');

        $first = ItemFields::of($item)['slug'];
        $second = ItemFields::of($item)['slug'];

        $this->assertSame($first, $second);
        $this->assertSame('99', $first);
    }

    private function apply(string $path): PendingCommand
    {
        $hash = (string) app(PlanBuilder::class)
            ->build($path, ImportOptions::fromCli([], [], false))
            ->fingerprint();

        /** @var PendingCommand */
        return $this->artisan('agentcms:wxr-apply', [
            'file' => $path,
            '--i-have-a-backup' => true,
            '--plan-hash' => $hash,
        ]);
    }

    private function makeItem(string $postId, string $title): WxrItem
    {
        return new WxrItem(
            postId: $postId,
            postType: 'news',
            status: 'publish',
            title: $title,
            slug: '',
            content: '本文',
            excerpt: '',
            publishedAt: null,
            parentId: null,
            attachmentUrl: null,
        );
    }

    private function entry(string $id, string $slug): string
    {
        return "<title>記事 {$id}</title><wp:post_id>{$id}</wp:post_id>"
            .'<wp:post_type>news</wp:post_type><wp:status>publish</wp:status>'
            ."<wp:post_name>{$slug}</wp:post_name>"
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
