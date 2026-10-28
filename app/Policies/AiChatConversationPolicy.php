<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\EnrollmentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AiChatConversation;
use App\Models\User;

/**
 * AIチャット会話(AiChatConversation)に対する認可ポリシー。
 *
 * - viewAny / create: 学習中の受講生のみ許可
 * - view / update / delete / sendMessage: 学習中の受講生かつ会話の所有者のみ許可
 * - admin / coach はすべての操作を拒否
 * - 会話に受講登録が紐づく場合、本人の学習中の受講登録であることを確認
 *
 * 受講生のロール・学習状態は User の role / status で判定する。
 * 会話の所有者は user_id で判定する。
 */
class AiChatConversationPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canUseAiChat($user);
    }

    public function create(User $user): bool
    {
        return $this->canUseAiChat($user);
    }

    public function view(User $user, AiChatConversation $conversation): bool
    {
        return $this->canAccessConversation($user, $conversation);
    }

    public function update(User $user, AiChatConversation $conversation): bool
    {
        return $this->canAccessConversation($user, $conversation);
    }

    public function delete(User $user, AiChatConversation $conversation): bool
    {
        return $this->canAccessConversation($user, $conversation);
    }

    public function sendMessage(User $user, AiChatConversation $conversation): bool
    {
        return $this->canAccessConversation($user, $conversation);
    }

    private function canUseAiChat(User $user): bool
    {
        return $user->role === UserRole::Student
            && $user->status === UserStatus::InProgress;
    }

    private function canAccessConversation(User $user, AiChatConversation $conversation): bool
    {
        if (! $this->canUseAiChat($user)) {
            return false;
        }

        if ($conversation->user_id !== $user->id) {
            return false;
        }

        if ($conversation->enrollment_id === null) {
            return true;
        }

        $enrollment = $conversation->enrollment;

        if ($enrollment === null || $enrollment->trashed()) {
            return false;
        }

        return $enrollment->user_id === $user->id
            && $enrollment->status === EnrollmentStatus::Learning;
    }
}
