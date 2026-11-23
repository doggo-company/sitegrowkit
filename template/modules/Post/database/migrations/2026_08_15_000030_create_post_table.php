<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * agentcms:content-type — Phase 9.7 / STAGE 20B。
 *
 * カラムは content/schemas/post.json の column-backed フィールドと一致する
 * （SchemaTableParityTest が検証する）。手で編集しないこと。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 120)->index();
            $table->string('slug', 120)->unique();
            $table->longText('body')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->dateTime('published_at')->nullable()->index();
            $table->json('data')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post');
    }
};
