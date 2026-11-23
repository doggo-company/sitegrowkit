<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 監査ログ（Phase 9.1B / STEP 8）。
 *
 * Admin・CLI・MCP のどの経路から実行されたかを含めて mutation を記録する。
 * **機密情報は保存しない**（metadata はフィールド名ベースの安全な要約のみ）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('actor_type', 20);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_label', 120)->default('');
            $table->string('interface', 20);
            $table->string('operation', 80);
            $table->string('classification', 20);
            $table->string('resource_type', 80)->nullable();
            $table->string('resource_id', 80)->nullable();
            $table->string('result', 30);
            $table->string('confirmation_token', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['operation', 'created_at']);
            $table->index(['resource_type', 'resource_id']);
            $table->index('interface');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
