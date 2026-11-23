<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 行動履歴の検索とセキュリティイベント（PHASE WP-BASELINE / STAGE #18A）。
 *
 * ## 追加する列
 *
 * `ip_address` / `user_agent` は **認証まわりのセキュリティイベントだけ**に入る。
 * 個人情報なので表示はオーナー限定、保持期間も別建てにする
 * （`anonymized_at` が入った行は IP と User-Agent が消えている）。
 *
 * ## 追加する index
 *
 * 「誰の履歴か」「いつか」「結果は何か」で必ず絞り込むため、
 * 実際の検索条件に合わせて張る。既存の index は変更しない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->string('ip_address', 45)->nullable()->after('request_id');
            // User-Agent は長さの上限を持たせる（際限なく保存しない）
            $table->string('user_agent', 255)->nullable()->after('ip_address');
            $table->timestamp('anonymized_at')->nullable()->after('user_agent');

            $table->index(['actor_id', 'created_at']);
            $table->index('created_at');
            $table->index('result');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex(['actor_id', 'created_at']);
            $table->dropIndex(['created_at']);
            $table->dropIndex(['result']);
            $table->dropColumn(['ip_address', 'user_agent', 'anonymized_at']);
        });
    }
};
