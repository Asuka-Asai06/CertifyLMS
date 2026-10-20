<?php

declare(strict_types=1);

namespace App\UseCases\Announcement;

use App\Enums\AnnouncementTargetType;
use App\Enums\EnrollmentStatus;
use App\Models\Announcement;
use App\Models\User;
use App\Notifications\AnnouncementNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * お知らせを新規作成し、対象受講生へ通知するユースケース。
 *
 * - 配信対象に応じて受講中の受講生を取得する
 * - 配信履歴の保存と配信件数・配信日時の記録をトランザクション内で行う
 * - 配信対象と関係のない対象 ID は null として保存する
 * - DB のコミット後にデータベース通知とメール通知を送信する
 */
final class StoreAction
{
    public function __invoke(User $admin, array $validated): Announcement
    {
        return DB::transaction(function () use ($admin, $validated): Announcement {
            $targetType = AnnouncementTargetType::from(
                $validated['target_type'],
            );

            $recipients = $this->getRecipients(
                $targetType,
                $validated['target_certification_id'] ?? null,
                $validated['target_user_id'] ?? null,
            );

            $announcement = Announcement::create([
                'title' => $validated['title'],
                'body' => $validated['body'],
                'target_type' => $targetType,
                'target_certification_id' => $validated['target_certification_id'] ?? null,
                'target_user_id' => $validated['target_user_id'] ?? null,
                'dispatched_count' => $recipients->count(),
                'dispatched_at' => now(),
                'created_by' => $admin->id,
            ]);

            DB::afterCommit(function () use ($recipients, $announcement): void {
                $recipients->each(function (User $recipient) use ($announcement): void {
                    $recipient->notify(
                        new AnnouncementNotification($announcement),
                    );
                });
            });

            return $announcement;
        });
    }

    /**
     * 配信対象の受講中の受講生を取得する。
     *
     * @return Collection<int, User>
     */
    private function getRecipients(
        AnnouncementTargetType $targetType,
        ?string $certificationId,
        ?string $userId,
    ): Collection {
        return match ($targetType) {
            AnnouncementTargetType::AllStudents => User::query()
                ->inProgressStudents()
                ->get(),

            AnnouncementTargetType::Certification => User::query()
                ->inProgressStudents()
                ->whereHas('enrollments', function ($query) use ($certificationId): void {
                    $query
                        ->where('certification_id', $certificationId)
                        ->where('status', EnrollmentStatus::Learning->value);
                })
                ->get(),

            AnnouncementTargetType::User => User::query()
                ->inProgressStudents()
                ->whereKey($userId)
                ->get(),
        };
    }
}
