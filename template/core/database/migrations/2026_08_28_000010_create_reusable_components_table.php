<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\Schema;

/**
 * 再利用 component（Phase 9.6 / P0-C7・`PHASE9_6_SPEC.md` §4.5・§6）。
 *
 * **本書で唯一の新規モデル。** 参照する側（node）は `instanceOf` を持つだけで、
 * テーブルは増えない。
 *
 * ## draft と published を分けて持つ
 *
 * 公開側は node の `instanceOf` から実体を引くだけなので、**下書きと公開の区別が
 * 実体側に無いと区別できない**。1 つの document で持つと、**編集した瞬間に
 * 公開中のページが変わる**。ページが draft / published を持つのと同じ向きにする。
 *
 * - `published_document` が null = **一度も公開していない**（公開側では描かない）
 * - **`status` は持たない。** `published_at` / `published_document` から導出できる
 *
 * ## 参照側に外部キーは張れない
 *
 * 参照は `page_versions.document` の **JSON の中**にある。だから DB では守れない。
 * 参照が残っているものを消さない（RESTRICT）のは**アプリ層**の仕事である（§4.5）。
 *
 * 既存の作法に揃えるもの:
 * - identity は immutable ULID（CHAR(26)）。`key` は identity にしない
 * - index は 767 バイト以内（共有ホスティングの MySQL 5.7 世代互換）
 * - generated column / functional index / CHECK 制約に依存しない
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
        Schema::create('reusable_components', function (Blueprint $table): void {
            $this->ulid($table, 'id')->primary();
            // 人が読んで指すための key。rename できる（identity ではない）
            $table->string('key', 64);
            $table->string('label', 100);
            // 編集中の部分木。**Editor と preview はこちらを読む**
            $table->json('draft_document');
            // 公開に出る部分木。null = 一度も公開していない
            $table->json('published_document')->nullable();
            $table->timestamp('published_at')->nullable();
            /*
             * null = 有効 / 値あり = しまった（`taxonomy_terms.archived_at` と同じ作法）。
             * **物理削除は置かない**（OG8 — backup ができるまで作らない）。
             */
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            // 256 バイト（utf8mb4 最悪値）
            $table->unique('key', 'uq_reusable_component_key');
            // 一覧の既定の並び（公開済みが先・新しい順）
            $table->index('published_at', 'idx_reusable_component_published');
            // 有効なものだけを引く
            $table->index('archived_at', 'idx_reusable_component_archived');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reusable_components');
    }
};
