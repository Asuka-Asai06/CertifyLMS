<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AIチャットのメッセージを管理するテーブル。
 *
 * ユーザーの質問とAIの回答を保存する。
 * AIの処理状態や運用上のメタデータも保持する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_chat_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('conversation_id')
                ->constrained('ai_chat_conversations')
                ->cascadeOnDelete();
            $table->string('role', 20);
            $table->text('content');
            $table->string('status', 20)->default('completed');
            $table->text('error_detail')->nullable();
            $table->string('model_name', 100)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
            $table->index(['role', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chat_messages');
    }
};
