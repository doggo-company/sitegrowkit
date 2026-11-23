<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * フォームと受信（Phase 9.12 / STAGE 25B・SPEC §B.1・§B.2）。
 *
 * ## 項目の定義はここに持たない
 *
 * 入力項目は Field Group（`location.kind = form`）が持つ（OG1）。
 * 9.5-A が場所を用意し「content と form は Field 定義だけを共用する（G9）」と決めているので、
 * ここでは**どの束を使うか**（`field_group_id`）だけを指す。
 *
 * ## 入力値は JSON
 *
 * 実カラムにすると、**フォームを編集するたびに migration** が要る。
 * 管理画面から表を増やせる形は、DDL を外部入力に近づけることでもある（OG3）。
 *
 * ## 受信が残っているフォームは消せない
 *
 * `form_submissions.form_id` は **RESTRICT**。cascade にすると、
 * フォームを消した拍子に**問い合わせの中身ごと消える**。
 * 消したいなら先に受信を片付ける、という順序を DB に持たせる。
 *
 * ## down は fail-closed
 *
 * 受信は顧客とのやり取りそのもので、落とすと戻す先が無い。
 * 1 行でも在れば、何も変更せずに止まる。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('forms')) {
            Schema::create('forms', function (Blueprint $table): void {
                $table->id();
                // 公開 URL に出る（/forms/{key}）
                $table->string('key', 64);
                $table->string('name', 120);
                $table->text('description')->nullable();
                /*
                 * 項目を持つ Field Group。**FK は張らない** —
                 * `field_groups.id` は ULID の char で、束が無い状態（項目ゼロ）も
                 * 正しい状態だからである。存在確認は Application 層が行う。
                 */
                $table->char('field_group_id', 26)->nullable();
                // draft / published / closed
                $table->string('status', 20)->default('draft');
                // 確認画面を挟むか（日本のフォームでは期待されることが多い — OG5）
                $table->boolean('confirm_step')->default(true);
                $table->string('submit_label', 40)->default('送信する');
                $table->text('complete_message')->nullable();
                // 空ならサイト設定の宛先を使う
                $table->string('notify_to', 255)->nullable();
                /*
                 * 自動返信の宛先に使う項目の key。**推測しない**（OG6）。
                 * 「連絡用メール」と「担当者メール」がある構成で誤送信させない。
                 */
                $table->string('autoreply_field', 64)->nullable();
                $table->string('autoreply_subject', 200)->nullable();
                $table->text('autoreply_body')->nullable();
                $table->unsignedBigInteger('lock_version')->default(0);
                $table->timestamps();

                $table->unique('key', 'uq_form_key');
                $table->index('status', 'idx_form_status');
            });
        }

        if (! Schema::hasTable('form_submissions')) {
            Schema::create('form_submissions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('form_id');
                /*
                 * 入力値。**表示名（`__labels`）も一緒に持つ**（OG4）。
                 * 項目を消す・改名すると、過去の受信が読めなくなるためである。
                 */
                $table->json('data');
                $table->string('status', 20)->default('received');
                // 通知の結果（メールが落ちても受信は残す — ADR-0005）
                $table->timestamp('admin_notified_at')->nullable();
                $table->string('admin_notification_error', 500)->nullable();
                $table->timestamp('autoreply_sent_at')->nullable();
                $table->string('autoreply_error', 500)->nullable();
                // 生の IP は持たない（sha256(APP_KEY + ip) — 9.10 と同じ）
                $table->char('ip_hash', 64)->nullable();
                $table->string('user_agent', 255)->nullable();
                $table->timestamps();

                $table->index(['form_id', 'created_at'], 'idx_submission_form');
                $table->index(['form_id', 'status'], 'idx_submission_status');

                // **RESTRICT。** 受信の中身だけが宙に浮く状態を作らない
                $table->foreign('form_id', 'fk_submission_form')
                    ->references('id')->on('forms')
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        $blockers = [];

        foreach (['form_submissions' => '受信', 'forms' => 'フォーム'] as $table => $label) {
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
                "フォームの表を戻せません。次のものが残っています: \n  - ".
                implode("\n  - ", $blockers).
                "\n受信は顧客とのやり取りそのもので、落とすと戻す先がありません。".
                "\n戻す場合は、必要な受信を書き出したうえで、先に中身を片付けてから再実行してください。".
                "\n（この migration は表を 1 つも変更していません）",
            );
        }

        Schema::dropIfExists('form_submissions');
        Schema::dropIfExists('forms');
    }
};
