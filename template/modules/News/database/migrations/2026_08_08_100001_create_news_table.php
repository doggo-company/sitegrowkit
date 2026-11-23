<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// カラムは content/schemas/news.json と一致していること（SchemaTableParityTest が検証）
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->string('slug', 120)->unique();
            $table->longText('body');
            $table->string('status', 20)->default('draft')->index();
            $table->dateTime('published_at')->nullable()->index();
            $table->string('seo_title', 120)->nullable();
            $table->string('seo_description', 200)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news');
    }
};
