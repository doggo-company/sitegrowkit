<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Page / PageVersion（Phase 9.1D・owner 決定 G17 / G23 / G25）。
 *
 * - Page: 識別・公開状態・SEO・managed_by（既存 Blade ページとの共存）
 * - PageVersion: document 本体。draft と published を **別 version** として持つ
 *   （公開中のページを編集しても published は変わらない）
 * - document は JSON 1 カラム。node ごとに行を作らない（Phase 9.1C §5）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 190)->unique();
            $table->string('title', 200);
            // draft / published / trashed（trash からは復元できる。purge は別操作）
            $table->string('status', 20)->default('draft');
            // document | blade — 既存の Blade 管理ページを壊さないための共存フラグ
            $table->string('managed_by', 20)->default('document');
            $table->unsignedBigInteger('draft_version_id')->nullable();
            $table->unsignedBigInteger('published_version_id')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('trashed_at')->nullable();
            // SEO は PageDocument と分離して保持する（Phase 9.1C §22）
            $table->json('seo')->nullable();
            $table->timestamps();

            $table->index(['status', 'managed_by']);
        });

        Schema::create('page_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('page_id')->constrained('pages')->cascadeOnDelete();
            // ページ内で単調増加する版番号。楽観ロック（expectedVersion）の基準
            $table->unsignedInteger('revision');
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->json('document');
            $table->string('created_interface', 20)->default('admin');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['page_id', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_versions');
        Schema::dropIfExists('pages');
    }
};
