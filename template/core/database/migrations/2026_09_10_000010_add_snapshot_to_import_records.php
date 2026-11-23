<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 台帳に「取り込んだ直後の姿」を残す（release-20260909 / wxr-pages）。
 *
 * 取り消しが「人が触ったか」を `updated_at` の前後で見ると、**同じ秒の中の編集**を
 * 見逃して物理削除してしまう（レビュー再現 2026-09-09）。時刻ではなく、
 * 取り込んだ直後の内容（行の値・版の id/revision/本文の hash）を残して、
 * 取り消すときに**今の姿と一致するものだけ**を消す。
 *
 * ページ（`kind = page`）だけが書く。他の種類は `null` のまま（従来の判定）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('import_records', 'snapshot')) {
            Schema::table('import_records', function (Blueprint $table): void {
                $table->text('snapshot')->nullable()->after('created_by_import');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('import_records', 'snapshot')) {
            Schema::table('import_records', function (Blueprint $table): void {
                $table->dropColumn('snapshot');
            });
        }
    }
};
