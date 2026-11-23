<?php

declare(strict_types=1);

namespace Tests\Feature\Trash;

use AgentCms\Core\ContentTypes\ContentTypeDraft;
use AgentCms\Core\ContentTypes\ContentTypeGenerator;
use AgentCms\Core\ContentTypes\Registration\EditorialCollections;
use AgentCms\Core\Schema\SchemaRegistry;
use AgentCms\Core\Trash\TrashColumns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 列を足す側（Phase 9.8 / STAGE 21B・SPEC §C.4.2・§C.4.3・§C.4.4）。
 *
 * 見たいのは 3 つ。
 *
 * 1. **投稿タイプ全部**が同じ形になる（型によってゴミ箱がある / ない、を作らない）
 * 2. **投稿タイプでないもの**には足さない
 * 3. down は **守るものが 1 件でもあれば 1 列も落とさない**
 */
final class TrashMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_すべての投稿タイプが同じ3列を持つ(): void
    {
        $editorial = $this->editorialTables();

        $this->assertNotSame([], $editorial, '投稿タイプが 1 つも無いなら、この検査は意味を失っている');

        foreach ($editorial as $table) {
            foreach (TrashColumns::ALL as $column) {
                $this->assertTrue(
                    Schema::hasColumn($table, $column),
                    "{$table} に {$column} が無い",
                );
            }
        }
    }

    public function test_投稿タイプでないものには足さない(): void
    {
        $schemas = app(SchemaRegistry::class)->declared();

        foreach ($schemas as $schema) {
            if (EditorialCollections::isEditorial($schema) || ! Schema::hasTable($schema->table)) {
                continue;
            }

            foreach (TrashColumns::ALL as $column) {
                $this->assertFalse(
                    Schema::hasColumn($schema->table, $column),
                    "{$schema->table}（投稿タイプではない）に {$column} が増えている",
                );
            }
        }

        // 分類・Field Group・投稿タイプの定義そのものも対象外（SPEC §A）
        foreach (['taxonomies', 'taxonomy_terms', 'field_groups', 'content_types'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $this->assertFalse(Schema::hasColumn($table, TrashColumns::DELETED_AT), "{$table} に列が増えている");
        }
    }

    public function test_lock_versionの初期値は0(): void
    {
        $id = DB::table('post')->insertGetId([
            'title' => 'はじめ', 'slug' => 'first', 'body' => '<p>b</p>', 'status' => 'draft',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // migration の default が付く（`updated_at` から導出しない — OG11）
        $this->assertSame(0, (int) DB::table('post')->where('id', $id)->value(TrashColumns::LOCK_VERSION));
    }

    public function test_対象の走査は型名を書かずに配布契約から引く(): void
    {
        $source = file_get_contents(
            base_path('core/database/migrations/2026_08_17_000010_add_trash_state_to_editorial_content_tables.php'),
        );

        $this->assertIsString($source);

        // 見たいのは**動く側**である。なぜそう書いたかを説明する文章は対象にしない
        $code = preg_replace(['#/\*[\s\S]*?\*/#', '#(^|\s)//[^\n]*#'], '', $source) ?? '';

        // 現時点の例を製品の契約として焼き込まない
        foreach (["'news'", "'works'", "'product'", "'post'"] as $literal) {
            $this->assertStringNotContainsString($literal, $code, "型名 {$literal} が焼き込まれている");
        }

        // 判定は 9.7 の分類に任せる（2 か所に書かない）
        $this->assertStringContainsString('EditorialCollections::isEditorial', $source);
    }

    public function test_生成される型は最初から3列を持つ(): void
    {
        $migration = app(ContentTypeGenerator::class)->migrationSource($this->draft());

        foreach (TrashColumns::ALL as $column) {
            $this->assertStringContainsString("'{$column}'", $migration, "生成 migration に {$column} が無い");
        }

        $this->assertStringContainsString('->default(0)', $migration, 'lock_version の既定値が無い');
        $this->assertStringContainsString("timestamp('deleted_at')->nullable()->index()", $migration);
    }

    public function test_生成されるモデルは既定除外を持つ(): void
    {
        $model = app(ContentTypeGenerator::class)->modelSource($this->draft());

        /*
         * 生成物へ trait を **書き写さない**。既定除外は `HasContentSchema` の中に在り、
         * core を更新するだけで既存の生成物にも届く（21B 是正）。
         * ここへ 2 つ目の経路を作ると、古い生成物だけ取り残される形が戻ってくる。
         */
        $this->assertStringContainsString('use HasContentSchema;', $model);
        $this->assertStringNotContainsString('HasTrashState', $model);
        // Laravel 標準の論理削除は使わない（SPEC §C.2）
        $this->assertStringNotContainsString('SoftDeletes', $model);
    }

    private function draft(): ContentTypeDraft
    {
        $parsed = ContentTypeDraft::fromInput([
            'id' => 'faq',
            'label' => 'よくある質問',
            'fields' => [
                ['key' => 'title', 'type' => 'text', 'label' => 'タイトル', 'required' => true],
                ['key' => 'slug', 'type' => 'slug', 'label' => 'スラッグ'],
            ],
            'list' => ['title'],
            'search' => ['title'],
        ]);

        $this->assertSame([], $parsed['errors']);
        $this->assertNotNull($parsed['draft']);

        return $parsed['draft'];
    }

    /** @return list<string> */
    private function editorialTables(): array
    {
        $tables = [];

        foreach (app(SchemaRegistry::class)->declared() as $schema) {
            if (EditorialCollections::isEditorial($schema) && Schema::hasTable($schema->table)) {
                $tables[] = $schema->table;
            }
        }

        return $tables;
    }
}
