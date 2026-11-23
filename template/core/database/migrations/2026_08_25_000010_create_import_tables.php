<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 取り込みの記録（Phase 9.14 / STAGE 27C・SPEC §D.2・**OG6**・**OG7**）。
 *
 * ## この 2 つの表が「取り消せること」そのもの
 *
 * 取り込みは他人のサイトの十年分を一度に作る。**何を作ったかを覚えていなければ、
 * 取り消す手段が無い。** 記事側に「import で入った」という印を持たせる案もあるが、
 * それだと分類項目やコメントに同じ印を配って回ることになり、
 * 1 か所でも漏れると取り消しが半端になる。**作ったものを 1 つの台帳に集める。**
 *
 * ## `unique(kind, target_type, external_id)` が冪等性の鍵
 *
 * 同じ WXR をもう一度 apply しても二重に作らない（SPEC §D.2）。
 * **これをアプリ側の検査だけに任せない。** 検査してから書くまでの間に
 * もう 1 本が同じ検査を通れば、二重に入る。DB に守らせる。
 *
 * `batch_id` は**わざと含めない**。含めると同じ WXR を別 batch で再実行したときに
 * 二重に作れてしまい、冪等性が壊れる。
 * 別々の WordPress サイトを 1 つの CMS へ統合する用途は 9.14 の対象外である
 * （やるなら `channel` の `base_site_url` から安定した site 識別子を設計し、
 * `unique(source_site, kind, target_type, external_id)` にする別の Owner Gate が要る）。
 *
 * ## `kind` は「何の記録か」、所有権は別の列
 *
 * `kind` は `content` / `term` / `comment` / `media` の 4 つだけである。
 * 「重なった定義」「使い回した対象」を `kind` の値として足したくなるが、
 * それは**何の記録かではなく、その対象を誰が作ったか**の話で、軸が違う。
 * 混ぜると一意制約の意味まで変わる。所有権は `created_by_import` が持つ。
 *
 * ## 対応先を変えて実行し直した場合
 *
 * 一意制約は `target_type` を含むので、`--type` を変えれば**同じ記事が
 * 2 つの型へ入る**。制約は通ってしまうので、`(kind, external_id)` の索引で
 * 先に引いて突き合わせ、既に別の `target_type` にあれば V1 は
 * `LedgerConflict` として止める。**別の対応先へ二重に作らない。**
 *
 * ## 記事への外部キーは張らない
 *
 * 型は管理画面から増えるので、参照先の表が**実行時にしか決まらない**。
 * `content_entry_revisions` や `content_comments` が既に `(型, id)` の組で指す形を
 * 採っているので、同じ形にする。**新しい流儀を持ち込まない。**
 *
 * ## `batch_id` は RESTRICT
 *
 * batch だけ消えると「何を入れたか分からない記録」が残り、取り消せなくなる。
 *
 * ## 失敗した取り込みと、成功後に取り消した取り込みを混同しない
 *
 * `status` は**いまの状態**（`running` / `completed` / `failed` / `rolled_back`）。
 * それだけだと、**失敗して取り消した batch と、成功してから取り消した batch が
 * 同じ `rolled_back` になる**。前者は半端に入った残骸を疑う必要があり、後者は無い。
 * 「取り消し済みだから安全」と読んで再実行するのが一番踏みやすい。
 * だから `failed_at` と `rolled_back_at` で**経緯を残す**。
 *
 * ## down は fail-closed
 *
 * 台帳を落とすと、**入れたものを取り消す手段そのものが消える**。
 * 1 行でも在れば、表を 1 つも変更せずに止まる。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('import_batches')) {
            Schema::create('import_batches', function (Blueprint $table): void {
                // ULID。時刻順に並ぶので、世代の整理が id の順で素直に書ける
                $table->char('id', 26)->primary();
                // **パスは持たない。** 誰のどこに置いたファイルかを台帳へ残さない（§G）
                $table->string('source', 120);
                // 中身の sha256。同じファイルの再実行を検出する
                $table->char('file_hash', 64);
                // plan と apply の食い違いを検出する（ファイルの差し替えを防ぐ）
                $table->char('plan_hash', 64);
                // --type / --taxonomy / --media
                $table->json('options');
                // running | completed | failed | rolled_back（いまの状態）
                $table->string('status', 20)->default('running');
                // 種類ごとの件数
                $table->json('counts');
                $table->timestamp('started_at');
                $table->timestamp('finished_at')->nullable();
                /*
                 * **経緯は status では表せない。**
                 * 失敗を取り消した batch は `failed_at` が残り、
                 * 成功を取り消した batch は `null` のままになる。
                 */
                $table->timestamp('failed_at')->nullable();
                $table->timestamp('rolled_back_at')->nullable();

                // 同じファイルを前に入れていないか
                $table->index('file_hash', 'idx_import_batch_file');
                $table->index(['status', 'started_at'], 'idx_import_batch_status');
            });
        }

        if (! Schema::hasTable('import_records')) {
            Schema::create('import_records', function (Blueprint $table): void {
                $table->id();
                $table->char('batch_id', 26);
                // WXR 側の id（wp:post_id など）
                $table->string('external_id', 64);
                // content | term | comment | media
                $table->string('kind', 20);
                // 投稿タイプ / 分類 key / 'comment' / 'media'
                $table->string('target_type', 64);
                // こちら側の id（型が実行時に決まるので文字列で持つ）
                $table->string('target_id', 64);

                /*
                 * **この取り込みが作ったものか。**
                 *
                 * `kind` とは直交する。`kind` は「何の記録か」で、
                 * こちらは「その対象を誰が作ったか」である。
                 *
                 * 取り消しは**自分が作ったものだけ**を消す。ここが無いと、
                 * 元から在った項目へ記事を結び付けただけの記録と、
                 * 取り込みが作った項目の記録が見分けられず、
                 * **取り消しで元からあったものまで消える**。
                 *
                 * 既定は false。**安全側に倒れる方を既定にする** —
                 * 決め忘れた記録は「他人のもの」として扱われ、消されない。
                 *
                 * 重なった定義（alias）や、既にある対象を使い回した記録は
                 * `target_id` を代表と共有するが、**所有権は持たない**（false）。
                 * 代表の 1 行だけが true になる。
                 *
                 * 後から走る batch は、**既にある対象の所有権を奪わない**。
                 * 奪えると、先の取り込みを取り消したときに後の取り込みの
                 * 対象まで消える。
                 */
                $table->boolean('created_by_import')->default(false);

                $table->timestamp('created_at');

                /*
                 * **冪等性の鍵。** batch_id は含めない（docblock 参照）。
                 * 名前を明示するのは、`down` や rollback の手順書から
                 * この制約を名指しできるようにするため。
                 */
                $table->unique(['kind', 'target_type', 'external_id'], 'uq_import_external');

                // rollback は batch 単位で引く
                $table->index(['batch_id', 'kind'], 'idx_import_record_batch');

                /*
                 * **対応先を変えて実行し直したときに気づくため。**
                 *
                 * 一意制約は `target_type` を含むので、`--type=post:news` から
                 * `--type=post:blog` へ変えると**同じ WXR の同じ記事が
                 * 2 つの型へ入る**。制約は通ってしまうので、
                 * `(kind, external_id)` で先に引いて突き合わせる。
                 *
                 * V1 は、同じ `(kind, external_id)` が別の `target_type` に
                 * 既にあれば `LedgerConflict` として止める。
                 * **別の対応先へ二重に作らない。**
                 */
                $table->index(['kind', 'external_id'], 'idx_import_kind_external');

                // **RESTRICT。** 何を入れたか分からない記録を残さない
                $table->foreign('batch_id', 'fk_import_record_batch')
                    ->references('id')->on('import_batches')
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['import_records', 'import_batches'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $rows = DB::table($table)->count();

            if ($rows > 0) {
                throw new RuntimeException(
                    "取り込みの台帳を戻せません。{$table} に {$rows} 件残っています.".
                    "\nこの表を落とすと、取り込んだものを取り消す手段そのものが無くなります。".
                    "\n先に rollback を済ませ、台帳を片付けてから再実行してください。".
                    "\n（この migration は表を 1 つも変更していません）",
                );
            }
        }

        Schema::dropIfExists('import_records');
        Schema::dropIfExists('import_batches');
    }
};
