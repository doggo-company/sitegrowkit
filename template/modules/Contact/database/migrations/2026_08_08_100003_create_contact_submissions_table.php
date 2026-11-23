<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 入力フィールドは content/schemas/contact.json、状態設計は docs/adr/0005 参照
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_submissions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('email');
            $table->string('company', 120)->nullable();
            $table->string('subject', 40);
            $table->text('message');
            // 対応の進行状態（通知の成否とは別軸 — ADR-0005）
            $table->string('status', 20)->default('received')->index();
            $table->dateTime('admin_notified_at')->nullable();
            $table->string('admin_notification_error', 500)->nullable();
            $table->dateTime('autoreply_sent_at')->nullable();
            $table->string('autoreply_error', 500)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_submissions');
    }
};
