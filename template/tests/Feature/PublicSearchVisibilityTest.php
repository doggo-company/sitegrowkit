<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Schema\SchemaRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 探せない型で検索窓を出さない（B-14）。
 *
 * ## 押せるのに何も起きない、を作らない
 *
 * `search` の宣言を失った型では `PublicSearch::apply()` が何もしないので、
 * 検索語を入れても**一覧がそのまま出る**。窓は在って、押せて、**結果が変わらない**。
 * 利用者から見れば「壊れている」のではなく「**自分の入れ方が悪い**」に見える。
 *
 * ▷ 挙動そのもの（絞られない）は `PublicSearchTest` が固定している。
 *   ここで決めるのは**見せ方**である。
 */
final class PublicSearchVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private string $schemaPath = '';

    protected function tearDown(): void
    {
        if ($this->schemaPath !== '' && is_dir($this->schemaPath)) {
            foreach (glob($this->schemaPath.'/overrides/*.json') ?: [] as $file) {
                @unlink($file);
            }
            foreach (glob($this->schemaPath.'/*.json') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->schemaPath.'/overrides');
            @rmdir($this->schemaPath);
        }

        parent::tearDown();
    }

    private function news(string $title): void
    {
        DB::table('news')->insert([
            'title' => $title, 'slug' => 's-'.uniqid(), 'body' => '<p>本文</p>',
            'status' => 'published', 'published_at' => now()->subDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * `search` の宣言を落とした schema へ差し替える。
     *
     * **同梱 override は前提にしない**（`26444c1`）。一時ディレクトリを作って
     * そこを見せる。
     */
    private function dropSearchDeclaration(string $type): void
    {
        $source = base_path('content/schemas');
        $this->schemaPath = sys_get_temp_dir().'/agentcms-search-visibility-'.uniqid();

        mkdir($this->schemaPath.'/overrides', 0777, true);

        foreach (glob($source.'/*.json') ?: [] as $file) {
            /** @var array<string, mixed> $data */
            $data = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);

            if (($data['id'] ?? '') === $type) {
                unset($data['search'], $data['admin']['search']);
            }

            file_put_contents(
                $this->schemaPath.'/'.basename($file),
                json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            );
        }

        config(['agentcms.schema_path' => $this->schemaPath]);
        app()->forgetInstance(SchemaRegistry::class);
    }

    /** **宣言が在るあいだは、いままでどおり出る** */
    public function test_探せる型では検索窓が出る(): void
    {
        $this->news('記事');

        $this->get('/news')->assertOk()->assertSee('name="q"', false);
        $this->get('/works')->assertOk()->assertSee('name="q"', false);
    }

    /**
     * **探せない型では窓を出さない。**
     *
     * 出しても結果が変わらないので、**窓が在ること自体が嘘**になる。
     */
    public function test_探せない型では検索窓を出さない(): void
    {
        $this->dropSearchDeclaration('news');
        $this->news('記事');

        $html = (string) $this->get('/news')->assertOk()->getContent();

        $this->assertStringNotContainsString('name="q"', $html);

        // **一覧そのものは出る**（窓を消しただけで、ページを壊さない）
        $this->assertStringContainsString('記事', $html);
    }

    /**
     * **消えるのはその型だけ。**
     *
     * 型ごとに宣言が違うので、1 つ落としたら全部から消える、では困る。
     */
    public function test_別の型の検索窓は消えない(): void
    {
        $this->dropSearchDeclaration('news');

        DB::table('works')->insert([
            'title' => '実績', 'slug' => 'w-'.uniqid(), 'excerpt' => '概要',
            'body' => '<p>本文</p>', 'category' => 'web',
            'status' => 'published', 'published_at' => now()->subDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->get('/works')->assertOk()->assertSee('name="q"', false);
    }
}
