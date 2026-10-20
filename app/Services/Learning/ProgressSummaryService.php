<?php

declare(strict_types=1);

namespace App\Services\Learning;

use App\Enums\ContentStatus;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 学習進捗（Section / Chapter / Part / 資格）の集計を担当する Service。
 *
 * 完了判定や集計対象は既存実装を維持する。
 */
final class ProgressSummaryService
{
    public function summarize(Enrollment $enrollment): ProgressSummary
    {
        $totals = $this->fetchSectionTotals($enrollment);

        $partsTotal = Part::query()
            ->where('certification_id', $enrollment->certification_id)
            ->where('status', ContentStatus::Published->value)
            ->count();

        $chaptersTotal = Chapter::query()
            ->whereHas('part', function ($query) use ($enrollment): void {
                $query->where('certification_id', $enrollment->certification_id)
                    ->where('status', ContentStatus::Published->value);
            })
            ->where('status', ContentStatus::Published->value)
            ->count();

        $sectionsTotal = (int) $totals->sections_total;
        $sectionsCompleted = (int) $totals->sections_completed;

        $sectionRatio = $sectionsTotal === 0
            ? 0.0
            : round($sectionsCompleted / $sectionsTotal, 4);

        $chaptersCompleted = $this->countCompletedChapters($enrollment);
        $partsCompleted = $this->countCompletedParts($enrollment);

        $chapterRatio = $chaptersTotal === 0
            ? 0.0
            : round($chaptersCompleted / $chaptersTotal, 4);

        $partRatio = $partsTotal === 0
            ? 0.0
            : round($partsCompleted / $partsTotal, 4);

        return new ProgressSummary(
            sectionsTotal: $sectionsTotal,
            sectionsCompleted: $sectionsCompleted,
            sectionCompletionRatio: $sectionRatio,
            chaptersTotal: $chaptersTotal,
            chaptersCompleted: $chaptersCompleted,
            chapterCompletionRatio: $chapterRatio,
            partsTotal: $partsTotal,
            partsCompleted: $partsCompleted,
            partCompletionRatio: $partRatio,
            overallCompletionRatio: $sectionRatio,
        );
    }

    /**
     * @return object{sections_total: int|string, sections_completed: int|string}
     */
    private function fetchSectionTotals(Enrollment $enrollment): object
    {
        return DB::table('sections')
            ->join('chapters', 'chapters.id', '=', 'sections.chapter_id')
            ->join('parts', 'parts.id', '=', 'chapters.part_id')
            ->leftJoin('section_progresses', function ($join) use ($enrollment): void {
                $join->on('section_progresses.section_id', '=', 'sections.id')
                    ->where('section_progresses.enrollment_id', '=', $enrollment->id);
            })
            ->where('parts.certification_id', $enrollment->certification_id)
            ->where('parts.status', ContentStatus::Published->value)
            ->where('chapters.status', ContentStatus::Published->value)
            ->where('sections.status', ContentStatus::Published->value)
            ->selectRaw(
                'COUNT(sections.id) AS sections_total, COUNT(section_progresses.id) AS sections_completed',
            )
            ->first() ?? (object) [
                'sections_total' => 0,
                'sections_completed' => 0,
            ];
    }

    private function countCompletedChapters(Enrollment $enrollment): int
    {
        $rows = DB::table('chapters')
            ->join('parts', 'parts.id', '=', 'chapters.part_id')
            ->leftJoin('sections', function ($join): void {
                $join->on('sections.chapter_id', '=', 'chapters.id')
                    ->where('sections.status', ContentStatus::Published->value);
            })
            ->leftJoin('section_progresses', function ($join) use ($enrollment): void {
                $join->on('section_progresses.section_id', '=', 'sections.id')
                    ->where('section_progresses.enrollment_id', '=', $enrollment->id);
            })
            ->where('parts.certification_id', $enrollment->certification_id)
            ->where('parts.status', ContentStatus::Published->value)
            ->where('chapters.status', ContentStatus::Published->value)
            ->groupBy('chapters.id')
            ->selectRaw(
                'chapters.id AS chapter_id, COUNT(sections.id) AS total, COUNT(section_progresses.id) AS done',
            )
            ->get();

        $completed = 0;

        foreach ($rows as $row) {
            if ((int) $row->total > 0 && (int) $row->total === (int) $row->done) {
                $completed++;
            }
        }

        return $completed;
    }

    private function countCompletedParts(Enrollment $enrollment): int
    {
        $rows = DB::table('parts')
            ->leftJoin('chapters', function ($join): void {
                $join->on('chapters.part_id', '=', 'parts.id')
                    ->where('chapters.status', ContentStatus::Published->value);
            })
            ->leftJoin('sections', function ($join): void {
                $join->on('sections.chapter_id', '=', 'chapters.id')
                    ->where('sections.status', ContentStatus::Published->value);
            })
            ->leftJoin('section_progresses', function ($join) use ($enrollment): void {
                $join->on('section_progresses.section_id', '=', 'sections.id')
                    ->where('section_progresses.enrollment_id', '=', $enrollment->id);
            })
            ->where('parts.certification_id', $enrollment->certification_id)
            ->where('parts.status', ContentStatus::Published->value)
            ->groupBy('parts.id')
            ->selectRaw(
                'parts.id AS part_id, COUNT(sections.id) AS total, COUNT(section_progresses.id) AS done',
            )
            ->get();

        $completed = 0;

        foreach ($rows as $row) {
            if ((int) $row->total > 0 && (int) $row->total === (int) $row->done) {
                $completed++;
            }
        }

        return $completed;
    }

    /**
     * ダッシュボード向けに、複数 Enrollment の Section 完了率をまとめて算出する。
     *
     * @param Collection<int, Enrollment> $enrollments
     *
     * @return array<string, float>
     */
    public function batchSectionCompletionRatios(Collection $enrollments): array
    {
        if ($enrollments->isEmpty()) {
            return [];
        }

        $enrollmentIds = $enrollments->pluck('id')->all();
        $certificationIds = $enrollments->pluck('certification_id')->unique()->values()->all();

        $rows = DB::table('sections')
            ->join('chapters', 'chapters.id', '=', 'sections.chapter_id')
            ->join('parts', 'parts.id', '=', 'chapters.part_id')
            ->join('enrollments', 'enrollments.certification_id', '=', 'parts.certification_id')
            ->leftJoin('section_progresses', function ($join): void {
                $join->on('section_progresses.section_id', '=', 'sections.id')
                    ->on('section_progresses.enrollment_id', '=', 'enrollments.id');
            })
            ->whereIn('enrollments.id', $enrollmentIds)
            ->whereIn('parts.certification_id', $certificationIds)
            ->where('parts.status', ContentStatus::Published->value)
            ->where('chapters.status', ContentStatus::Published->value)
            ->where('sections.status', ContentStatus::Published->value)
            ->groupBy('enrollments.id')
            ->selectRaw(
                'enrollments.id AS enrollment_id, COUNT(sections.id) AS total, COUNT(section_progresses.id) AS done',
            )
            ->get();

        $result = [];

        foreach ($enrollmentIds as $id) {
            $result[(string) $id] = 0.0;
        }

        foreach ($rows as $row) {
            $total = (int) $row->total;
            $done = (int) $row->done;

            $result[(string) $row->enrollment_id] = $total === 0
                ? 0.0
                : round($done / $total, 4);
        }

        return $result;
    }
}
