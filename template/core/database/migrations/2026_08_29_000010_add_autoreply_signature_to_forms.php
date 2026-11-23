<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 自動返信の署名を、本文とは別に持つ（R-11 ③）。
 *
 * ## なぜ本文に書かせないか
 *
 * 署名は**本文を書き換えても変わらない**部分である。
 * 本文の末尾に書かせると、文面を直すたびに署名も巻き込んで消える。
 *
 * ## 空のままなら、これまでどおり
 *
 * 既定は `null` で、そのときは従来どおりサイト名 1 行が出る。
 * **導入しただけで既存フォームの文面が変わることはない。**
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('forms', 'autoreply_signature')) {
            return;
        }

        Schema::table('forms', function (Blueprint $table): void {
            $table->text('autoreply_signature')->nullable()->after('autoreply_body');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('forms', 'autoreply_signature')) {
            return;
        }

        Schema::table('forms', function (Blueprint $table): void {
            $table->dropColumn('autoreply_signature');
        });
    }
};
