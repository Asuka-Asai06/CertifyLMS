<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AIチャットの会話を管理するテーブル。
 *
 * 1会話に複数のメッセージが紐づく。
 * 受講生の受講登録や、会話を開始したSectionを任意で保持する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_chat_conversations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->foreignUlid('enrollment_id')
                ->nullable()
                ->constrained('enrollments')
                ->restrictOnDelete();
            $table->foreignUlid('section_id')
                ->nullable()
                ->constrained('sections')
                ->restrictOnDelete();
            $table->string('title', 100);
            $table->boolean('auto_title_enabled')->default(true);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'last_message_at']);
            $table->index(['enrollment_id', 'last_message_at']);
            $table->index('section_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chat_conversations');
    }
};
