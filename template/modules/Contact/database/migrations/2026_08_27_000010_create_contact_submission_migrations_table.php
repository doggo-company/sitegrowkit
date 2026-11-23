<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 旧お問い合わせ → フォームの受信、の対応表（R-12 Stage 2 / B）。
 *
 * ## なぜ表を 1 枚作るのか
 *
 * 移行は**冪等**でなければならない（2 回流して二重に増えない）。印をどこに置くかで
 * 3 案あったが、**新側の `data` に印を入れる案は却下した** —
 * `FormSubmission::values()` が除外するのは `__labels` だけなので、
 * **移行の都合が入力値として受信詳細と CSV に出てしまう**。しかも `values()` は
 * core にあるので、この制約の側を直せない。
 *
 * 表にすれば **PK が冪等そのもの**になり、core を 1 バイトも触らずに済む。
 * さらに **どの受信がどこへ行ったかを辿れる** — 移行は必ず一度は疑われるので、
 * 辿れること自体が値打ちである。
 *
 * ## FK は張らない
 *
 * `form_submissions.form_id` が **RESTRICT** である以上、ここから更に FK を張ると
 * 「片付ける順序」が 2 段になる。ここは**記録であって原本ではない**ので、
 * 存在確認は Application 層が行う（`forms.field_group_id` と同じ判断）。
 *
 * ## 🔴 Stage 3 では旧テーブルと**同時に**畳む
 *
 * この表は `contact_submissions` を指すためだけに在る。**片方だけ残さない。**
 * 旧テーブルを消してこの表を残すと、**何も指していない記録**が残り続ける。
 * この表を消して旧テーブルを残すと、**再実行で二重に増える**。
 * B-47（kit を直しても案件へ届ける手順が無い）で見たとおり、
 * **手順に書かれていないものは実行されない**ので、ここに書いておく。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contact_submission_migrations')) {
            return;
        }

        Schema::create('contact_submission_migrations', function (Blueprint $table): void {
            // 旧受信 1 件につき 1 行。**主キーがそのまま「もう移した」の判定**である
            $table->unsignedBigInteger('contact_submission_id')->primary();
            $table->unsignedBigInteger('form_submission_id');
            $table->timestamp('migrated_at');

            // 1 件の旧受信が 2 件の受信になっていない、を DB の側でも言う
            $table->unique('form_submission_id', 'uq_contact_migration_target');
        });
    }

    /**
     * **記録なので素直に落とす。**
     *
     * 旧テーブルの `down` は fail-closed（受信は戻す先が無いため）だが、
     * こちらは原本ではない。ただし**落としたあとに移行をもう一度流すと二重に増える**ので、
     * 戻すなら旧テーブルと揃えること。
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_submission_migrations');
    }
};
