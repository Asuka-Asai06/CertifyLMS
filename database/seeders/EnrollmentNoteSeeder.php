<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

final class EnrollmentNoteSeeder extends Seeder
{
    /**
     * 開発用の受講メモを作成する。
     *
     * 既存の受講登録ごとに、担当コーチ・他コーチ・管理者の
     * メモを作成し、閲覧・編集・削除権限を確認できるようにする。
     */
    public function run(): void
    {
        $admin = User::query()
            ->where('email', 'admin@certify-lms.test')
            ->first();

        $coaches = User::query()
            ->whereIn('email', [
                'coach@certify-lms.test',
                'coach2@certify-lms.test',
            ])
            ->get()
            ->keyBy('email');

        if (
            $admin === null
            || ! $coaches->has('coach@certify-lms.test')
            || ! $coaches->has('coach2@certify-lms.test')
        ) {
            $this->command?->warn(
                'EnrollmentNoteSeeder: 固定管理者または固定コーチが見つからないため、処理を中止しました。',
            );

            return;
        }

        /** @var Collection<int, Enrollment> $enrollments */
        $enrollments = Enrollment::query()
            ->with(['user', 'certification.coaches'])
            ->get();

        foreach ($enrollments as $enrollment) {
            $this->createNotes(
                $enrollment,
                $admin,
                $coaches->values(),
            );
        }
    }

    /**
     * 受講登録に3件のメモを作成する。
     *
     * @param Collection<int, User> $coaches
     */
    private function createNotes(
        Enrollment $enrollment,
        User $admin,
        Collection $coaches,
    ): void {
        $assignedCoachIds = $enrollment->certification
            ->coaches
            ->pluck('id')
            ->all();

        $authors = $coaches
            ->sortBy(
                fn (User $coach): int => in_array(
                    $coach->id,
                    $assignedCoachIds,
                    true,
                ) ? 0 : 1,
            )
            ->push($admin)
            ->unique('id')
            ->values();

        $bodies = [
            '進捗を確認しました。次回までに学習内容を復習するよう指示してください。',
            '学習状況を確認しました。理解度を次回の面談で確認します。',
            '今後の学習計画について、引き続き進捗を確認します。',
        ];

        foreach ($bodies as $index => $body) {
            $author = $authors->get($index);

            if ($author === null) {
                continue;
            }

            // enrollment_id と本文の組み合わせで重複作成を防ぐ。
            EnrollmentNote::query()->firstOrCreate(
                [
                    'enrollment_id' => $enrollment->id,
                    'body' => $body,
                ],
                [
                    'author_id' => $author->id,
                ],
            );
        }
    }
}
