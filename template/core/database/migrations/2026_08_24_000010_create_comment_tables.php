<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * コメントと、記事ごとの受付可否（Phase 9.13 / STAGE 26C・SPEC §B.1・§B.2）。
 *
 * ## 記事への FK は張らない
 *
 * 型は管理画面から増えるので、参照先の表が**実行時にしか決まらない**。
 * `content_entry_revisions` が既に `(content_type, content_id)` の組で指す形を
 * 採っているので、同じ形にする。**新しい流儀を持ち込まない。**
 *
 * ## 返信の親は RESTRICT
 *
 * 親を消すと返信だけが宙に浮く。「消す」は既定でゴミ箱なので（§B.3）、
 * 物理削除の順序は運用の判断に残す。
 *
 * ## 記事ごとの設定は「行が無ければ継承」
 *
 * 既定値を全記事へ書き込まない。書き込むと、型の設定を変えたときに
 * **過去の記事だけ古い値のまま**になる。
 *
 * ## down は fail-closed
 *
 * コメントは**他人が書いた文章**で、落とすと戻す先が無い。
 * 1 行でも在れば、何も変更せずに止まる。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('content_comments')) {
            Schema::create('content_comments', function (Blueprint $table): void {
                $table->id();
                // 型の id と記事の id。**FK は張らない**（参照先が実行時にしか決まらない）
                $table->string('content_type', 64);
                $table->unsignedBigInteger('content_id');
                // 返信の親。1 段まで（2 段目は Application 層が断る — §B.5）
                $table->unsignedBigInteger('parent_id')->nullable();
                // pending / approved / spam / trash。**物理削除を既定にしない**
                $table->string('status', 20)->default('pending');
                $table->string('author_name', 80);
                // **公開面へ 1 度も出さない**（§G.1）
                $table->string('author_email', 255)->nullable();
                $table->string('author_url', 255)->nullable();
                $table->text('body');
                // 管理画面から書いた返信（表示も moderation も同じ経路に乗せる）
                $table->boolean('is_staff')->default(false);
                $table->unsignedBigInteger('author_user_id')->nullable();
                // 生の IP は持たない（sha256(APP_KEY + ip) — 9.10 / 9.12 と同じ）
                $table->char('ip_hash', 64)->nullable();
                $table->string('user_agent', 255)->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();

                $table->index(['content_type', 'content_id', 'status', 'created_at'], 'idx_comment_entry');
                $table->index(['status', 'created_at'], 'idx_comment_status');
                $table->index('parent_id', 'idx_comment_parent');

                // **RESTRICT。** 返信だけが宙に浮く状態を作らない
                $table->foreign('parent_id', 'fk_comment_parent')
                    ->references('id')->on('content_comments')
                    ->restrictOnDelete();

                // 書いた管理者を消しても、返信そのものは残す
                $table->foreign('author_user_id', 'fk_comment_author')
                    ->references('id')->on('users')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasTable('content_comment_settings')) {
            Schema::create('content_comment_settings', function (Blueprint $table): void {
                $table->string('content_type', 64);
                $table->unsignedBigInteger('content_id');
                /*
                 * **行が無ければ「型の設定を継承」。**
                 * この列は「明示的に開いた / 閉じた」だけを表す。
                 */
                $table->boolean('is_open');
                $table->timestamp('updated_at')->nullable();

                $table->primary(['content_type', 'content_id']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('content_comments')) {
            $rows = DB::table('content_comments')->count();

            if ($rows > 0) {
                throw new RuntimeException(
                    "コメントの表を戻せません。{$rows} 件のコメントが残っています。".
                    "\nコメントは他人が書いた文章で、落とすと戻す先がありません。".
                    "\n戻す場合は、必要なものを書き出したうえで先に中身を片付けてから再実行してください。".
                    "\n（この migration は表を 1 つも変更していません）",
                );
            }
        }

        Schema::dropIfExists('content_comment_settings');
        Schema::dropIfExists('content_comments');
    }
};
