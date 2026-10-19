<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 管理者お知らせの配信対象タイプを表す Enum。
 *
 * - AllStudents: 受講中の全受講生
 * - Certification: 指定資格に受講登録している受講生
 * - User: 指定した受講生 1 名
 *
 * 配信対象の判定は、お知らせ配信処理側で行う。
 */
enum AnnouncementTargetType: string
{
    case AllStudents = 'all_students';
    case Certification = 'certification';
    case User = 'user';

    public function label(): string
    {
        return match ($this) {
            self::AllStudents => '全受講生',
            self::Certification => '資格指定',
            self::User => 'ユーザー指定',
        };
    }
}
