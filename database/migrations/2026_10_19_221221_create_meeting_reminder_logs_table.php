<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_reminder_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('meeting_id')
                ->constrained('meetings')
                ->cascadeOnDelete();

            $table->string('reminder_type', 30);

            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            // 同じ面談・同じリマインダー種別の重複登録を防止する
            $table->unique(
                ['meeting_id', 'reminder_type'],
                'meeting_reminder_logs_meeting_type_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_reminder_logs');
    }
};
