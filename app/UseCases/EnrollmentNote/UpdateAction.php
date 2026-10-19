<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentNote;

use App\Models\EnrollmentNote;

/**
 * EnrollmentNote のメモ本文を UPDATE する Action。
 *
 * - 指定されたメモの本文を更新する
 * - 作成者や受講登録など、本文以外の情報は変更しない
 */
class UpdateAction
{
    /**
     * 受講生メモを更新する。
     *
     * @param array<string, mixed> $data
     */
    public function __invoke(EnrollmentNote $note, array $data): EnrollmentNote
    {
        $note->update([
            'body' => $data['body'],
        ]);

        return $note;
    }
}
