<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaReply;

use App\Models\Certification;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QaReply の作成・編集・削除の挙動を検証する。
 *
 * - 受講生・担当コーチは質問に返信できる
 * - admin は返信を作成できない
 * - 返信投稿者は自分の返信を編集・削除できる
 * - 他のユーザーは返信を編集できない
 * - admin は他のユーザーの返信を削除できる
 * - 返信作成・編集時の入力値をバリデーションする
 */
class CrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_create_reply(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $thread = $this->createPublishedThread();

        $this->actingAs($student)
            ->post(route('qa-board.replies.store', $thread), [
                'body' => '回答本文です。',
            ])
            ->assertRedirect(route('qa-board.show', $thread))
            ->assertSessionHas('success', '回答を投稿しました。');

        $this->assertDatabaseHas('qa_replies', [
            'qa_thread_id' => $thread->id,
            'user_id' => $student->id,
            'body' => '回答本文です。',
        ]);
    }

    public function test_assigned_coach_can_create_reply(): void
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
            ->post(route('qa-board.replies.store', $thread), [
                'body' => 'コーチからの回答です。',
            ])
            ->assertRedirect(route('qa-board.show', $thread))
            ->assertSessionHas('success', '回答を投稿しました。');

        $this->assertDatabaseHas('qa_replies', [
            'qa_thread_id' => $thread->id,
            'user_id' => $coach->id,
            'body' => 'コーチからの回答です。',
        ]);
    }

    public function test_unassigned_coach_cannot_create_reply(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $thread = $this->createPublishedThread();

        $this->actingAs($coach)
            ->post(route('qa-board.replies.store', $thread), [
                'body' => '担当外資格への回答です。',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('qa_replies', [
            'qa_thread_id' => $thread->id,
            'user_id' => $coach->id,
        ]);
    }

    public function test_admin_cannot_create_reply(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();
        $thread = $this->createPublishedThread();

        $this->actingAs($admin)
            ->post(route('qa-board.replies.store', $thread), [
                'body' => '管理者による回答です。',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('qa_replies', [
            'qa_thread_id' => $thread->id,
            'user_id' => $admin->id,
        ]);
    }

    public function test_student_can_view_edit_form_for_own_reply(): void
    {
        $this->withoutExceptionHandling();
        $student = User::factory()->student()->inProgress()->create();
        $thread = $this->createPublishedThread();

        $reply = QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
            'user_id' => $student->id,
            'body' => '変更前の回答です。',
        ]);

        $this->actingAs($student)
            ->get(route('qa-board.replies.edit', $reply))
            ->assertOk()
            ->assertViewIs('qa-thread.reply-edit')
            ->assertViewHas('reply', $reply);
    }

    public function test_student_cannot_view_edit_form_for_other_users_reply(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        $thread = $this->createPublishedThread();

        $reply = QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
            'user_id' => $otherStudent->id,
        ]);

        $this->actingAs($student)
            ->get(route('qa-board.replies.edit', $reply))
            ->assertForbidden();
    }

    public function test_student_can_update_own_reply(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $thread = $this->createPublishedThread();

        $reply = QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
            'user_id' => $student->id,
            'body' => '変更前の回答です。',
        ]);

        $this->actingAs($student)
            ->patch(route('qa-board.replies.update', $reply), [
                'body' => '変更後の回答です。',
            ])
            ->assertRedirect(route('qa-board.show', $thread))
            ->assertSessionHas('success', '回答を更新しました。');

        $this->assertDatabaseHas('qa_replies', [
            'id' => $reply->id,
            'body' => '変更後の回答です。',
        ]);
    }

    public function test_student_cannot_update_other_users_reply(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        $thread = $this->createPublishedThread();

        $reply = QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
            'user_id' => $otherStudent->id,
            'body' => '元の回答です。',
        ]);

        $this->actingAs($student)
            ->patch(route('qa-board.replies.update', $reply), [
                'body' => '不正な変更です。',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('qa_replies', [
            'id' => $reply->id,
            'body' => '元の回答です。',
        ]);
    }

    public function test_coach_cannot_update_other_users_reply(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $student = User::factory()->student()->inProgress()->create();
        $thread = $this->createPublishedThread();

        $reply = QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
            'user_id' => $student->id,
            'body' => '元の回答です。',
        ]);

        $this->actingAs($coach)
            ->patch(route('qa-board.replies.update', $reply), [
                'body' => 'コーチによる不正な変更です。',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('qa_replies', [
            'id' => $reply->id,
            'body' => '元の回答です。',
        ]);
    }

    public function test_admin_cannot_update_reply(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();
        $student = User::factory()->student()->inProgress()->create();
        $thread = $this->createPublishedThread();

        $reply = QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
            'user_id' => $student->id,
            'body' => '元の回答です。',
        ]);

        $this->actingAs($admin)
            ->patch(route('qa-board.replies.update', $reply), [
                'body' => '管理者による変更です。',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('qa_replies', [
            'id' => $reply->id,
            'body' => '元の回答です。',
        ]);
    }

    public function test_student_can_delete_own_reply(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $thread = $this->createPublishedThread();

        $reply = QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
            'user_id' => $student->id,
        ]);

        $this->actingAs($student)
            ->delete(route('qa-board.replies.destroy', $reply))
            ->assertRedirect(route('qa-board.show', $thread))
            ->assertSessionHas('success', '回答を削除しました。');

        $this->assertDatabaseMissing('qa_replies', [
            'id' => $reply->id,
        ]);
    }

    public function test_student_cannot_delete_other_users_reply(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        $thread = $this->createPublishedThread();

        $reply = QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
            'user_id' => $otherStudent->id,
        ]);

        $this->actingAs($student)
            ->delete(route('qa-board.replies.destroy', $reply))
            ->assertForbidden();

        $this->assertDatabaseHas('qa_replies', [
            'id' => $reply->id,
        ]);
    }

    public function test_admin_can_delete_any_reply(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();
        $student = User::factory()->student()->inProgress()->create();
        $thread = $this->createPublishedThread();

        $reply = QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
            'user_id' => $student->id,
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.qa-board.replies.destroy', $reply))
            ->assertRedirect(route('admin.qa-board.show', $thread))
            ->assertSessionHas('success', '回答を削除しました。');

        $this->assertDatabaseMissing('qa_replies', [
            'id' => $reply->id,
        ]);
    }

    public function test_admin_can_delete_reply_to_unpublished_thread(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->draft()->create();

        $thread = QaThread::factory()->create([
            'certification_id' => $certification->id,
        ]);

        $reply = QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
            'user_id' => $student->id,
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.qa-board.replies.destroy', $reply))
            ->assertRedirect(route('admin.qa-board.show', $thread))
            ->assertSessionHas('success', '回答を削除しました。');

        $this->assertDatabaseMissing('qa_replies', [
            'id' => $reply->id,
        ]);
    }

    public function test_create_reply_requires_body(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $thread = $this->createPublishedThread();

        $this->actingAs($student)
            ->post(route('qa-board.replies.store', $thread), [])
            ->assertSessionHasErrors('body');

        $this->assertDatabaseCount('qa_replies', 0);
    }

    public function test_create_reply_rejects_body_over_5000_characters(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $thread = $this->createPublishedThread();

        $this->actingAs($student)
            ->post(route('qa-board.replies.store', $thread), [
                'body' => str_repeat('あ', 5001),
            ])
            ->assertSessionHasErrors('body');

        $this->assertDatabaseCount('qa_replies', 0);
    }

    public function test_update_reply_requires_body(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $thread = $this->createPublishedThread();

        $reply = QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
            'user_id' => $student->id,
            'body' => '元の回答です。',
        ]);

        $this->actingAs($student)
            ->patch(route('qa-board.replies.update', $reply), [])
            ->assertSessionHasErrors('body');

        $this->assertDatabaseHas('qa_replies', [
            'id' => $reply->id,
            'body' => '元の回答です。',
        ]);
    }

    public function test_update_reply_rejects_body_over_5000_characters(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $thread = $this->createPublishedThread();

        $reply = QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
            'user_id' => $student->id,
            'body' => '元の回答です。',
        ]);

        $this->actingAs($student)
            ->patch(route('qa-board.replies.update', $reply), [
                'body' => str_repeat('あ', 5001),
            ])
            ->assertSessionHasErrors('body');

        $this->assertDatabaseHas('qa_replies', [
            'id' => $reply->id,
            'body' => '元の回答です。',
        ]);
    }

    public function test_unauthenticated_user_cannot_create_reply(): void
    {
        $thread = $this->createPublishedThread();

        $this->post(route('qa-board.replies.store', $thread), [
            'body' => '未ログインユーザーからの回答です。',
        ])
            ->assertRedirect(route('login'));
    }

    public function test_unauthenticated_user_cannot_update_reply(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $thread = $this->createPublishedThread();

        $reply = QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
            'user_id' => $student->id,
        ]);

        $this->patch(route('qa-board.replies.update', $reply), [
            'body' => '変更後の回答です。',
        ])
            ->assertRedirect(route('login'));
    }

    public function test_unauthenticated_user_cannot_delete_reply(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $thread = $this->createPublishedThread();

        $reply = QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
            'user_id' => $student->id,
        ]);

        $this->delete(route('qa-board.replies.destroy', $reply))
            ->assertRedirect(route('login'));
    }

    /**
     * 公開中資格に紐づく質問を作成する。
     */
    private function createPublishedThread(): QaThread
    {
        $certification = Certification::factory()
            ->published()
            ->create();

        return QaThread::factory()->create([
            'certification_id' => $certification->id,
        ]);
    }
}
