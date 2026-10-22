<?php

declare(strict_types=1);

namespace App\UseCases\Announcement;

use App\Enums\AnnouncementTargetType;
use App\Enums\EnrollmentStatus;
use App\Jobs\SendAnnouncementNotifications;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class StoreAction
{
    /**
     * お知らせを作成し、コミット後に非同期配信を開始する。
     *
     * @param array{
     *     title: string,
     *     body: string,
     *     target_type: string,
     *     target_certification_id?: string|null,
     *     target_user_id?: string|null
     * } $validated
     */
    public function __invoke(User $admin, array $validated): Announcement
    {
        return DB::transaction(function () use (
            $admin,
            $validated,
        ): Announcement {
            $targetType = AnnouncementTargetType::from(
                $validated['target_type'],
            );

            $targetCertificationId =
                $validated['target_certification_id'] ?? null;

            $targetUserId = $validated['target_user_id'] ?? null;

            $dispatchedCount = $this->getRecipientsQuery(
                $targetType,
                $targetCertificationId,
                $targetUserId,
            )->count();

            $announcement = Announcement::create([
                'title' => $validated['title'],
                'body' => $validated['body'],
                'target_type' => $targetType,
                'target_certification_id' => $targetCertificationId,
                'target_user_id' => $targetUserId,
                'dispatched_count' => $dispatchedCount,
                'dispatched_at' => now(),
                'created_by' => $admin->id,
            ]);

            DB::afterCommit(function () use ($announcement): void {
                SendAnnouncementNotifications::dispatch(
                    $announcement->id,
                );
            });

            return $announcement;
        });
    }

    /**
     * お知らせの配信対象となるユーザーのクエリを作成する。
     *
     * @return Builder<User>
     */
    private function getRecipientsQuery(
        AnnouncementTargetType $targetType,
        ?string $certificationId,
        ?string $userId,
    ): Builder {
        return match ($targetType) {
            AnnouncementTargetType::AllStudents => User::query()
                ->inProgressStudents(),

            AnnouncementTargetType::Certification => User::query()
                ->inProgressStudents()
                ->whereHas('enrollments', function ($query) use ($certificationId): void {
                    $query
                        ->where('certification_id', $certificationId)
                        ->where(
                            'status',
                            EnrollmentStatus::Learning->value,
                        );
                }),

            AnnouncementTargetType::User => User::query()
                ->inProgressStudents()
                ->whereKey($userId),
        };
    }
}
