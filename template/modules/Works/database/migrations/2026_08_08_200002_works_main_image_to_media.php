<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// main_image（文字列パス）→ main_image_id（Media Entity 参照）へ移行（ADR-0006 / Phase 2）
// restrictOnDelete により使用中 Media の削除は DB レベルでも防がれる
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('works', function (Blueprint $table) {
            $table->dropColumn('main_image');
            $table->foreignId('main_image_id')
                ->nullable()
                ->after('slug')
                ->constrained('media')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('works', function (Blueprint $table) {
            $table->dropConstrainedForeignId('main_image_id');
            $table->string('main_image')->nullable()->after('slug');
        });
    }
};
