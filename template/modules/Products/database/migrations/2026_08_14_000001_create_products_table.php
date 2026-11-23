<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * 商品（Phase 9.2B の Custom Fields 実証用 Content Type）。
 *
 * カラムは content/schemas/product.json の **column-backed フィールド**と一致する
 * （SchemaTableParityTest が検証）。group / repeater / images / relations は
 * `data` JSON へ入るのでカラムを持たない（owner 決定 G2 = Hybrid）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 120);
            $table->string('slug', 120)->unique();
            $table->string('summary', 300)->nullable();
            $table->longText('description')->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->date('release_date')->nullable();
            $table->boolean('featured')->default(false);
            $table->string('product_type', 20)->nullable();
            $table->string('size', 20)->nullable();
            $table->json('tags')->nullable();
            $table->string('shop_url', 255)->nullable();
            $table->string('contact_email', 255)->nullable();
            $table->string('tel', 20)->nullable();
            $table->string('featured_image', 255)->nullable();
            $table->unsignedBigInteger('manual')->nullable();
            $table->unsignedBigInteger('related_work')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->dateTime('published_at')->nullable()->index();
            $table->string('seo_title', 120)->nullable();

            // 複合フィールド（group / repeater / images / relations）だけがここへ入る
            $table->json('data')->nullable();

            $table->timestamps();

            $table->foreign('manual')->references('id')->on('media')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
