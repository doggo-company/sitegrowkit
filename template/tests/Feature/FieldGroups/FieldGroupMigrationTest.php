<?php

declare(strict_types=1);

namespace Tests\Feature\FieldGroups;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Phase 9.5-A / ADR-0015 — migration の契約（必須テスト 1）。
 *
 * **使い捨ての in-memory SQLite だけ**で確かめる。
 * 稼働中の `agentcms-mysql` へは接続しない。
 */
final class FieldGroupMigrationTest extends FieldGroupTestCase
{
    public function test_3つのテーブルだけが追加される(): void
    {
        foreach (['field_groups', 'field_group_locations', 'field_group_revisions'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} が作られていない");
        }
    }

    public function test_列の契約が固定されている(): void
    {
        $this->assertSame(
            ['created_at', 'fields', 'id', 'key', 'label', 'sort_order', 'status', 'updated_at'],
            $this->columns('field_groups'),
        );

        $this->assertSame(
            ['field_group_id', 'id', 'kind', 'value'],
            $this->columns('field_group_locations'),
        );

        $this->assertSame(
            ['actor_id', 'actor_label', 'created_at', 'field_group_id', 'id', 'revision', 'snapshot'],
            $this->columns('field_group_revisions'),
        );
    }

    public function test_keyは一意で重複を保存できない(): void
    {
        $this->makeGroup('duplicated', []);

        $this->expectExceptionMessageMatches('/UNIQUE|Integrity constraint/i');

        $this->makeGroup('duplicated', []);
    }

    public function test_同じ割当先を二重に書けない(): void
    {
        $id = $this->makeGroup('spec', [], [['kind' => 'content_type', 'value' => 'news']]);

        $this->expectExceptionMessageMatches('/UNIQUE|Integrity constraint/i');

        DB::table('field_group_locations')->insert([
            'field_group_id' => $id,
            'kind' => 'content_type',
            'value' => 'news',
        ]);
    }

    public function test_存在しないfield_groupへlocationを挿せない(): void
    {
        $this->expectExceptionMessageMatches('/FOREIGN KEY|Integrity constraint/i');

        DB::table('field_group_locations')->insert([
            'field_group_id' => (string) Str::ulid(),
            'kind' => 'content_type',
            'value' => 'news',
        ]);
    }

    /**
     * 参照されている Field Group は **RESTRICT** で消せない。
     * CASCADE にしていたら、この test は「消せてしまう」で落ちる。
     */
    public function test_割当先が残っているfield_groupは削除できない(): void
    {
        $id = $this->makeGroup('restricted', [], [['kind' => 'content_type', 'value' => 'news']]);

        $this->expectExceptionMessageMatches('/FOREIGN KEY|Integrity constraint/i');

        DB::table('field_groups')->where('id', $id)->delete();
    }

    /** down は追加した 3 つだけを落とし、既存テーブルへ触れない */
    public function test_rollbackで3テーブルだけが消える(): void
    {
        $this->assertTrue(Schema::hasTable('news'));

        $migration = require base_path('core/database/migrations/2026_08_15_000003_create_field_group_tables.php');
        $migration->down();

        foreach (['field_groups', 'field_group_locations', 'field_group_revisions'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} が残っている");
        }

        // 既存テーブルは無傷
        foreach (['news', 'works', 'pages', 'taxonomies', 'audit_logs', 'users'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} を巻き込んでいる");
        }

        $migration->up();
        $this->assertTrue(Schema::hasTable('field_groups'));
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        $columns = Schema::getColumnListing($table);
        sort($columns);

        return $columns;
    }
}
