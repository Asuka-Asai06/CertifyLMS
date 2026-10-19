<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;

/**
 * 受講生メモ(EnrollmentNote)に対する認可ポリシー。
 *
 * - viewAny: admin / 担当コーチのみ(受講生は閲覧不可)
 * - view: admin / 当該資格の担当コーチ(他コーチのメモも閲覧可)
 * - create: admin / 当該資格の担当コーチ
 * - update / delete: admin は全メモ、coach は自分が作成したメモのみ
 * - 親の Enrollment が論理削除済みの場合は、閲覧 / 追加 / 編集 / 削除をすべて拒否
 * - student は閲覧を含むすべての操作を拒否
 *
 * coach の担当判定は certification.coaches リレーション(certification_coach_assignments 経由)で行う。
 * メモの作成者は author_id で判定する。
 */
class EnrollmentNotePolicy
{
    public function viewAny(User $user, Enrollment $enrollment): bool
    {
        if ($enrollment->trashed()) {
            return false;
        }

        return $this->canAccessEnrollment($user, $enrollment);
    }

    public function view(User $user, EnrollmentNote $enrollmentNote): bool
    {
        $enrollment = $enrollmentNote->enrollment;

        if ($enrollment === null || $enrollment->trashed()) {
            return false;
        }

        return $this->canAccessEnrollment($user, $enrollment);
    }

    public function create(User $user, Enrollment $enrollment): bool
    {
        if ($enrollment->trashed()) {
            return false;
        }

        return $this->canAccessEnrollment($user, $enrollment);
    }

    public function update(User $user, EnrollmentNote $enrollmentNote): bool
    {
        $enrollment = $enrollmentNote->enrollment;

        if ($enrollment === null || $enrollment->trashed()) {
            return false;
        }

        if ($user->role === UserRole::Admin) {
            return true;
        }

        return $user->role === UserRole::Coach
            && $enrollmentNote->author_id === $user->id
            && $this->isAssignedCoach($user, $enrollment);
    }

    public function delete(User $user, EnrollmentNote $enrollmentNote): bool
    {
        $enrollment = $enrollmentNote->enrollment;

        if ($enrollment === null || $enrollment->trashed()) {
            return false;
        }

        if ($user->role === UserRole::Admin) {
            return true;
        }

        return $user->role === UserRole::Coach
            && $enrollmentNote->author_id === $user->id
            && $this->isAssignedCoach($user, $enrollment);
    }

    private function canAccessEnrollment(User $user, Enrollment $enrollment): bool
    {
        if ($user->role === UserRole::Admin) {
            return true;
        }

        return $user->role === UserRole::Coach
            && $this->isAssignedCoach($user, $enrollment);
    }

    private function isAssignedCoach(User $user, Enrollment $enrollment): bool
    {
        $enrollment->loadMissing('certification.coaches');

        return $enrollment->certification?->coaches
            ->contains('id', $user->id) ?? false;
    }
}
