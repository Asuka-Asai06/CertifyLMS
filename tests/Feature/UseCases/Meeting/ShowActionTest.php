<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\User;
use App\UseCases\Meeting\ShowAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowActionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 面談詳細の表示に必要なリレーションを読み込む。
     */
    public function test_action_loads_relationships_required_for_meeting_details(): void
    {
        $data = $this->createStudentWithEnrollment();
        $meeting = $this->createMeeting($data);

        $result = $this->action()($meeting);

        $this->assertSame($meeting->id, $result->id);

        $this->assertTrue($result->relationLoaded('enrollment'));
        $this->assertTrue($result->enrollment->relationLoaded('certification'));
        $this->assertTrue($result->relationLoaded('coach'));
        $this->assertTrue($result->relationLoaded('student'));
        $this->assertTrue($result->relationLoaded('canceledBy'));
        $this->assertTrue($result->relationLoaded('meetingMemo'));
    }

    /**
     * 面談詳細の表示に必要な関連モデルを取得できる。
     */
    public function test_action_loads_related_models(): void
    {
        $data = $this->createStudentWithEnrollment();
        $meeting = $this->createMeeting($data);

        $result = $this->action()($meeting);

        $this->assertTrue($result->enrollment->is($data['enrollment']));
        $this->assertTrue($result->coach->is($data['coach']));
        $this->assertTrue($result->student->is($data['student']));
    }

    /**
     * 既に読み込まれているリレーションを維持する。
     */
    public function test_action_does_not_reload_already_loaded_relationships(): void
    {
        $data = $this->createStudentWithEnrollment();
        $meeting = $this->createMeeting($data);

        $meeting->load([
            'enrollment.certification',
            'coach',
            'student',
            'canceledBy',
            'meetingMemo',
        ]);

        $enrollment = $meeting->enrollment;
        $coach = $meeting->coach;
        $student = $meeting->student;

        $result = $this->action()($meeting);

        $this->assertSame($enrollment, $result->enrollment);
        $this->assertSame($coach, $result->coach);
        $this->assertSame($student, $result->student);
    }

    /**
     * 受講登録を作成する。
     *
     * @return array{
     *     student: User,
     *     coach: User,
     *     enrollment: Enrollment
     * }
     */
    private function createStudentWithEnrollment(): array
    {
        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $coach = User::factory()
            ->coach()
            ->create();

        $enrollment = Enrollment::factory()
            ->for($student, 'user')
            ->learning()
            ->create();

        return [
            'student' => $student,
            'coach' => $coach,
            'enrollment' => $enrollment,
        ];
    }

    /**
     * 指定した受講登録・コーチに紐づく面談を作成する。
     *
     * @param array{
     *     student: User,
     *     coach: User,
     *     enrollment: Enrollment
     * } $data
     */
    private function createMeeting(array $data): Meeting
    {
        return Meeting::factory()
            ->forEnrollment($data['enrollment'])
            ->forCoach($data['coach'])
            ->create();
    }

    private function action(): ShowAction
    {
        return app(ShowAction::class);
    }
}
