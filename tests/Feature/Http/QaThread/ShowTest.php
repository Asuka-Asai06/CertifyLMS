<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaThread;

use App\Models\Certification;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /qa-board/{thread}` の挙動を検証する。
 *
 * - 受講生は公開中資格の質問を閲覧できる
 * - コーチは担当中かつ公開中資格の質問を閲覧できる
 * - 未担当コーチは閲覧できない
 * - admin は非公開資格の質問も閲覧できる
 * - 受講生は非公開資格の質問を閲覧できない
 * - 質問に紐づく返信が表示される
 * - soft delete された投稿者は「不明」として表示される
 */
class ShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_view_published_thread(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()->create([
            'user_id' => $student->id,
            'certification_id' => $certification->id,
            'title' => 'Laravelについて質問があります',
            'body' => 'これは質問本文です。',
        ]);

        $this->actingAs($student)
            ->get(route('qa-board.show', $thread))
            ->assertOk()
            ->assertViewIs('qa-thread.show')
            ->assertViewHas('thread', $thread)
            ->assertSee('Laravelについて質問があります')
            ->assertSee('これは質問本文です。');
    }

    public function test_student_cannot_view_thread_of_unpublished_certification(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->draft()->create();

        $thread = QaThread::factory()->create([
            'certification_id' => $certification->id,
        ]);

        $this->actingAs($student)
            ->get(route('qa-board.show', $thread))
            ->assertForbidden();
    }

    public function test_coach_can_view_thread_of_assigned_published_certification(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $admin = User::factory()->admin()->inProgress()->create();

        $certification = Certification::factory()->published()->create();

        $certification->coaches()->attach($coach->id, [
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);

        $thread = QaThread::factory()->create([
            'certification_id' => $certification->id,
        ]);

        $this->actingAs($coach)
            ->get(route('qa-board.show', $thread))
            ->assertOk()
            ->assertViewIs('qa-thread.show')
            ->assertViewHas('thread', $thread);
    }

    public function test_coach_cannot_view_thread_of_unassigned_certification(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()->create([
            'certification_id' => $certification->id,
        ]);

        $this->actingAs($coach)
            ->get(route('qa-board.show', $thread))
            ->assertForbidden();
    }

    public function test_coach_cannot_view_thread_of_unpublished_certification(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $admin = User::factory()->admin()->inProgress()->create();

        $certification = Certification::factory()->draft()->create();

        $certification->coaches()->attach($coach->id, [
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);

        $thread = QaThread::factory()->create([
            'certification_id' => $certification->id,
        ]);

        $this->actingAs($coach)
            ->get(route('qa-board.show', $thread))
            ->assertForbidden();
    }

    public function test_admin_can_view_thread_of_unpublished_certification(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();
        $certification = Certification::factory()->draft()->create();

        $thread = QaThread::factory()->create([
            'certification_id' => $certification->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.qa-board.show', $thread))
            ->assertOk()
            ->assertViewIs('qa-thread.show')
            ->assertViewHas('thread', $thread);
    }

    public function test_show_displays_replies(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $replyUser = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()->create([
            'user_id' => $student->id,
            'certification_id' => $certification->id,
        ]);

        $reply = QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
            'user_id' => $replyUser->id,
            'body' => 'こちらが返信本文です。',
        ]);

        $this->actingAs($student)
            ->get(route('qa-board.show', $thread))
            ->assertOk()
            ->assertSee('こちらが返信本文です。')
            ->assertViewHas(
                'thread',
                fn (QaThread $loadedThread): bool => $loadedThread->relationLoaded('replies')
                    && $loadedThread->replies->contains(
                        fn (QaReply $loadedReply): bool => $loadedReply->is($reply),
                    ),
            );
    }

    public function test_show_displays_reply_user_name(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $replyUser = User::factory()
            ->student()
            ->inProgress()
            ->create([
                'name' => '返信ユーザー',
            ]);

        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()->create([
            'user_id' => $student->id,
            'certification_id' => $certification->id,
        ]);

        QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
            'user_id' => $replyUser->id,
            'body' => '返信本文です。',
        ]);

        $this->actingAs($student)
            ->get(route('qa-board.show', $thread))
            ->assertOk()
            ->assertSee('返信ユーザー')
            ->assertSee('返信本文です。');
    }

    public function test_soft_deleted_thread_user_is_displayed_as_unknown(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()->create([
            'user_id' => $student->id,
            'certification_id' => $certification->id,
            'title' => '削除済みユーザーの質問',
            'body' => '削除済みユーザーが投稿した質問です。',
        ]);

        $student->delete();

        $viewer = User::factory()->student()->inProgress()->create();

        $this->actingAs($viewer)
            ->get(route('qa-board.show', $thread))
            ->assertOk()
            ->assertSee('削除済みユーザーの質問')
            ->assertSee('不明');
    }

    public function test_soft_deleted_reply_user_is_displayed_as_unknown(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $replyUser = User::factory()->student()->inProgress()->create();

        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()->create([
            'user_id' => $student->id,
            'certification_id' => $certification->id,
        ]);

        QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
            'user_id' => $replyUser->id,
            'body' => '削除済みユーザーの返信です。',
        ]);

        $replyUser->delete();

        $this->actingAs($student)
            ->get(route('qa-board.show', $thread))
            ->assertOk()
            ->assertSee('削除済みユーザーの返信です。')
            ->assertSee('不明');
    }

    public function test_unauthenticated_user_cannot_view_thread(): void
    {
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()->create([
            'certification_id' => $certification->id,
        ]);

        $this->get(route('qa-board.show', $thread))
            ->assertRedirect(route('login'));
    }
}
