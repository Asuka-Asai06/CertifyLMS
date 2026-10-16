<?php

declare(strict_types=1);

namespace App\UseCases\Settings;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Arr;

/**
 * プロフィールを更新する Action
 */
final class UpdateProfileAction
{
    /**
     * @param array<string, mixed> $data
     */
    public function __invoke(User $user, array $data): void
    {
        $attributes = Arr::only($data, [
            'name',
            'bio',
        ]);

        // 面談URLはコーチ本人のプロフィールに限って更新する。
        if ($user->role === UserRole::Coach && array_key_exists('meeting_url', $data)) {
            $attributes['meeting_url'] = $data['meeting_url'];
        }

        $user->update($attributes);
    }
}
