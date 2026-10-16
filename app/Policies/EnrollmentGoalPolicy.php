<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;

/**
 * 個人目標に対する認可ポリシー。
 *
 * - view: 受講生本人 / 当該資格の担当コーチ / admin
 * - create: 受講中の受講生本人(自己の受講登録に対する目標作成)
 * - update / delete: 受講中の受講生本人(自己の目標のみ)
 * - markAchieved / unmarkAchieved: 受講中の受講生本人(自己の目標のみ)
 *
 * コーチは担当資格に登録された受講生の目標を閲覧できるが、変更操作はできない。
 * admin は全受講生の目標を閲覧できるが、変更操作はできない。
 *
 * coach の判定は certification.coaches リレーション(certification_coach_assignments 経由)で行う。
 *
 * 親enrollmentが論理削除されている場合は操作できない。
 */
class EnrollmentGoalPolicy
{
    public function view(User $user, EnrollmentGoal $goal): bool
    {
        $enrollment = $goal->enrollment;

        if ($enrollment === null || $enrollment->trashed()) {
            return false;
        }

        return match ($user->role) {
            UserRole::Admin => true,
            UserRole::Coach => $this->isAssignedCoach($enrollment, $user),
            UserRole::Student => $this->isActiveStudent($user)
                && $enrollment->user_id === $user->id,
            default => false,
        };
    }

    public function create(User $user, Enrollment $enrollment): bool
    {
        return ! $enrollment->trashed()
            && $this->isActiveStudent($user)
            && $enrollment->user_id === $user->id;
    }

    public function update(User $user, EnrollmentGoal $goal): bool
    {
        return $this->isOwner($user, $goal);
    }

    public function delete(User $user, EnrollmentGoal $goal): bool
    {
        return $this->isOwner($user, $goal);
    }

    public function markAchieved(User $user, EnrollmentGoal $goal): bool
    {
        return $this->isOwner($user, $goal);
    }

    public function unmarkAchieved(User $user, EnrollmentGoal $goal): bool
    {
        return $this->isOwner($user, $goal);
    }

    private function isOwner(User $user, EnrollmentGoal $goal): bool
    {
        $enrollment = $goal->enrollment;

        return $this->isActiveStudent($user)
            && $enrollment !== null
            && ! $enrollment->trashed()
            && $enrollment->user_id === $user->id;
    }

    private function isActiveStudent(User $user): bool
    {
        return $user->role === UserRole::Student
            && $user->status === UserStatus::InProgress;
    }

    private function isAssignedCoach(?Enrollment $enrollment, User $coach): bool
    {
        if ($coach->role !== UserRole::Coach || $enrollment === null) {
            return false;
        }

        $enrollment->loadMissing('certification.coaches');

        return $enrollment->certification?->coaches->contains('id', $coach->id)
            ?? false;
    }
}
