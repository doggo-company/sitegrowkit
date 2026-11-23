<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * session の世代番号（STAGE #18A Correction / 指摘 3）。
 *
 * ## なぜ列を足すのか
 *
 * 無効化とロール変更の「その場でログアウト」を、
 * **session driver に依存せず**成立させるため。
 * `sessions` テーブルから行を消す方法は `SESSION_DRIVER=database` でしか効かず、
 * file / redis / cookie では開いたままの画面が古い権限で動き続けてしまう。
 *
 * ログイン時に現在の世代番号を session へ入れ、毎リクエストで突き合わせる。
 * 権限が動いたら番号を進めるだけで、**driver に関係なく**次のリクエストで弾ける。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedInteger('session_epoch')->default(0)->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('session_epoch');
        });
    }
};
