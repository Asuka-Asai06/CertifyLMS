<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\ContentStatus;
use App\Enums\EnrollmentStatus;
use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Models\Section;
use App\Models\SectionProgress;
use App\Models\User;
use App\Services\Learning\ProgressSummaryService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProgressSummaryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_summarize_calculates_section_chapter_part_and_overall_progress(): void
    {
        $enrollment = $this->createEnrollment();

        $part = $this->createPublishedPart($enrollment->certification_id);

        $chapterA = $this->createPublishedChapter($part);
        $sectionA1 = $this->createPublishedSection($chapterA);
        $this->createPublishedSection($chapterA);

        $chapterB = $this->createPublishedChapter($part);
        $sectionB1 = $this->createPublishedSection($chapterB);

        // Chapter A は一部のみ完了、Chapter B はすべて完了。
        $this->markCompleted($enrollment, $sectionA1);
        $this->markCompleted($enrollment, $sectionB1);

        $summary = app(ProgressSummaryService::class)->summarize($enrollment);

        $this->assertSame(3, $summary->sectionsTotal);
        $this->assertSame(2, $summary->sectionsCompleted);
        $this->assertSame(0.6667, $summary->sectionCompletionRatio);

        $this->assertSame(2, $summary->chaptersTotal);
        $this->assertSame(1, $summary->chaptersCompleted);
        $this->assertSame(0.5, $summary->chapterCompletionRatio);

        $this->assertSame(1, $summary->partsTotal);
        $this->assertSame(0, $summary->partsCompleted);
        $this->assertSame(0.0, $summary->partCompletionRatio);

        $this->assertSame(
            $summary->sectionCompletionRatio,
            $summary->overallCompletionRatio,
        );
    }

    public function test_summarize_counts_only_published_content(): void
    {
        $enrollment = $this->createEnrollment();

        $part = $this->createPublishedPart($enrollment->certification_id);
        $chapter = $this->createPublishedChapter($part);
        $publishedSection = $this->createPublishedSection($chapter);

        Section::factory()->for($chapter)->create([
            'status' => 'draft',
        ]);

        $this->markCompleted($enrollment, $publishedSection);

        $summary = app(ProgressSummaryService::class)->summarize($enrollment);

        $this->assertSame(1, $summary->sectionsTotal);
        $this->assertSame(1, $summary->sectionsCompleted);
        $this->assertSame(1.0, $summary->sectionCompletionRatio);

        $this->assertSame(1, $summary->chaptersTotal);
        $this->assertSame(1, $summary->chaptersCompleted);

        $this->assertSame(1, $summary->partsTotal);
        $this->assertSame(1, $summary->partsCompleted);
    }

    public function test_summarize_returns_zero_ratios_when_no_sections_exist(): void
    {
        $enrollment = $this->createEnrollment();

        $this->createPublishedPart($enrollment->certification_id);

        $summary = app(ProgressSummaryService::class)->summarize($enrollment);

        $this->assertSame(0, $summary->sectionsTotal);
        $this->assertSame(0, $summary->sectionsCompleted);
        $this->assertSame(0.0, $summary->sectionCompletionRatio);

        $this->assertSame(0, $summary->chaptersTotal);
        $this->assertSame(0, $summary->chaptersCompleted);
        $this->assertSame(0.0, $summary->chapterCompletionRatio);

        $this->assertSame(1, $summary->partsTotal);
        $this->assertSame(0, $summary->partsCompleted);
        $this->assertSame(0.0, $summary->partCompletionRatio);

        $this->assertSame(0.0, $summary->overallCompletionRatio);
    }

    public function test_summarize_does_not_count_progress_from_another_enrollment(): void
    {
        $studentA = User::factory()->student()->create();
        $studentB = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();

        $enrollmentA = $this->createEnrollmentFor($studentA, $certification);
        $enrollmentB = $this->createEnrollmentFor($studentB, $certification);

        $part = $this->createPublishedPart($certification->id);
        $chapter = $this->createPublishedChapter($part);
        $section = $this->createPublishedSection($chapter);

        $this->markCompleted($enrollmentA, $section);

        $service = app(ProgressSummaryService::class);

        $summaryA = $service->summarize($enrollmentA);
        $summaryB = $service->summarize($enrollmentB);

        $this->assertSame(1, $summaryA->sectionsCompleted);
        $this->assertSame(1.0, $summaryA->sectionCompletionRatio);

        $this->assertSame(0, $summaryB->sectionsCompleted);
        $this->assertSame(0.0, $summaryB->sectionCompletionRatio);
    }

    public function test_batch_section_completion_ratios_returns_ratio_for_each_enrollment(): void
    {
        $studentA = User::factory()->student()->create();
        $studentB = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();

        $enrollmentA = $this->createEnrollmentFor($studentA, $certification);
        $enrollmentB = $this->createEnrollmentFor($studentB, $certification);

        $part = $this->createPublishedPart($certification->id);
        $chapter = $this->createPublishedChapter($part);
        $sectionA = $this->createPublishedSection($chapter);
        $sectionB = $this->createPublishedSection($chapter);

        $this->markCompleted($enrollmentA, $sectionA);
        $this->markCompleted($enrollmentA, $sectionB);
        $this->markCompleted($enrollmentB, $sectionA);

        $ratios = app(ProgressSummaryService::class)
            ->batchSectionCompletionRatios(
                new EloquentCollection([$enrollmentA, $enrollmentB]),
            );

        $this->assertSame(1.0, $ratios[(string) $enrollmentA->id]);
        $this->assertSame(0.5, $ratios[(string) $enrollmentB->id]);
    }

    public function test_batch_section_completion_ratios_returns_empty_array_for_empty_collection(): void
    {
        $ratios = app(ProgressSummaryService::class)
            ->batchSectionCompletionRatios(new EloquentCollection);

        $this->assertSame([], $ratios);
    }

    private function createEnrollment(): Enrollment
    {
        $student = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();

        return $this->createEnrollmentFor($student, $certification);
    }

    private function createEnrollmentFor(
        User $student,
        Certification $certification,
    ): Enrollment {
        return Enrollment::factory()
            ->for($student)
            ->for($certification)
            ->state(['status' => EnrollmentStatus::Learning->value])
            ->create();
    }

    private function createPublishedPart(string $certificationId): Part
    {
        return Part::factory()
            ->for(Certification::query()->findOrFail($certificationId))
            ->create(['status' => ContentStatus::Published->value]);
    }

    private function createPublishedChapter(Part $part): Chapter
    {
        return Chapter::factory()
            ->for($part)
            ->create(['status' => ContentStatus::Published->value]);
    }

    private function createPublishedSection(Chapter $chapter): Section
    {
        return Section::factory()
            ->for($chapter)
            ->create(['status' => ContentStatus::Published->value]);
    }

    private function markCompleted(
        Enrollment $enrollment,
        Section $section,
    ): void {
        SectionProgress::query()->create([
            'enrollment_id' => $enrollment->id,
            'section_id' => $section->id,
            'completed_at' => now(),
        ]);
    }
}
