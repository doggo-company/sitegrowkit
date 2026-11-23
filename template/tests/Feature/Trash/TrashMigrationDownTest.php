<?php

declare(strict_types=1);

namespace Tests\Feature\Trash;

use AgentCms\Core\Trash\TrashColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * down は fail-closed（Phase 9.8 / STAGE 21B・SPEC §C.4.4）。
 *
 * `deleted_at` を落とすと、**ゴミ箱にあった投稿が公開へ戻る**。
 * `lock_version` を落とすと競合検出の記録が消える。
 * どちらも「気づかないうちに起きる」ので、止めるほうを既定にする。
 *
 * **止めるときは 1 列も変更しない。** 途中まで落として止まるのは rollback ではない。
 */
final class TrashMigrationDownTest extends TestCase
{
    use RefreshDatabase;

    public function test_守るものが無ければ列を落とせる(): void
    {
        DB::table('post')->insert($this->row('clean'));

        $this->migration()->down();

        foreach (TrashColumns::ALL as $column) {
            $this->assertFalse(Schema::hasColumn('post', $column), "{$column} が残っている");
        }

        // 投稿そのものは消さない
        $this->assertSame(1, DB::table('post')->count());
    }

    public function test_ゴミ箱に投稿があれば止まる(): void
    {
        DB::table('post')->insert($this->row('trashed', [TrashColumns::DELETED_AT => now()]));

        $this->assertRefusesAndChangesNothing('ゴミ箱の投稿が 1 件');
    }

    public function test_競合検出の痕跡があれば止まる(): void
    {
        DB::table('post')->insert($this->row('locked', [TrashColumns::LOCK_VERSION => 3]));

        $this->assertRefusesAndChangesNothing('lock_version > 0');
    }

    public function test_版の記録があれば止まる(): void
    {
        /*
         * 版の表は **STAGE 21D** で作られた。1 件でも版が在れば、
         * ゴミ箱の列を落とすのは止める — 版と entry の対応が失われるからである。
         */
        $this->assertTrue(Schema::hasTable('content_entry_revisions'));

        DB::table('content_schema_manifests')->insert([
            'content_type' => 'post', 'fingerprint' => str_repeat('a', 64),
            'manifest' => '[]', 'created_at' => now(),
        ]);

        DB::table('content_entry_revisions')->insert([
            'content_type' => 'post', 'content_id' => 1, 'revision' => 1,
            'kind' => 'manual', 'snapshot' => '{}', 'schema_fingerprint' => str_repeat('a', 64),
            'reason' => 'baseline', 'created_interface' => 'cli', 'created_at' => now(),
        ]);

        $this->assertRefusesAndChangesNothing('content_entry_revisions');
    }

    public function test_版が0件なら止まらない(): void
    {
        $this->assertTrue(Schema::hasTable('content_entry_revisions'));
        $this->assertSame(0, DB::table('content_entry_revisions')->count());

        // 在る表でも、守るものが無ければ通す
        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('post', TrashColumns::DELETED_AT));
    }

    public function test_止まるときは1列も落とさない(): void
    {
        DB::table('post')->insert($this->row('trashed', [TrashColumns::DELETED_AT => now()]));

        try {
            $this->migration()->down();
            $this->fail('止まるはずが通ってしまった');
        } catch (RuntimeException) {
            // 続く
        }

        /*
         * **他の投稿タイプまで見る。** 表ごとに「調べて落とす」を繰り返すと、
         * 3 つ目で止まったときに 1 つ目と 2 つ目だけ列が消えた状態が残る。
         */
        foreach (['post', 'news', 'works', 'products'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach (TrashColumns::ALL as $column) {
                $this->assertTrue(
                    Schema::hasColumn($table, $column),
                    "止まったのに {$table}.{$column} が落ちている",
                );
            }
        }
    }

    private function assertRefusesAndChangesNothing(string $expectedReason): void
    {
        try {
            $this->migration()->down();
            $this->fail('止まるはずが通ってしまった');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($expectedReason, $e->getMessage());
            // 何をすれば戻せるかまで書く
            $this->assertStringContainsString('再実行', $e->getMessage());
        }

        foreach (TrashColumns::ALL as $column) {
            $this->assertTrue(Schema::hasColumn('post', $column), "{$column} が落ちている");
        }
    }

    /** @param array<string, mixed> $overrides */
    private function row(string $slug, array $overrides = []): array
    {
        return array_merge([
            'title' => $slug,
            'slug' => $slug,
            'body' => '<p>本文</p>',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
    }

    private function migration(): Migration
    {
        /** @var Migration $migration */
        $migration = require base_path(
            'core/database/migrations/2026_08_17_000010_add_trash_state_to_editorial_content_tables.php',
        );

        return $migration;
    }
}
