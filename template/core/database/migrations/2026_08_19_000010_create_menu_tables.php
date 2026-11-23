<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * メニュー / ナビゲーション（Phase 9.9 / STAGE 22B・SPEC §B.1・§B.2）。
 *
 * ## URL は保存しない
 *
 * `link_url` を持てるのは `link_type = 'custom'` の行だけである（OG2）。
 * ページと投稿の公開 URL は `Route::fallback` が**要求時に DB を見て決める**ので、
 * ここへ書き写すと slug を変えた瞬間にメニューだけが古い URL を指す。しかも
 * 保存された URL が「意図」なのか「残骸」なのかを後から判定できない。
 * だから他の link_type は**参照だけ**を持ち、URL は読むときに作る（SPEC §B.3）。
 *
 * ## FK を張れるところと張れないところ
 *
 * `pages` は固定の表なので `page_id` に FK を張れる。**RESTRICT にはしない** —
 * ページを消せなくなると、メニューがページの人質になる（OG4）。
 * 参照が切れた項目は読むときに落とす（`MenuTree`）。
 *
 * `content_id` が指す表は**型ごとに違い、実行時に増える**ので FK を張れない。
 * `ContentReferences` と `content_entry_revisions` が既に同じ制約の下にあり、
 * どちらも Application 層で守る判断で揃っている。ここだけ変えない。
 *
 * ## down は fail-closed
 *
 * メニューは編集者が組み立てた導線で、落とすと**戻す先が無い**（版もゴミ箱も持たない — OG9）。
 * 1 件でも在れば、何も変更せずに止まる。
 */
return new class extends Migration
{
    public function up(): void
    {
        // ① メニュー本体。項目がこれを指す
        if (! Schema::hasTable('menus')) {
            Schema::create('menus', function (Blueprint $table): void {
                $table->id();
                $table->string('key', 64);
                $table->string('name', 120);
                $table->text('description')->nullable();
                /*
                 * 誰がこの本を持っているか（OG1）。`code` はテーマが宣言した本で、
                 * 削除と key の変更を拒む。`taxonomies.managed_by` と同じ軸である。
                 */
                $table->string('managed_by', 20)->default('admin');
                // 競合検出（Phase 9.8 / OG11 と同じ CAS。updated_at は使わない）
                $table->unsignedBigInteger('lock_version')->default(0);
                $table->timestamps();

                $table->unique('key', 'uq_menu_key');
            });
        }

        // ② 項目
        if (! Schema::hasTable('menu_items')) {
            Schema::create('menu_items', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('menu_id');
                $table->unsignedBigInteger('parent_id')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                // null = リンク先の名前をそのまま使う（SPEC §B.8）
                $table->string('title', 120)->nullable();

                // custom / route / page / content / content_index（SPEC §B.4）
                $table->string('link_type', 20);
                $table->string('link_url', 2048)->nullable();
                $table->string('link_route', 120)->nullable();
                $table->unsignedBigInteger('page_id')->nullable();
                $table->string('content_type', 64)->nullable();
                $table->unsignedBigInteger('content_id')->nullable();

                $table->string('target', 10)->default('_self');
                $table->timestamps();

                /*
                 * 木を引くための索引。並び順は **unique にしない** —
                 * 並べ替えの途中で一時的に重複するため（`taxonomy_terms` と同じ判断）。
                 */
                $table->index(['menu_id', 'parent_id', 'sort_order'], 'idx_menu_item_tree');

                /*
                 * 逆引きの索引。飾りではない。
                 * 「このページはどのメニューから参照されているか」を索けないと、
                 * ゴミ箱へ入れるときに何も警告できない（SPEC §B.2）。
                 */
                $table->index('page_id', 'idx_menu_item_page');
                $table->index(['content_type', 'content_id'], 'idx_menu_item_content');

                // メニューを消せば項目も消える（木の一部だけ残らない）
                $table->foreign('menu_id', 'fk_menu_item_menu')
                    ->references('id')->on('menus')
                    ->cascadeOnDelete();

                // 親を消せば子も消える（親の無い子を残さない）
                $table->foreign('parent_id', 'fk_menu_item_parent')
                    ->references('id')->on('menu_items')
                    ->cascadeOnDelete();
            });

            /*
             * `pages` への FK は **表がある生成物でだけ**張る。
             * module 構成によってはページを持たない生成物があり、
             * そこで migration ごと落とすと「メニューが使えない」ではなく
             * 「site が起動しない」になる。
             *
             * `nullOnDelete` にするのは OG4 のためである。ページの削除を
             * メニューが止めてはいけない。参照が切れた項目は読むときに落とす。
             */
            if (Schema::hasTable('pages')) {
                Schema::table('menu_items', function (Blueprint $table): void {
                    $table->foreign('page_id', 'fk_menu_item_page')
                        ->references('id')->on('pages')
                        ->nullOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        $blockers = [];

        foreach (['menu_items' => 'メニュー項目', 'menus' => 'メニュー'] as $table => $label) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $rows = DB::table($table)->count();

            if ($rows > 0) {
                $blockers[] = "{$table}: {$label}が {$rows} 行";
            }
        }

        if ($blockers !== []) {
            throw new RuntimeException(
                "メニューの表を戻せません。次のものが残っています: \n  - ".
                implode("\n  - ", $blockers).
                "\n落とすとサイトの導線が失われます。メニューには版もゴミ箱もありません。".
                "\n戻す場合は、導線が不要であることを確かめたうえで、先に中身を片付けてから再実行してください。".
                "\n（この migration は表を 1 つも変更していません）",
            );
        }

        // FK があるので **項目 → 本体**の順で落とす
        Schema::dropIfExists('menu_items');
        Schema::dropIfExists('menus');
    }
};
