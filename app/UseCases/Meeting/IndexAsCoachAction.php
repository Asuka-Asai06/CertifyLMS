<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Models\Meeting;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * コーチ宛の面談一覧取得Action。
 * filter (upcoming / past / all) に応じて面談一覧を取得する。
 */
final class IndexAsCoachAction
{
    /**
     * @param array{
     *     filter?: string|null,
     *     student?: string|null,
     *     enrollment?: string|null
     * } $filters
     *
     * @return array{
     *     meetings: LengthAwarePaginator,
     *     filter: string,
     *     studentFilter: string|null,
     *     enrollmentFilter: string|null
     * }
     */
    public function __invoke(User $coach, array $filters): array
    {
        $filter = $filters['filter'] ?? 'upcoming';
        $studentId = $filters['student'] ?? null;
        $enrollmentId = $filters['enrollment'] ?? null;

        $query = Meeting::query()
            ->with(['enrollment.certification', 'student'])
            ->forCoach($coach)
            ->when(
                $studentId,
                fn ($query, $id) => $query->where('student_id', $id),
            )
            ->when(
                $enrollmentId,
                fn ($query, $id) => $query->where('enrollment_id', $id),
            );

        // upcoming は次の面談を上に、past / all は直近の活動を上に表示する。
        $meetings = match ($filter) {
            'past' => $query->past()
                ->orderByDesc('scheduled_at')
                ->paginate(20),
            'all' => $query
                ->orderByDesc('scheduled_at')
                ->paginate(20),
            default => $query->upcoming()
                ->orderBy('scheduled_at')
                ->paginate(20),
        };

        return [
            'meetings' => $meetings,
            'filter' => $filter,
            'studentFilter' => $studentId,
            'enrollmentFilter' => $enrollmentId,
        ];
    }
}
