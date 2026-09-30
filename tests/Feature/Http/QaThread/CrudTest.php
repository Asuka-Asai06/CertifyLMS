<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaThread;

use App\Enums\QaThreadStatus;
use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QaThread の作成・編集・削除・解決状態変更の挙動を検証する。
 *
 * - 受講生は公開中資格を指定して質問を作成できる
 * - 質問投稿者は自分の質問を編集・削除できる
 * - 質問投稿者は自分の質問を解決済み / 未解決に変更できる
 * - 他の受講生は質問を編集・削除・解決状態変更できない
 * - コーチは質問を作成・編集・削除・解決状態変更できない
 * - admin は質問を削除できる
 * - 質問作成時・編集時の入力値をバリデーションする
 */
class CrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_view_create_form(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        Certification::factory()
            ->published()
            ->count(2)
            ->create();

        $this->actingAs($student)
            ->get(route('qa-board.create'))
            ->assertOk()
            ->assertViewIs('qa-thread.create');
    }

    public function test_coach_cannot_view_create_form(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();

        $this->actingAs($coach)
            ->get(route('qa-board.create'))
            ->assertForbidden();
    }

    public function test_admin_cannot_view_create_form(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();

        $this->actingAs($admin)
            ->get(route('qa-board.create'))
            ->assertForbidden();
    }

    public function test_student_can_create_thread(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $response = $this->actingAs($student)
            ->post(route('qa-board.store'), [
                'certification_id' => $certification->id,
                'title' => 'Laravelについて質問があります',
                'body' => 'Laravelの認証について質問です。',
            ]);

        $thread = QaThread::firstOrFail();

        $response
            ->assertRedirect(route('qa-board.index'))
            ->assertSessionHas('success', '質問を投稿しました。');

        $this->assertSame($student->id, $thread->user_id);
        $this->assertSame($certification->id, $thread->certification_id);
        $this->assertSame(
            'Laravelについて質問があります',
            $thread->title,
        );
        $this->assertSame(
            'Laravelの認証について質問です。',
            $thread->body,
        );
        $this->assertSame(
            QaThreadStatus::Unresolved,
            $thread->status,
        );
        $this->assertNull($thread->resolved_at);
    }

    public function test_student_cannot_create_thread_with_invalid_data(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $this->actingAs($student)
            ->post(route('qa-board.store'), [
                'certification_id' => 'invalid-id',
                'title' => '',
                'body' => '',
            ])
            ->assertSessionHasErrors([
                'certification_id',
                'title',
                'body',
            ]);

        $this->assertDatabaseCount('qa_threads', 0);
    }

    public function test_student_cannot_create_thread_for_unpublished_certification(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->draft()->create();

        $this->actingAs($student)
            ->post(route('qa-board.store'), [
                'certification_id' => $certification->id,
                'title' => '下書き資格への質問',
                'body' => '質問本文です。',
            ])
            ->assertSessionHasErrors('certification_id');

        $this->assertDatabaseCount('qa_threads', 0);
    }

    public function test_student_can_view_edit_form_for_own_thread(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()->create([
            'user_id' => $student->id,
            'certification_id' => $certification->id,
        ]);

        $this->actingAs($student)
            ->get(route('qa-board.edit', $thread))
            ->assertOk()
            ->assertViewIs('qa-thread.edit')
            ->assertViewHas('thread', $thread);
    }

    public function test_student_cannot_view_edit_form_for_other_student_thread(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()->create([
            'user_id' => $otherStudent->id,
            'certification_id' => $certification->id,
        ]);

        $this->actingAs($student)
            ->get(route('qa-board.edit', $thread))
            ->assertForbidden();
    }

    public function test_student_can_update_own_thread(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()->create([
            'user_id' => $student->id,
            'certification_id' => $certification->id,
            'title' => '変更前のタイトル',
            'body' => '変更前の本文です。',
        ]);

        $this->actingAs($student)
            ->patch(route('qa-board.update', $thread), [
                'title' => '変更後のタイトル',
                'body' => '変更後の本文です。',
            ])
            ->assertRedirect(route('qa-board.show', $thread))
            ->assertSessionHas('success', '質問を更新しました。');

        $this->assertDatabaseHas('qa_threads', [
            'id' => $thread->id,
            'title' => '変更後のタイトル',
            'body' => '変更後の本文です。',
            'certification_id' => $certification->id,
        ]);
    }

    public function test_student_cannot_update_other_student_thread(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()->create([
            'user_id' => $otherStudent->id,
            'certification_id' => $certification->id,
            'title' => '元のタイトル',
            'body' => '元の本文です。',
        ]);

        $this->actingAs($student)
            ->patch(route('qa-board.update', $thread), [
                'title' => '不正な変更',
                'body' => '不正な本文です。',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('qa_threads', [
            'id' => $thread->id,
            'title' => '元のタイトル',
            'body' => '元の本文です。',
        ]);
    }

    public function test_student_cannot_update_thread_with_invalid_data(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()->create([
            'user_id' => $student->id,
            'certification_id' => $certification->id,
        ]);

        $this->actingAs($student)
            ->patch(route('qa-board.update', $thread), [
                'title' => '',
                'body' => '',
            ])
            ->assertSessionHasErrors([
                'title',
                'body',
            ]);
    }

    public function test_student_can_delete_own_thread(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()->create([
            'user_id' => $student->id,
            'certification_id' => $certification->id,
        ]);

        $this->actingAs($student)
            ->delete(route('qa-board.destroy', $thread))
            ->assertRedirect(route('qa-board.index'))
            ->assertSessionHas('success', '質問を削除しました。');

        $this->assertDatabaseMissing('qa_threads', [
            'id' => $thread->id,
        ]);
    }

    public function test_student_cannot_delete_other_student_thread(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()->create([
            'user_id' => $otherStudent->id,
            'certification_id' => $certification->id,
        ]);

        $this->actingAs($student)
            ->delete(route('qa-board.destroy', $thread))
            ->assertForbidden();

        $this->assertDatabaseHas('qa_threads', [
            'id' => $thread->id,
        ]);
    }

    public function test_admin_can_delete_any_thread(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->draft()->create();

        $thread = QaThread::factory()->create([
            'user_id' => $student->id,
            'certification_id' => $certification->id,
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.qa-board.destroy', $thread))
            ->assertRedirect(route('admin.qa-board.index'))
            ->assertSessionHas('success', '質問を削除しました。');

        $this->assertDatabaseMissing('qa_threads', [
            'id' => $thread->id,
        ]);
    }

    public function test_student_can_resolve_own_thread(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()->unresolved()->create([
            'user_id' => $student->id,
            'certification_id' => $certification->id,
        ]);

        $this->actingAs($student)
            ->post(route('qa-board.resolve', $thread))
            ->assertRedirect(route('qa-board.show', $thread))
            ->assertSessionHas('success', '質問を解決済みにしました。');

        $thread->refresh();

        $this->assertSame(
            QaThreadStatus::Resolved,
            $thread->status,
        );
        $this->assertNotNull($thread->resolved_at);
    }

    public function test_student_can_unresolve_own_thread(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()->resolved()->create([
            'user_id' => $student->id,
            'certification_id' => $certification->id,
        ]);

        $this->actingAs($student)
            ->post(route('qa-board.unresolve', $thread))
            ->assertRedirect(route('qa-board.show', $thread))
            ->assertSessionHas('success', '質問を未解決に戻しました。');

        $thread->refresh();

        $this->assertSame(
            QaThreadStatus::Unresolved,
            $thread->status,
        );
        $this->assertNull($thread->resolved_at);
    }

    public function test_student_cannot_resolve_other_student_thread(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()->unresolved()->create([
            'user_id' => $otherStudent->id,
            'certification_id' => $certification->id,
        ]);

        $this->actingAs($student)
            ->post(route('qa-board.resolve', $thread))
            ->assertForbidden();

        $thread->refresh();

        $this->assertSame(
            QaThreadStatus::Unresolved,
            $thread->status,
        );
        $this->assertNull($thread->resolved_at);
    }

    public function test_student_cannot_unresolve_other_student_thread(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()->resolved()->create([
            'user_id' => $otherStudent->id,
            'certification_id' => $certification->id,
        ]);

        $this->actingAs($student)
            ->post(route('qa-board.unresolve', $thread))
            ->assertForbidden();

        $thread->refresh();

        $this->assertSame(
            QaThreadStatus::Resolved,
            $thread->status,
        );
        $this->assertNotNull($thread->resolved_at);
    }

    public function test_coach_cannot_update_thread(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()->create([
            'user_id' => $student->id,
            'certification_id' => $certification->id,
        ]);

        $this->actingAs($coach)
            ->patch(route('qa-board.update', $thread), [
                'title' => 'コーチによる変更',
                'body' => '不正な変更です。',
            ])
            ->assertForbidden();
    }

    public function test_coach_cannot_delete_thread(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()->create([
            'user_id' => $student->id,
            'certification_id' => $certification->id,
        ]);

        $this->actingAs($coach)
            ->delete(route('qa-board.destroy', $thread))
            ->assertForbidden();

        $this->assertDatabaseHas('qa_threads', [
            'id' => $thread->id,
        ]);
    }

    public function test_coach_cannot_resolve_thread(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()->unresolved()->create([
            'user_id' => $student->id,
            'certification_id' => $certification->id,
        ]);

        $this->actingAs($coach)
            ->post(route('qa-board.resolve', $thread))
            ->assertForbidden();

        $thread->refresh();

        $this->assertSame(
            QaThreadStatus::Unresolved,
            $thread->status,
        );
        $this->assertNull($thread->resolved_at);
    }

    public function test_admin_can_delete_unpublished_thread(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->draft()->create();

        $thread = QaThread::factory()->create([
            'user_id' => $student->id,
            'certification_id' => $certification->id,
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.qa-board.destroy', $thread))
            ->assertRedirect(route('admin.qa-board.index'));

        $this->assertDatabaseMissing('qa_threads', [
            'id' => $thread->id,
        ]);
    }

    public function test_unauthenticated_user_is_redirected_to_login_when_creating_thread(): void
    {
        $certification = Certification::factory()->published()->create();

        $this->post(route('qa-board.store'), [
            'certification_id' => $certification->id,
            'title' => '質問タイトル',
            'body' => '質問本文です。',
        ])
            ->assertRedirect(route('login'));

        $this->assertDatabaseCount('qa_threads', 0);
    }

    public function test_unauthenticated_user_is_redirected_to_login_when_updating_thread(): void
    {
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()->create([
            'certification_id' => $certification->id,
        ]);

        $this->patch(route('qa-board.update', $thread), [
            'title' => '変更後のタイトル',
            'body' => '変更後の本文です。',
        ])
            ->assertRedirect(route('login'));
    }

    public function test_unauthenticated_user_is_redirected_to_login_when_deleting_thread(): void
    {
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()->create([
            'certification_id' => $certification->id,
        ]);

        $this->delete(route('qa-board.destroy', $thread))
            ->assertRedirect(route('login'));

        $this->assertDatabaseHas('qa_threads', [
            'id' => $thread->id,
        ]);
    }
}
