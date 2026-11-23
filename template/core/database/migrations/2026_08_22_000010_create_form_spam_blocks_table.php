<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 弾いた件数（Phase 9.12 / STAGE 25C・SPEC §F.4）。
 *
 * ## 本文は持たない
 *
 * 弾いた送信の中身は 1 文字も入れない。持つのは
 * **どのフォームで・何の理由で・どの日に・何件**の 4 つだけである。
 * スパムの本文を貯める理由が無く、貯めれば「保存していない」と言えなくなる。
 *
 * ## 1 日 1 行
 *
 * 1 送信 1 行にすると bot に叩かれた分だけ伸びる。日単位に畳めば、
 * 行数は「フォーム数 × 理由 3 × 日数」で上から抑えられる。
 *
 * ## down は落としてよい
 *
 * ここは**数えただけのもの**で、失っても戻す先のあるデータが消えるわけではない
 * （`form_submissions` の fail-closed とは性質が違う）。同じ理由で
 * `forms` への FK も CASCADE にしてある。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('form_spam_blocks')) {
            return;
        }

        Schema::create('form_spam_blocks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('form_id');
            // honeypot / rate_limit / bot
            $table->string('reason', 20);
            $table->date('blocked_on');
            $table->unsignedBigInteger('hits')->default(0);
            $table->timestamps();

            // 1 日 1 行へ畳む鍵
            $table->unique(['form_id', 'reason', 'blocked_on'], 'uq_spam_block_day');

            /*
             * **CASCADE。** 受信（RESTRICT）とは性質が違う。
             * これはフォームについての数であって、顧客とのやり取りではない。
             * ここを RESTRICT にすると、**bot に叩かれたという理由だけで
             * フォームを消せなくなる**。運用者にスパムの責任を負わせない。
             */
            $table->foreign('form_id', 'fk_spam_block_form')
                ->references('id')->on('forms')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_spam_blocks');
    }
};
