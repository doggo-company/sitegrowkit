<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * メニューから分類の一覧を指せるようにする（WP 一周 7 周目 2026-09-03 / 効くもの ③）。
 *
 * ## なぜ URL 直接指定では足りないか
 *
 * 分類ごとの一覧（`/blog/{分類のキー}/{項目の綴り}`）は入れたのに、
 * メニューの種類は 5 つのままだった — つまり「カテゴリをナビに出す」には
 * `URL を直接指定` で手打ちするしかない。
 *
 * それだと **項目の綴りを変えた瞬間に、メニューが黙って 404 になる**。
 * 綴りは**あとから変えられる**（`term-1` を `yobou` へ、など）ので、
 * これは「いつか起きる」ではなく「そのうち必ず起きる」形である。
 *
 * だから**参照で持つ**。`menu_items` は URL を持たない設計（`MenuItem` の docblock）で、
 * ここもそれに従う。
 *
 * ## 投稿タイプも要る
 *
 * 一覧の URL は入口（投稿タイプの公開スラッグ）から始まる。
 * どの入口の下の一覧かは項目だけからは決まらないので、
 * 既にある `content_type` と組で持つ。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('menu_items')) {
            return;
        }

        if (Schema::hasColumn('menu_items', 'taxonomy_term_id')) {
            return;
        }

        Schema::table('menu_items', function (Blueprint $table): void {
            // term の id は ULID（26 文字）。`taxonomy_terms.id` と同じ形
            $table->string('taxonomy_term_id', 26)->nullable()->after('content_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('menu_items') || ! Schema::hasColumn('menu_items', 'taxonomy_term_id')) {
            return;
        }

        Schema::table('menu_items', function (Blueprint $table): void {
            $table->dropColumn('taxonomy_term_id');
        });
    }
};
