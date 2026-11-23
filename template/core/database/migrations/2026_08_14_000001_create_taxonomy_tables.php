<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\Schema;

/**
 * Taxonomy 基盤（Phase 9.3B / STAGE 1・SPEC §G.2 / §G.7 / §B）。
 *
 * **新規 3 テーブルのみ。既存テーブルの ALTER は行わない**（G3-a）。
 * `works.category` にも `news` にも触れない。
 *
 * 設計上の固定事項:
 * - identity は immutable ULID（CHAR(26)）。slug は identity にしない
 * - **すべての外部キーは RESTRICT**（CASCADE を使わない — §G.4 / §H.1）
 * - 導出値（count / depth）を永続化しない。assignment へ taxonomy_id を非正規化しない
 * - index は全て 767 バイト以内（共有ホスティングの MySQL 5.7 世代互換 — §G.7）
 * - generated column / functional index / CHECK 制約に依存しない（5.7 と 8.4 の両立）
 * - **この migration は taxonomy-sync を実行せず、term も作らない**（SY-1 / PV-1）
 */
return new class extends Migration
{
    /**
     * ULID 列。MySQL では ascii / ascii_bin にして比較を厳密にし index を縮める。
     * SQLite（テスト）は ascii_bin を知らないため修飾子を付けない。
     */
    private function ulid(Blueprint $table, string $name): ColumnDefinition
    {
        $column = $table->char($name, 26);

        if ($this->isMySql()) {
            $column->charset('ascii')->collation('ascii_bin');
        }

        return $column;
    }

    private function isMySql(): bool
    {
        return Schema::getConnection()->getDriverName() === 'mysql';
    }

    public function up(): void
    {
        // ── taxonomies ───────────────────────────────────────────────
        // key は machine key（rename は MIGRATION_REQUIRED — §C.2）
        Schema::create('taxonomies', function (Blueprint $table): void {
            $this->ulid($table, 'id')->primary();
            // varchar(64) unique = 256 バイト（utf8mb4 最悪値）
            $table->string('key', 64);
            $table->string('label', 100);
            $table->boolean('hierarchical')->default(false);
            // code = 定義ファイルが SSoT / cms = 管理画面が SSoT（9.4 で開放）
            $table->string('managed_by', 20)->default('code');
            $table->timestamps();

            $table->unique('key', 'uq_taxonomy_key');
        });

        // ── taxonomy_terms ───────────────────────────────────────────
        Schema::create('taxonomy_terms', function (Blueprint $table): void {
            $this->ulid($table, 'id')->primary();
            $this->ulid($table, 'taxonomy_id');
            $table->string('name', 120);
            // slug は同一 taxonomy 内 unique。identity ではない（§C.1 / §F.1）
            $table->string('slug', 120);
            $table->text('description')->nullable();
            $this->ulid($table, 'parent_id')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            // null = active / 値あり = archived（§I.5・G3-e）
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            // 584 バイト（char(26) + varchar(120)）
            $table->unique(['taxonomy_id', 'slug'], 'uq_term_taxonomy_slug');
            // 212 バイト。一覧と並べ替え
            $table->index(['taxonomy_id', 'parent_id', 'sort_order'], 'idx_term_tree');
            // 108 バイト。active / archived の絞り込み
            $table->index(['taxonomy_id', 'archived_at'], 'idx_term_archived');

            $table->foreign('taxonomy_id', 'fk_term_taxonomy')
                ->references('id')->on('taxonomies')
                ->restrictOnDelete()->restrictOnUpdate();

            // 親を消したときに子が黙って root へ昇格しないよう nullOnDelete にしない（§H.1）
            $table->foreign('parent_id', 'fk_term_parent')
                ->references('id')->on('taxonomy_terms')
                ->restrictOnDelete()->restrictOnUpdate();
        });

        // ── taxonomy_term_assignments ────────────────────────────────
        // content_id → 動的な content テーブルには FK を張れない（参照先が type ごとに違う）。
        // Application 層で守る（§H.2）
        Schema::create('taxonomy_term_assignments', function (Blueprint $table): void {
            $table->id();
            $this->ulid($table, 'term_id');
            // ContentTypeResolver::knows() が true の type のみ（§G.3 CT-1〜CT-9）
            $table->string('content_type', 64);
            $table->unsignedBigInteger('content_id');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('created_at')->nullable();

            // 368 バイト。二重付与の防止
            $table->unique(['term_id', 'content_type', 'content_id'], 'uq_assignment_entry');
            // 264 バイト。エントリから term を引く
            $table->index(['content_type', 'content_id'], 'idx_assignment_entry');
            // term からエントリを引く
            $table->index('term_id', 'idx_assignment_term');

            $table->foreign('term_id', 'fk_assignment_term')
                ->references('id')->on('taxonomy_terms')
                ->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        // 参照の逆順に落とす（RESTRICT のため順序が必須）
        Schema::dropIfExists('taxonomy_term_assignments');
        Schema::dropIfExists('taxonomy_terms');
        Schema::dropIfExists('taxonomies');
    }
};
