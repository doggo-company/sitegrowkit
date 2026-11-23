<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 編集可能フィールド（name/alt）は content/schemas/media.json、Entity 設計は docs/adr/0006 参照
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('alt', 200)->nullable();
            // 以下はシステム管理（ユーザーに入力させない — Phase 2 仕様）
            $table->string('path');
            $table->string('mime', 50);
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->boolean('has_webp')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
