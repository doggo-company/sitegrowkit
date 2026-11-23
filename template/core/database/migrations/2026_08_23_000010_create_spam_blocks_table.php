<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 弾いた件数（Phase 9.13 / STAGE 26B・SPEC §F.1）。
 *
 * ## `form_spam_blocks` を畳んでここへ移す
 *
 * 9.12 はフォーム専用の表として作った。コメント（P0-9）も同じ対策を使うので、
 * **書き込み口ごとに表を増やさない**。増やすと、片方だけ整理する・
 * 片方だけ直す、という食い違いが必ず起きる。
 *
 * 既存の行は `kind = 'form'` として**そのまま持ってくる**。
 *
 * ## FK は張らない
 *
 * `subject_id` はフォームの id のことも、投稿タイプの id のこともある。
 * 参照先が 1 つに決まらないので FK は置けない。対象が消えたときの後始末は
 * `SpamBlocks::forget()` が明示的に行う（黙って残さない）。
 *
 * ## down は落としてよい
 *
 * ここは**数えただけのもの**で、失っても戻す先のあるデータが消えるわけではない
 * （`form_submissions` の fail-closed とは性質が違う）。
 * ただし戻せる分は戻す — `kind = 'form'` で、フォームがまだ在る行だけを書き戻す。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('spam_blocks')) {
            Schema::create('spam_blocks', function (Blueprint $table): void {
                $table->id();
                // form / comment
                $table->string('kind', 20);
                // 対象。フォームの id や投稿タイプの id が入るので文字列で持つ
                $table->string('subject_id', 64);
                // honeypot / rate_limit / bot
                $table->string('reason', 20);
                $table->date('blocked_on');
                $table->unsignedBigInteger('hits')->default(0);
                $table->timestamps();

                /*
                 * 1 日 1 行へ畳む鍵。
                 *
                 * **名前を旧表と変える。** SQLite の索引名は DB 全体で一意なので、
                 * 移行の途中（旧表がまだ在る瞬間）に同じ名前では作れない。
                 */
                $table->unique(['kind', 'subject_id', 'reason', 'blocked_on'], 'uq_spam_block_subject_day');
            });
        }

        if (! Schema::hasTable('form_spam_blocks')) {
            return;
        }

        foreach (DB::table('form_spam_blocks')->orderBy('id')->cursor() as $row) {
            DB::table('spam_blocks')->updateOrInsert(
                [
                    'kind' => 'form',
                    'subject_id' => (string) $row->form_id,
                    'reason' => (string) $row->reason,
                    'blocked_on' => (string) $row->blocked_on,
                ],
                [
                    'hits' => (int) $row->hits,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ],
            );
        }

        Schema::drop('form_spam_blocks');
    }

    public function down(): void
    {
        if (! Schema::hasTable('form_spam_blocks')) {
            Schema::create('form_spam_blocks', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('form_id');
                $table->string('reason', 20);
                $table->date('blocked_on');
                $table->unsignedBigInteger('hits')->default(0);
                $table->timestamps();

                $table->unique(['form_id', 'reason', 'blocked_on'], 'uq_spam_block_day');

                $table->foreign('form_id', 'fk_spam_block_form')
                    ->references('id')->on('forms')
                    ->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('spam_blocks') && Schema::hasTable('forms')) {
            $forms = DB::table('forms')->pluck('id')->map(strval(...))->all();

            foreach (DB::table('spam_blocks')->where('kind', 'form')->orderBy('id')->cursor() as $row) {
                // 消えたフォームの分は書き戻せない（FK があるため）。数なので落とす
                if (! in_array((string) $row->subject_id, $forms, true)) {
                    continue;
                }

                DB::table('form_spam_blocks')->updateOrInsert(
                    [
                        'form_id' => (int) $row->subject_id,
                        'reason' => (string) $row->reason,
                        'blocked_on' => (string) $row->blocked_on,
                    ],
                    [
                        'hits' => (int) $row->hits,
                        'created_at' => $row->created_at,
                        'updated_at' => $row->updated_at,
                    ],
                );
            }
        }

        Schema::dropIfExists('spam_blocks');
    }
};
