<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 自動返信の差出人表示名と返信先を、フォームごとに持つ（R-11 ④）。
 *
 * ## 差出人アドレスは変えない
 *
 * From アドレスをフォームごとに変えると **SPF / DKIM が合わず迷惑メール判定になりやすく**、
 * 利用者は原因を追えない。変えてよいのは
 *
 * - **表示名**（同じアドレスのままなので、認証は崩れない）
 * - **返信先**（Reply-To。返信を受ける先は差出人と別でよい）
 *
 * の 2 つで、「送信元の名前やアドレスを変えたい」はこの 2 つで満たせる。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('forms', 'mail_from_name')) {
            return;
        }

        Schema::table('forms', function (Blueprint $table): void {
            $table->string('mail_from_name', 120)->nullable()->after('autoreply_signature');
            $table->string('reply_to', 255)->nullable()->after('mail_from_name');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('forms', 'mail_from_name')) {
            return;
        }

        Schema::table('forms', function (Blueprint $table): void {
            $table->dropColumn(['mail_from_name', 'reply_to']);
        });
    }
};
