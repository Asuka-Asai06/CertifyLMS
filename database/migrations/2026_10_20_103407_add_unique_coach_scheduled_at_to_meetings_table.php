<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->string('active_coach_id')
                ->nullable()
                ->storedAs(
                    "CASE WHEN status IN ('reserved', 'completed') THEN coach_id ELSE NULL END"
                );

            $table->dateTime('active_scheduled_at')
                ->nullable()
                ->storedAs(
                    "CASE WHEN status IN ('reserved', 'completed') THEN scheduled_at ELSE NULL END"
                );

            $table->unique(
                ['active_coach_id', 'active_scheduled_at'],
                'meetings_active_coach_scheduled_at_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropUnique('meetings_active_coach_scheduled_at_unique');
            $table->dropColumn(['active_coach_id', 'active_scheduled_at']);
        });
    }
};