<?php

declare(strict_types=1);

namespace Tests\Feature;

use AgentCms\Core\Schema\SchemaRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema as DbSchema;
use Tests\TestCase;

/**
 * Schema（content/schemas/*.json）と DB テーブルの整合を機械検証する（ADR-0004）。
 * フィールド追加時に migration を忘れると必ずここで落ちる。
 */
final class SchemaTableParityTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = [
        'news' => 'news',
        'works' => 'works',
        'contact' => 'contact_submissions',
        'media' => 'media',
        'product' => 'products',
    ];

    /**
     * Phase 9.2B（G2 = Hybrid）以降、突き合わせるのは **カラムに保存されるフィールド**だけ。
     * group / repeater / images / relations は `data` JSON の中なのでカラムを持たない。
     */
    public function test_every_column_backed_field_has_a_column(): void
    {
        $registry = app(SchemaRegistry::class);

        foreach (self::TABLES as $schemaId => $table) {
            $columns = DbSchema::getColumnListing($table);

            foreach ($registry->get($schemaId)->columnFields() as $field) {
                $this->assertContains(
                    $field->key,
                    $columns,
                    "Schema [{$schemaId}] field [{$field->key}] has no column in [{$table}] — migration を追加してください",
                );
            }
        }
    }

    /** 複合フィールドを持つ Content Type には data JSON カラムが要る */
    public function test_schemas_with_complex_fields_have_a_data_column(): void
    {
        $registry = app(SchemaRegistry::class);

        foreach (self::TABLES as $schemaId => $table) {
            $schema = $registry->get($schemaId);

            if (! $schema->needsDataColumn()) {
                continue;
            }

            $this->assertContains(
                'data',
                DbSchema::getColumnListing($table),
                "Schema [{$schemaId}] は複合フィールドを持つのに [{$table}] に data カラムがありません",
            );
        }
    }

    /** 複合フィールドはカラムを持たない（同じ値を二重に保存しない） */
    public function test_json_backed_fields_do_not_have_columns(): void
    {
        $registry = app(SchemaRegistry::class);

        foreach (self::TABLES as $schemaId => $table) {
            $columns = DbSchema::getColumnListing($table);

            foreach ($registry->get($schemaId)->jsonFields() as $field) {
                $this->assertNotContains(
                    $field->key,
                    $columns,
                    "Schema [{$schemaId}] field [{$field->key}] は data JSON に入るのに [{$table}] にカラムがあります（二重保存）",
                );
            }
        }
    }

    public function test_all_schemas_load_and_are_valid(): void
    {
        $all = app(SchemaRegistry::class)->all();

        // `post` は生成サイトが最初から持つ唯一の投稿タイプ（Phase 9.7 / STAGE 20B）。
        // `news` / `works` / `product` は kit の例で、新規サイトへは配らない（SPEC §C）
        $this->assertSame(['contact', 'media', 'news', 'post', 'product', 'site', 'works'], array_keys($all));
        $this->assertSame('settings', $all['site']->kind);
        $this->assertSame('submissions', $all['contact']->kind);
    }
}
