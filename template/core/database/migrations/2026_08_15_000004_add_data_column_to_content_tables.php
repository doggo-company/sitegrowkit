<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Field Group の値の置き場所（Phase 9.5-B / ADR-0015・owner 決定 G2-(a)）。
 *
 * ## なぜ要るのか
 *
 * `FieldStorage::forType()` は**型だけ**で保存先を決めるので、Field Group が
 * overlay で足した `text` は「カラムに入れる」と判定される。しかし
 * `news` にも `works` にもそのカラムは無く、**`data` JSON カラムも無い**。
 * つまり足した項目は **どこにも保存できず**、投稿の作成が conflict で失敗していた。
 *
 * owner 決定 G2-(a) は「Field Group で足した項目は既定で `data` JSON」なので、
 * 受け皿を用意する。カラム化（G2-(c)）は migration を伴う opt-in として後続 Stage。
 *
 * ## 安全側の設計
 *
 * - **追加だけ**。nullable・default なし・index なし。既存行は NULL のまま
 * - 既存カラムの変更・改名・削除・データ移行を **一切しない**
 * - down は **この migration が足したカラムだけ** を落とす
 * - 既に `data` を持つテーブルには触らない（再実行しても安全）
 * - JSON カラムに index を張らない（共有ホスティングの MySQL 5.7 世代互換）
 *
 * 対象は **同梱の collection 型**。CMS が作る型は
 * `ContentTypeGenerator` が最初から `data` を持たせる。
 */
return new class extends Migration
{
    /**
     * 同梱の collection テーブル。
     *
     * **schema registry を読まない。** migration は「いつ実行しても同じ結果」で
     * なければならず、実行時のファイル内容に依存させると再現しなくなる。
     *
     * @var list<string>
     */
    private const TABLES = ['news', 'works'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'data')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->json('data')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'data')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('data');
            });
        }
    }
};
