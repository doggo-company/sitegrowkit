<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 既存の記事本文はそのままに、任意のアイキャッチ画像と抜粋を追加する。
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news', function (Blueprint $table): void {
            $table->foreignId('main_image_id')->nullable()->constrained('media')->nullOnDelete();
            $table->text('excerpt')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('news', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('main_image_id');
            $table->dropColumn('excerpt');
        });
    }
};
