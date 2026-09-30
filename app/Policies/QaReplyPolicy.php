<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CertificationStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;

/**
 * 質問掲示板の返信に対する認可ポリシー。
 *
 * - admin: 全返信を閲覧・削除できる。
 * - coach: 担当中かつ公開中の資格に紐づく返信をCRUD操作できる。
 * - student: 公開中の資格に紐づく返信をCRUD操作できる。
 */
class QaReplyPolicy
{
    public function view(User $user, QaReply $reply): bool
    {
        if (! $this->isActiveUser($user)) {
            return false;
        }

        $thread = $reply->thread;
        $certification = $thread->certification;

        return match ($user->role) {
            UserRole::Admin => true,

            UserRole::Coach => $certification->status === CertificationStatus::Published
                && $certification->coaches()
                    ->where('users.id', $user->id)
                    ->exists(),

            UserRole::Student => $certification->status
                === CertificationStatus::Published,
        };
    }

    /**
     * 返信を作成できるか。
     *
     * 学生とコーチが返信できる。
     * 管理者は返信できない。
     *
     * 学生は公開中の資格に紐づく質問、
     * コーチは担当中かつ公開中の資格に紐づく質問に返信できる。
     *
     * @param User $user 認証済みユーザー
     * @param QaThread $thread 返信対象の質問
     */
    public function create(User $user, QaThread $thread): bool
    {
        if (! $this->isActiveUser($user)) {
            return false;
        }

        if (! in_array($user->role, [UserRole::Student, UserRole::Coach], true)) {
            return false;
        }

        if ($thread->certification->status !== CertificationStatus::Published) {
            return false;
        }

        if ($user->role === UserRole::Coach) {
            return $thread->certification
                ->coaches
                ->contains('id', $user->id);
        }

        return true;
    }

    /**
     * 返信を編集できるか。
     *
     * 返信を作成した本人のみ編集できる。
     * 管理者は返信を編集できない。
     *
     * @param User $user 認証済みユーザー
     * @param QaReply $reply 編集対象の返信
     */
    public function update(User $user, QaReply $reply): bool
    {
        return $this->isActiveUser($user)
            && in_array(
                $user->role,
                [UserRole::Student, UserRole::Coach],
                true,
            )
            && $reply->user_id === $user->id;
    }

    /**
     * 返信を削除できるか。
     *
     * 返信を作成した本人、または管理者が削除できる。
     *
     * @param User $user 認証済みユーザー
     * @param QaReply $reply 削除対象の返信
     */
    public function delete(User $user, QaReply $reply): bool
    {
        if (! $this->isActiveUser($user)) {
            return false;
        }

        return $user->role === UserRole::Admin
            || (
                in_array(
                    $user->role,
                    [UserRole::Student, UserRole::Coach],
                    true,
                )
                && $reply->user_id === $user->id
            );
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
