<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MeetingStatus;
use App\Enums\UserStatus;
use App\Models\Meeting;
use App\Models\MeetingReminderLog;
use App\Notifications\MeetingReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SendMeetingRemindersCommand extends Command
{
    protected $signature = 'notifications:send-meeting-reminders
                            {--window= : Reminder window (eve or one_hour_before)}';

    protected $description = '予約済み面談のリマインダー通知を送信する';

    /**
     * リマインダー対象の面談を検索し、通知を送信する。
     */
    public function handle(): int
    {
        $window = $this->option('window');

        $hoursBefore = match ($window) {
            'eve' => 24,
            'one_hour_before' => 1,
            default => null,
        };

        if ($hoursBefore === null) {
            $this->error(
                'オプション --window には eve または one_hour_before を指定してください。'
            );

            return self::FAILURE;
        }

        // 定期実行の間隔を考慮し、目標時刻までの5分間を対象にする
        $now = now();
        $targetTime = $now->copy()->addHours($hoursBefore);
        $windowStart = $targetTime->copy()->subMinutes(5);

        $count = 0;

        Meeting::query()
            ->with(['student', 'coach'])
            ->where('status', MeetingStatus::Reserved->value)
            ->whereBetween('scheduled_at', [$windowStart, $targetTime])
            ->orderBy('scheduled_at')
            ->chunkById(100, function ($meetings) use ($window, &$count): void {
                foreach ($meetings as $meeting) {
                    // 同じ面談・通知種別の重複処理を防ぐ
                    $now = now();

                    $inserted = MeetingReminderLog::query()->insertOrIgnore([
                        'id' => (string) Str::ulid(),
                        'meeting_id' => $meeting->id,
                        'reminder_type' => $window,
                        'sent_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    if ($inserted === 0) {
                        continue;
                    }

                    // コーチは UserStatus に関係なく通知する
                    $meeting->coach->notify(
                        new MeetingReminderNotification($meeting, $window)
                    );

                    // 受講生は受講中の場合のみ通知する
                    if ($meeting->student->status === UserStatus::InProgress) {
                        $meeting->student->notify(
                            new MeetingReminderNotification($meeting, $window)
                        );
                    }

                    // 通知処理が完了した時刻を記録する
                    MeetingReminderLog::query()
                        ->where('meeting_id', $meeting->id)
                        ->where('reminder_type', $window)
                        ->update([
                            'sent_at' => now(),
                            'updated_at' => now(),
                        ]);

                    $count++;
                }
            });

        $this->info("{$window} のリマインダーを {$count} 件処理しました。");

        return self::SUCCESS;
    }
}
