<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 利用者のロールと有効・無効（PHASE WP-BASELINE / STAGE #18A）。
 *
 * ## 既定値を 'viewer' にする理由
 *
 * 列を足した瞬間に既存行が最強の権限になるのを避ける。
 * **既存の利用者は下の移行で明示的に owner へ上げる**ので、
 * 「気づかないうちに誰かが owner になっていた」状態を作らない。
 *
 * ## owner 不在を作らない
 *
 * 既存の利用者は全員 owner にする（単一管理者前提だった時代の利用者は、
 * これまで実際に全操作ができていたため）。1 人も居ない環境では
 * seeder が owner を作る。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 20)->default('viewer')->after('email_verified_at')->index();
            $table->boolean('is_active')->default(true)->after('role')->index();
            // 招待した時刻（本人がまだパスワードを設定していない状態の目印）
            $table->timestamp('invited_at')->nullable()->after('is_active');
            $table->timestamp('last_login_at')->nullable()->after('invited_at');
        });

        // 既存の利用者は全員 owner（これまで全操作ができていた前提を壊さない）
        DB::table('users')->update(['role' => 'owner', 'is_active' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['role']);
            $table->dropIndex(['is_active']);
            $table->dropColumn(['role', 'is_active', 'invited_at', 'last_login_at']);
        });
    }
};
