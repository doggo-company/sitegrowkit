<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 復元の commit 印（release R09・2026-09-09）。
 *
 * 復元は DB の入れ直しとメディアの rename を 1 つの transaction の中で行う。
 * 途中でプロセスが消えたとき、**commit が通ったかどうか**をファイルは知らない
 * （journal の「committing」は意図であって事実ではない）。
 *
 * そこで同じ transaction の中でこの表へ 1 行入れる。行が在れば commit は通っている。
 * この表は backup に入れず、復元でも消さない（`DatabaseDump::EXCLUDED_TABLES`）
 * — 復元で入れ替わる側に印を置いても、印にならない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_restores', function (Blueprint $table): void {
            $table->string('restore_id', 80)->primary();
            $table->string('backup_id', 80);
            $table->string('pre_restore_id', 80);
            $table->timestamp('committed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_restores');
    }
};
