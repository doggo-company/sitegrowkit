<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 版が自動保存かどうかを持たせる（Phase 9.6 / P0-C6b・SPEC §4.11 / Owner Gate V8）。
 *
 * ## 経路とは別の軸である
 *
 * `created_interface` は admin / api / cli という**どこから来たか**で、
 * 自動保存かどうかは**どういう保存か**である。**1 つの列に 2 つの軸を入れない。**
 * 混ぜると「api から来た自動保存」を表せなくなる。
 *
 * ## 既存の版は `false` のまま
 *
 * 既定を `false` にするので、**この migration より前に作られた版は 1 件も間引かれない**。
 * それらは「上限も間引きも無い」という約束のもとで作られている。
 * **別の約束で作られた履歴を、後から遡って消さない**（Owner 決定 2026-08-19）。
 *
 * 代償として、導入前の肥大はそのまま残る。遡って自動保存へ倒すなら
 * backup を前提にした一括の作業として別に決める — **不可逆ではない。**
 *
 * ## 索引は間引きが引く順で
 *
 * 間引きは「このページの、自動保存の版を、新しい順に N 件残して消す」を引く。
 * `(page_id, autosave, revision)` はその 1 本のためにある。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('page_versions', 'autosave')) {
            return;
        }

        Schema::table('page_versions', function (Blueprint $table): void {
            $table->boolean('autosave')->default(false)->after('created_interface');
            $table->index(['page_id', 'autosave', 'revision'], 'idx_page_version_autosave');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('page_versions', 'autosave')) {
            return;
        }

        Schema::table('page_versions', function (Blueprint $table): void {
            $table->dropIndex('idx_page_version_autosave');
            $table->dropColumn('autosave');
        });
    }
};
