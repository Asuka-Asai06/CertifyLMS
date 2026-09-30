<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CertificationStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\QaThread;
use App\Models\User;

class QaThreadPolicy
{
    /**
     * 質問掲示板の認可ポリシー。
     *
     * - admin: 全質問を閲覧・削除できる。
     * - coach: 担当中かつ公開中の資格に紐づく質問を閲覧できる。
     * - student: 公開中の資格に紐づく質問を閲覧できる。
     *
     * @param User $user 認証済みユーザー
     * @param QaThread $thread 閲覧対象の質問
     */
    public function view(User $user, QaThread $thread): bool
    {
        if (! $this->isActiveUser($user)) {
            return false;
        }

        return match ($user->role) {
            UserRole::Admin => true,

            UserRole::Coach => $thread->certification
                ->coaches()
                ->where('users.id', $user->id)
                ->exists()
                && $thread->certification->status === CertificationStatus::Published,

            UserRole::Student => $thread->certification->status
                === CertificationStatus::Published,
        };
    }

    public function create(User $user): bool
    {
        return $this->isActiveUser($user)
            && $user->role === UserRole::Student;
    }

    public function update(User $user, QaThread $thread): bool
    {
        return $this->isActiveUser($user)
            && $user->role === UserRole::Student
            && $thread->user_id === $user->id;
    }

    public function delete(User $user, QaThread $thread): bool
    {
        if (! $this->isActiveUser($user)) {
            return false;
        }

        return $user->role === UserRole::Admin
            || (
                $user->role === UserRole::Student
                && $thread->user_id === $user->id
            );
    }

    public function resolve(User $user, QaThread $thread): bool
    {
        return $this->isActiveUser($user)
            && $user->role === UserRole::Student
            && $thread->user_id === $user->id;
    }

    public function unresolve(User $user, QaThread $thread): bool
    {
        return $this->isActiveUser($user)
            && $user->role === UserRole::Student
            && $thread->user_id === $user->id;
    }

    /**
     * Q&Aを利用できるアクティブユーザーか判定する。
     *
     * @param User $user 認証済みユーザー
     */
    private function isActiveUser(User $user): bool
    {
        return $user->status === UserStatus::InProgress;
    }
}
