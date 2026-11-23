<?php

declare(strict_types=1);

namespace Tests\Feature\Wxr;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * 受け皿を plan に作らせる（WP 一周 2026-09-03 / 実物で詰まった所）。
 *
 * ## なぜ要るのか
 *
 * 分類つきの WXR を取り込むには、いま**ファイルを 2 つ手で書く**必要がある。
 *
 *   1. `content/taxonomies/<キー>.json`（＋ `agentcms:taxonomy-sync`）
 *   2. `content/schemas/<入れ先の型>.json` に `taxonomies` を宣言
 *
 * どちらも管理画面ではできない。**WordPress から来た人がやることではない。**
 * 移行の実質的な壁がここになっていた。
 *
 * ## plan は読むだけ、の約束は守る
 *
 * だから**このフラグを付けたときだけ**書く。付けなければ 1 バイトも書かない。
 * 書いたファイルは必ず一覧で出す。**既にあるものは上書きしない**（手で直した
 * ものを消さない）。
 */
final class CreateTaxonomiesTest extends TestCase
{
    use RefreshDatabase;

    private string $dir = '';

    /** **この試験はプロジェクトのファイルを書き換える。** 必ず元へ戻す */
    private string $schemaBackup = '';

    /** AI 向け索引も書き換える。**戻し忘れるとリポジトリが汚れる** */
    private string $registryBackup = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/wxr-create-'.bin2hex(random_bytes(6));
        mkdir($this->dir);

