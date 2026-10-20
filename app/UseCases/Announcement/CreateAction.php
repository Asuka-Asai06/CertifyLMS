<?php

declare(strict_types=1);

namespace App\UseCases\Announcement;

use App\Enums\CertificationStatus;
use App\Models\Certification;
use App\Models\User;

/**
 * お知らせ配信フォーム用の資格・受講生一覧を取得するユースケース。
 * 資格は公開中のみ、受講生は受講中のみを対象とし、どちらも名前の昇順で取得する。
 *
 * */
final class CreateAction
{
    public function __invoke(): array
    {
        $certifications = Certification::query()
            ->where('status', CertificationStatus::Published->value)
            ->orderBy('name')
            ->get();

        $students = User::query()
            ->inProgressStudents()
            ->orderBy('name')
            ->get();

        return [
            'certifications' => $certifications,
            'students' => $students,
        ];
    }
}
