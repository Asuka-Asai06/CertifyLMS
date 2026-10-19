<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentNote;

use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;

/**
 * EnrollmentNote に受講生メモを INSERT する Action。
 *
 * - 作成者にはログイン中の User を設定する
 * - メモ本文を登録する
 */
class StoreAction
{
    public function __invoke(
        Enrollment $enrollment,
        User $author,
        array $data,
    ): EnrollmentNote {
        return $enrollment->notes()->create([
            'author_id' => $author->id,
            'body' => $data['body'],
        ]);
    }
}
