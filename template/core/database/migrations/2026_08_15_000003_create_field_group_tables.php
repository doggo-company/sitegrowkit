<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\Schema;

/**
 * Field Group 基盤（Phase 9.5-A / ADR-0015・SPEC §5 / §8 Step 1）。
 *
 * **新規 3 テーブルのみ。既存テーブルの ALTER も既存データの移行も行わない**（G6）。
 * `news` / `works` / `pages` / `content_types` / `taxonomies` へ触れない。
 *
 * 設計上の固定事項:
 * - identity は immutable ULID（CHAR(26)）。`key` は identity にしない（rename できる）
 * - **すべての外部キーは RESTRICT**（ADR-0013 / Taxonomy と同じ。CASCADE を使わない）
 * - 導出値（割当先の label・field 数）を永続化しない
 * - 列名は `order` ではなく `sort_order`。`order` は SQL の予約語で、
 *   既存の `taxonomy_terms.sort_order` と表記が揃う
 * - index は全て 767 バイト以内（共有ホスティングの MySQL 5.7 世代互換）
 * - generated column / functional index / CHECK 制約に依存しない（5.7 と 8.4 の両立）
 * - **この migration は Field Group を 1 件も作らない**。誰も読まないテーブルを置くだけ
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

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            $column->charset('ascii')->collation('ascii_bin');
        }

        return $column;
    }

    public function up(): void
    {
        // ── field_groups ─────────────────────────────────────────────
        // fields は **既存 Field 契約そのままの JSON**（新しい語彙を作らない — ADR-0015）
        Schema::create('field_groups', function (Blueprint $table): void {
            $this->ulid($table, 'id')->primary();
            // varchar(64) unique = 256 バイト（utf8mb4 最悪値）
            $table->string('key', 64);
            $table->string('label', 100);
            $table->unsignedInteger('sort_order')->default(0);
            // draft | published | archived。published だけが overlay に参加する
            $table->string('status', 20)->default('draft');
            $table->json('fields');
            $table->timestamps();

            $table->unique('key', 'uq_field_group_key');
            // 一覧の既定の並び
            $table->index(['status', 'sort_order'], 'idx_field_group_order');
        });

        // ── field_group_locations ────────────────────────────────────
        // kind は content_type / form の 2 値だけ（G11）。value は割当先の識別子。
        // **value に FK は張れない**（content type のテーブルは型ごとに違い、
        // form はまだ存在しない）。一致しない value は何にも当たらないだけで害が無い
        Schema::create('field_group_locations', function (Blueprint $table): void {
            $table->id();
            $this->ulid($table, 'field_group_id');
            $table->string('kind', 32);
            $table->string('value', 64);

            // 410 バイト。同じ割当先を二重に書けない
            $table->unique(['field_group_id', 'kind', 'value'], 'uq_field_group_location');
            // 384 バイト。「この Content Type に当たる Field Group」の逆引き
            $table->index(['kind', 'value'], 'idx_field_group_location_target');

            $table->foreign('field_group_id', 'fk_field_group_location_group')
                ->references('id')->on('field_groups')
                ->restrictOnDelete()->restrictOnUpdate();
        });

        // ── field_group_revisions ────────────────────────────────────
        // **9.5-A では 1 行も書かれない。** 置き場所を先に確保するためだけに作る
        // （後からテーブルを足すと読み取り専用環境で詰むため — ADR-0015）。
        // actor は audit_logs と同じ形（無効化・改名されても誰が保存したか読める）
        Schema::create('field_group_revisions', function (Blueprint $table): void {
            $table->id();
            $this->ulid($table, 'field_group_id');
            $table->unsignedInteger('revision');
            $table->json('snapshot');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_label', 120)->default('');
            $table->timestamp('created_at')->useCurrent();

            // 130 バイト。同じ版を二重に書けない
            $table->unique(['field_group_id', 'revision'], 'uq_field_group_revision');

            $table->foreign('field_group_id', 'fk_field_group_revision_group')
                ->references('id')->on('field_groups')
                ->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        // 参照の逆順に落とす（RESTRICT のため順序が必須）。
        // **この 3 つだけ**。既存テーブルには触れない
        Schema::dropIfExists('field_group_revisions');
        Schema::dropIfExists('field_group_locations');
        Schema::dropIfExists('field_groups');
    }
};
