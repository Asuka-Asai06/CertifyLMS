<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use App\Models\User;
use App\UseCases\Meeting\CreateFallbackAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateFallbackActionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Learning と Passed の受講登録を取得する。
     */
    public function test_action_returns_learning_and_passed_enrollments(): void
    {
        $student = $this->createStudent();

        $learning = $this->createEnrollment(
            $student,
            EnrollmentStatus::Learning,
        );

        $passed = $this->createEnrollment(
            $student,
            EnrollmentStatus::Passed,
        );

        $this->createEnrollment(
            $student,
            EnrollmentStatus::Failed,
        );

        $result = $this->action()($student);

        $this->assertEqualsCanonicalizing(
            [$learning->id, $passed->id],
            $result->modelKeys(),
        );
    }

    /**
     * 取得した受講登録の資格情報を Eager Loading する。
     */
    public function test_action_eager_loads_certification_relationship(): void
    {
        $student = $this->createStudent();

        $enrollment = $this->createEnrollment(
            $student,
            EnrollmentStatus::Learning,
        );

        $result = $this->action()($student);

        $this->assertCount(1, $result);
        $this->assertSame($enrollment->id, $result->first()->id);
        $this->assertTrue(
            $result->first()->relationLoaded('certification'),
        );
    }

    /**
     * 対象となる受講登録がない場合は空の Collection を返す。
     */
    public function test_action_returns_empty_collection_when_no_eligible_enrollments_exist(): void
    {
        $student = $this->createStudent();

        $this->createEnrollment(
            $student,
            EnrollmentStatus::Failed,
        );

        $result = $this->action()($student);

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(0, $result);
    }

    /**
     * ユーザーが null の場合は空の Collection を返す。
     */
    public function test_action_returns_empty_collection_when_user_is_null(): void
    {
        $result = $this->action()(null);

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(0, $result);
    }

    /**
     * 他の受講生の受講登録を取得しない。
     */
    public function test_action_returns_only_enrollments_belonging_to_the_given_user(): void
    {
        $student = $this->createStudent();
        $otherStudent = $this->createStudent();

        $ownEnrollment = $this->createEnrollment(
            $student,
            EnrollmentStatus::Learning,
        );

        $otherEnrollment = $this->createEnrollment(
            $otherStudent,
            EnrollmentStatus::Learning,
        );

        $result = $this->action()($student);

        $this->assertSame(
            [$ownEnrollment->id],
            $result->modelKeys(),
        );

        $this->assertNotContains(
            $otherEnrollment->id,
            $result->modelKeys(),
        );
    }

    /**
     * 受講生を作成する。
     */
    private function createStudent(): User
    {
        return User::factory()
            ->student()
            ->inProgress()
            ->create();
    }

    /**
     * 指定した受講生とステータスの受講登録を作成する。
     */
    private function createEnrollment(
        User $student,
        EnrollmentStatus $status,
    ): Enrollment {
        return Enrollment::factory()
            ->for($student, 'user')
            ->learning()
            ->create([
                'status' => $status->value,
            ]);
    }

    private function action(): CreateFallbackAction
    {
        return app(CreateFallbackAction::class);
    }
}
