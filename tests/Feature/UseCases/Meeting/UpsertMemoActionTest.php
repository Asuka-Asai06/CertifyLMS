<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Enums\MeetingStatus;
use App\Exceptions\Mentoring\MeetingStatusTransitionException;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\MeetingMemo;
use App\Models\User;
use App\UseCases\Meeting\UpsertMemoAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class UpsertMemoActionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Reserved の面談にメモを新規作成できることを確認する。
     */
    public function test_reserved_meeting_memo_can_be_created(): void
    {
        $meeting = $this->createMeeting();

        $body = '次回の面談で進捗を確認する。';

        app(UpsertMemoAction::class)($meeting, $body);

        $this->assertDatabaseHas('meeting_memos', [
            'meeting_id' => $meeting->id,
            'body' => $body,
        ]);

        $this->assertSame(
            1,
            MeetingMemo::query()
                ->where('meeting_id', $meeting->id)
                ->count(),
        );
    }

    /**
     * Completed の面談にもメモを新規作成できることを確認する。
     */
    public function test_completed_meeting_memo_can_be_created(): void
    {
        $meeting = $this->createMeeting();

        $meeting->update([
            'status' => MeetingStatus::Completed->value,
        ]);

        $body = '面談で学習の振り返りを行った。';

        app(UpsertMemoAction::class)($meeting, $body);

        $this->assertDatabaseHas('meeting_memos', [
            'meeting_id' => $meeting->id,
            'body' => $body,
        ]);
    }

    /**
     * 既存の面談メモを更新し、重複作成しないことを確認する。
     */
    public function test_existing_meeting_memo_can_be_updated(): void
    {
        $meeting = $this->createMeeting();

        MeetingMemo::factory()->create([
            'meeting_id' => $meeting->id,
            'body' => '更新前のメモ',
        ]);

        $body = '更新後のメモ';

        app(UpsertMemoAction::class)($meeting, $body);

        $this->assertDatabaseHas('meeting_memos', [
            'meeting_id' => $meeting->id,
            'body' => $body,
        ]);

        $this->assertSame(
            1,
            MeetingMemo::query()
                ->where('meeting_id', $meeting->id)
                ->count(),
        );
    }

    /**
     * Canceled の面談では例外が発生し、メモが作成されないことを確認する。
     */
    public function test_canceled_meeting_memo_cannot_be_created(): void
    {
        $meeting = $this->createMeeting();

        $meeting->update([
            'status' => MeetingStatus::Canceled->value,
        ]);

        try {
            app(UpsertMemoAction::class)($meeting, '保存してはいけないメモ');

            $this->fail('キャンセル済み面談のメモ保存では例外が発生する必要があります。');
        } catch (MeetingStatusTransitionException) {
            $this->assertDatabaseMissing('meeting_memos', [
                'meeting_id' => $meeting->id,
            ]);
        }
    }

    /**
     * Canceled の面談では既存メモも更新されないことを確認する。
     */
    public function test_canceled_meeting_existing_memo_cannot_be_updated(): void
    {
        $meeting = $this->createMeeting();

        $originalBody = '更新前のメモ';

        MeetingMemo::factory()->create([
            'meeting_id' => $meeting->id,
            'body' => $originalBody,
        ]);

        $meeting->update([
            'status' => MeetingStatus::Canceled->value,
        ]);

        try {
            app(UpsertMemoAction::class)($meeting, '更新してはいけないメモ');

            $this->fail('キャンセル済み面談のメモ更新では例外が発生する必要があります。');
        } catch (MeetingStatusTransitionException) {
            $this->assertDatabaseHas('meeting_memos', [
                'meeting_id' => $meeting->id,
                'body' => $originalBody,
            ]);

            $this->assertSame(
                1,
                MeetingMemo::query()
                    ->where('meeting_id', $meeting->id)
                    ->count(),
            );
        }
    }

    /**
     * テスト用の面談を作成する。
     */
    private function createMeeting(): Meeting
    {
        $student = User::factory()
            ->student()
            ->inProgress()
            ->create([
                'max_meetings' => 3,
            ]);

        $coach = User::factory()
            ->coach()
            ->create([
                'meeting_url' => 'https://example.com/meeting',
            ]);

        $certification = Certification::factory()
            ->published()
            ->create();

        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $student->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrollment = Enrollment::factory()
            ->for($student, 'user')
            ->for($certification, 'certification')
            ->learning()
            ->create();

        return Meeting::factory()
            ->forEnrollment($enrollment)
            ->forCoach($coach)
            ->forStudent($student)
            ->reserved()
            ->inFuture()
            ->create();
    }
}
