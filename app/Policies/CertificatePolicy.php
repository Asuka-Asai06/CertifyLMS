<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Certificate;
use App\Models\User;

/**
 * 修了証の認可ルール。
 *
 * - admin: すべての修了証をダウンロード可能
 * - coach: 担当資格の修了証のみダウンロード可能
 * - student: 自分の修了証のみダウンロード可能
 */
class CertificatePolicy
{
    /**
     * 修了証 PDF のダウンロードを許可するか判定する。
     */
    public function download(User $auth, Certificate $certificate): bool
    {
        return match ($auth->role) {
            UserRole::Admin => true,

            UserRole::Coach => $certificate->certification
                ->coaches
                ->contains('id', $auth->id),

            UserRole::Student => $certificate->user_id === $auth->id,

            default => false,
        };
    }
}
