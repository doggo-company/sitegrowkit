<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ページの予約公開（release R02）。
 *
 * **列は増やさない。** `pages.published_at` は最初から nullable で在り
 * （`2026_08_13_000001_create_pages_tables`）、予約は「公開状態で日時が未来」として
 * 同じ列に載る。既存の公開ページ（`published_at` が null または過去）は
 * この migration の前後で見え方が変わらない。
 *
 * 足すのは公開面の絞り込み（`Page::scopePubliclyVisible()` — route / sitemap /
 * 導線）が毎回引く `published_at` の index だけ。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table): void {
            $table->index('published_at', 'pages_published_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table): void {
            $table->dropIndex('pages_published_at_index');
        });
    }
};
