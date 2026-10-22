<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AnnouncementTargetType;
use App\Enums\EnrollmentStatus;
use App\Models\Announcement;
use App\Models\User;
use App\Notifications\AnnouncementNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * お知らせの対象者へ通知を非同期で配信するジョブ。
 *
 * - 既存のお知らせ配信条件を維持する
 * - 対象者を100人ずつ取得してメモリ使用量を抑える
 * - 各受信者への通知送信は通知キューに登録する
 */
class SendAnnouncementNotifications implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * 初回を含む最大試行回数。
     */
    public int $tries = 4;

    /**
     * 再試行時の待機時間（秒）。
     *
     * @var array<int, int>
     */
    public array $backoff = [10, 30, 60];

    /**
     * 配信対象のお知らせID。
     */
    public function __construct(
        public string $announcementId,
    ) {}

    /**
     * お知らせの対象者を取得し、通知をキューに登録する。
     */
    public function handle(): void
    {
        $announcement = Announcement::query()->findOrFail(
            $this->announcementId,
        );

        $targetType = $announcement->target_type;

        $query = match ($targetType) {
            AnnouncementTargetType::AllStudents => User::query()
                ->inProgressStudents(),

            AnnouncementTargetType::Certification => User::query()
                ->inProgressStudents()
                ->whereHas('enrollments', function ($query) use ($announcement): void {
                    $query
                        ->where(
                            'certification_id',
                            $announcement->target_certification_id,
                        )
                        ->where(
                            'status',
                            EnrollmentStatus::Learning->value,
                        );
                }),

            AnnouncementTargetType::User => User::query()
                ->inProgressStudents()
                ->whereKey($announcement->target_user_id),
        };

        $query->chunkById(100, function ($recipients) use ($announcement): void {
            foreach ($recipients as $recipient) {
                $recipient->notify(
                    new AnnouncementNotification($announcement),
                );
            }
        });
    }
}