        $this->schemaBackup = (string) file_get_contents(base_path('content/schemas/post.json'));
        $this->registryBackup = (string) file_get_contents($this->registryPath());
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);

        // 作ったものを残さない（次の試験にも、リポジトリにも）
        foreach (['wp_category', 'wp_tag', 'wp_genre'] as $key) {
            @unlink(base_path('content/taxonomies/'.$key.'.json'));
        }
        file_put_contents(base_path('content/schemas/post.json'), $this->schemaBackup);
        file_put_contents($this->registryPath(), $this->registryBackup);

        parent::tearDown();
    }

    /** **付けなければ 1 バイトも書かない**（plan は読むだけ） */
    public function test_フラグが無ければ何も書かない(): void
    {
        Artisan::call('agentcms:wxr-plan', [
            'file' => $this->wxrFile(),
            '--type' => ['post:post'],
            '--taxonomy' => ['category:wp_category'],
        ]);

        $this->assertFileDoesNotExist(base_path('content/taxonomies/wp_category.json'));
    }

    public function test_分類の定義ファイルを作る(): void
    {
        Artisan::call('agentcms:wxr-plan', [
            'file' => $this->wxrFile(),
            '--type' => ['post:post'],
            '--taxonomy' => ['category:wp_category'],
            '--create-taxonomies' => true,
        ]);

        $path = base_path('content/taxonomies/wp_category.json');

        $this->assertFileExists($path);

        /** @var array<string, mixed> $json */
        $json = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('wp_category', $json['key']);
        $this->assertSame('code', $json['managed_by']);
    }

    /**
     * 🔴 **label は画面に出る言葉**（WP 一周 2026-09-06 / F-18）。
     *
     * key をそのまま書いていたため、公開記事の下に
     * **「wp_category お知らせ」**と出ていた。買った人が最初に見る所である。
     */
    public function test_分類の名前を画面に出せる言葉にする(): void
    {
        Artisan::call('agentcms:wxr-plan', [
            'file' => $this->wxrFile(),
            '--type' => ['post:post'],
            '--taxonomy' => ['category:wp_category'],
            '--create-taxonomies' => true,
        ]);

        $json = $this->definitionOf('wp_category');

        $this->assertSame('カテゴリー', $json['label']);
        $this->assertNotSame('wp_category', $json['label'], 'key をそのまま label にしている');
        // WordPress の category は親子を持てる
        $this->assertTrue($json['hierarchical']);
    }

    /** 🔴 **WordPress のタグは平ら。** 親子ありで作ると管理画面に親の欄が出る */
    public function test_タグは平らな分類として作る(): void
    {
        Artisan::call('agentcms:wxr-plan', [
            'file' => $this->wxrFile(),
            '--type' => ['post:post'],
            '--taxonomy' => ['post_tag:wp_tag'],
            '--create-taxonomies' => true,
        ]);

        $json = $this->definitionOf('wp_tag');

        $this->assertSame('タグ', $json['label']);
        $this->assertFalse($json['hierarchical'], 'タグに親子を持たせている');
    }

    /**
     * 知らない分類は、**その人が自分の WordPress で呼んでいる名前**を使う
     * （`--taxonomy=` の左）。こちら側の key より読める言葉で、
     * こちらが勝手に作った言葉でもない。親子は持てる方へ倒す。
     */
    public function test_知らない分類はwxr側の呼び名を使う(): void
    {
        Artisan::call('agentcms:wxr-plan', [
            'file' => $this->wxrFile(),
            '--type' => ['post:post'],
            '--taxonomy' => ['genre:wp_genre'],
            '--create-taxonomies' => true,
        ]);

        $json = $this->definitionOf('wp_genre');

        $this->assertSame('genre', $json['label']);
        $this->assertTrue($json['hierarchical']);
    }

    /** 索引も定義に従う（片方だけ直すと `agentcms verify` が食い違いで落ちる） */
    public function test_索引の親子も定義に合わせる(): void
    {
        Artisan::call('agentcms:wxr-plan', [
            'file' => $this->wxrFile(),
            '--type' => ['post:post'],
            '--taxonomy' => ['post_tag:wp_tag'],
            '--create-taxonomies' => true,
        ]);

        $this->assertFalse($this->registryEntry('wp_tag')['hierarchical']);
    }

    /** **書いたものは必ず一覧で出す**（黙って足さない） */
    public function test_書いたファイルを一覧で出す(): void
    {
        Artisan::call('agentcms:wxr-plan', [
            'file' => $this->wxrFile(),
            '--type' => ['post:post'],
            '--taxonomy' => ['category:wp_category'],
            '--create-taxonomies' => true,
        ]);

        $out = Artisan::output();

        $this->assertStringContainsString('content/taxonomies/wp_category.json', $out);
        $this->assertStringContainsString('content/schemas/post.json', $out);
    }

    /** 入れ先の型にも宣言を足す（ここも管理画面ではできない） */
    public function test_入れ先の型に分類を宣言する(): void
    {
        Artisan::call('agentcms:wxr-plan', [
            'file' => $this->wxrFile(),
            '--type' => ['post:post'],
            '--taxonomy' => ['category:wp_category'],
            '--create-taxonomies' => true,
        ]);

        /** @var array<string, mixed> $schema */
        $schema = json_decode(
            (string) file_get_contents(base_path('content/schemas/post.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertContains(
            'wp_category',
            array_column($schema['taxonomies'] ?? [], 'key'),
            '入れ先の型に宣言されていない',
        );
    }

    /** **手で直したものを消さない** */
    public function test_既にあるものは上書きしない(): void
    {
        $path = base_path('content/taxonomies/wp_category.json');
        file_put_contents($path, '{"key":"wp_category","label":"手で書いたもの","hierarchical":false,"managed_by":"code"}');

        Artisan::call('agentcms:wxr-plan', [
            'file' => $this->wxrFile(),
            '--type' => ['post:post'],
            '--taxonomy' => ['category:wp_category'],
            '--create-taxonomies' => true,
        ]);

        $this->assertStringContainsString('手で書いたもの', (string) file_get_contents($path));
    }

    /**
     * 🔴 **書いた正から AI 向けの索引を作り直す**（WP 一周 2026-09-06）。
     *
     * ここが抜けていたため、`docs/MIGRATE_FROM_WORDPRESS.md` の手順どおりに
     * 進めた人は、最後の `agentcms verify` の分類ゲート
     * （`agentcms:taxonomy-check`）で必ず止まっていた。
     * 索引は `content/schemas/*.json` の宣言から導かれるもので、
     * その宣言を書いているのは `--create-taxonomies` だけである
     * （`agentcms:taxonomy-sync` は DB へ同期するもので、`content/schemas/` を読まない）。
     */
    public function test_索引を書いた正に合わせる(): void
    {
        Artisan::call('agentcms:wxr-plan', [
            'file' => $this->wxrFile(),
            '--type' => ['post:post'],
            '--taxonomy' => ['category:wp_category'],
            '--create-taxonomies' => true,
        ]);

        /** @var array{taxonomies: list<array<string, mixed>>} $registry */
        $registry = json_decode(
            (string) file_get_contents($this->registryPath()),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $entry = null;

        foreach ($registry['taxonomies'] as $candidate) {
            if (($candidate['key'] ?? null) === 'wp_category') {
                $entry = $candidate;
            }
        }

        $this->assertNotNull($entry, '索引に wp_category の項目が無い');
        $this->assertSame('code', $entry['managed_by']);
        $this->assertTrue($entry['hierarchical']);
        $this->assertSame('content/taxonomies/wp_category.json', $entry['definition']);
        $this->assertSame(['post'], $entry['consumers'], 'consumers が入れ先の型と一致しない');
    }

    /** 書いたものは索引も含めて一覧で出す（黙って足さない） */
    public function test_索引を書いたことも一覧で出す(): void
    {
        Artisan::call('agentcms:wxr-plan', [
            'file' => $this->wxrFile(),
            '--type' => ['post:post'],
            '--taxonomy' => ['category:wp_category'],
            '--create-taxonomies' => true,
        ]);

        $this->assertStringContainsString(
            '.agentcms/registry/taxonomies.json',
            Artisan::output(),
        );
    }

    /** **フラグが無ければ索引も 1 バイトも書かない**（plan は読むだけ） */
    public function test_フラグが無ければ索引も書かない(): void
    {
        Artisan::call('agentcms:wxr-plan', [
            'file' => $this->wxrFile(),
            '--type' => ['post:post'],
            '--taxonomy' => ['category:wp_category'],
        ]);

        $this->assertSame(
            $this->registryBackup,
            (string) file_get_contents($this->registryPath()),
        );
    }

    /** **この道具が起こしていない乖離は直さない**（他の項目に手を出さない） */
    public function test_関係の無い項目には触らない(): void
    {
        /** @var array{taxonomies: list<array<string, mixed>>} $registry */
        $registry = json_decode($this->registryBackup, true, 512, JSON_THROW_ON_ERROR);
        $registry['taxonomies'][] = [
            'key' => 'sonomama',
            'managed_by' => 'code',
            'hierarchical' => false,
            'definition' => 'content/taxonomies/sonomama.json',
            'consumers' => ['dokoka'],
        ];
        file_put_contents(
            $this->registryPath(),
            json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        Artisan::call('agentcms:wxr-plan', [
            'file' => $this->wxrFile(),
            '--type' => ['post:post'],
            '--taxonomy' => ['category:wp_category'],
            '--create-taxonomies' => true,
        ]);

        /** @var array{taxonomies: list<array<string, mixed>>} $after */
        $after = json_decode(
            (string) file_get_contents($this->registryPath()),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $untouched = null;

        foreach ($after['taxonomies'] as $candidate) {
            if (($candidate['key'] ?? null) === 'sonomama') {
                $untouched = $candidate;
            }
        }

        $this->assertNotNull($untouched, '関係の無い項目が消えている');
        $this->assertSame(['dokoka'], $untouched['consumers'], '関係の無い項目が書き換えられている');
    }

    /** @return array<string, mixed> */
    private function definitionOf(string $key): array
    {
        $path = base_path('content/taxonomies/'.$key.'.json');

        $this->assertFileExists($path);

        /** @var array<string, mixed> $json */
        $json = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $json;
    }

    /** @return array<string, mixed> */
    private function registryEntry(string $key): array
    {
        /** @var array{taxonomies: list<array<string, mixed>>} $registry */
        $registry = json_decode(
            (string) file_get_contents($this->registryPath()),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        foreach ($registry['taxonomies'] as $candidate) {
            if (($candidate['key'] ?? null) === $key) {
                return $candidate;
            }
        }

        $this->fail("索引に {$key} の項目が無い");
    }

    private function registryPath(): string
    {
        return (string) config('agentcms.taxonomy_registry_path');
    }

    private function wxrFile(): string
    {
        $body = '<wp:category><wp:term_id>1</wp:term_id>'
            .'<wp:category_nicename><![CDATA[oshirase]]></wp:category_nicename>'
            .'<wp:category_parent><![CDATA[]]></wp:category_parent>'
            .'<wp:cat_name><![CDATA[お知らせ]]></wp:cat_name></wp:category>'
            .'<item><title>記事</title><wp:post_id>11</wp:post_id>'
            .'<wp:post_type>post</wp:post_type><wp:status>publish</wp:status>'
            .'<content:encoded><![CDATA[本文]]></content:encoded>'
            .'<category domain="category" nicename="oshirase"><![CDATA[お知らせ]]></category></item>';

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"'
            .' xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"'
            .' xmlns:wp="http://wordpress.org/export/1.2/">'
            .'<channel><title>移行元</title><wp:wxr_version>1.2</wp:wxr_version>'
            .$body
            .'</channel></rss>';

        $path = $this->dir.'/a.xml';
        file_put_contents($path, $xml);

        return $path;
    }
}
