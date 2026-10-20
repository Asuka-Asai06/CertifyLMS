<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\QaThread;

use App\Exceptions\QaThread\QaThreadHasRepliesException;
use App\Models\Certification;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use App\UseCases\QaThread\DestroyAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DestroyAction の責務:
 *
 * - 回答のないスレッドを削除
 * - 一般ユーザーによる回答付きスレッドの削除を拒否
 * - 管理者による回答付きスレッドの削除
 * - 削除処理をトランザクション内で実行
 */
class DestroyActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_delete_thread_without_replies(): void
    {
        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $certification = Certification::factory()->create();

        $thread = QaThread::factory()->create([
            'user_id' => $student->id,
            'certification_id' => $certification->id,
        ]);

        app(DestroyAction::class)($thread, $student);

        $this->assertDatabaseMissing('qa_threads', [
            'id' => $thread->id,
        ]);
    }

    public function test_student_cannot_delete_thread_with_replies(): void
    {
        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $certification = Certification::factory()->create();

        $thread = QaThread::factory()->create([
            'user_id' => $student->id,
            'certification_id' => $certification->id,
        ]);

        $reply = QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
            'user_id' => $student->id,
        ]);

        try {
            app(DestroyAction::class)($thread, $student);

            $this->fail('回答付きスレッドの削除が拒否されませんでした。');
        } catch (QaThreadHasRepliesException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertSame(
                '回答が付いているスレッドは削除できません。',
                $exception->getMessage(),
            );
        }

        // 削除が拒否されたため、スレッドと回答は残る。
        $this->assertDatabaseHas('qa_threads', [
            'id' => $thread->id,
        ]);

        $this->assertDatabaseHas('qa_replies', [
            'id' => $reply->id,
            'qa_thread_id' => $thread->id,
        ]);
    }

    public function test_admin_can_delete_thread_with_replies(): void
    {
        $admin = User::factory()
            ->admin()
            ->inProgress()
            ->create();

        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $certification = Certification::factory()->create();

        $thread = QaThread::factory()->create([
            'user_id' => $student->id,
            'certification_id' => $certification->id,
        ]);

        $reply = QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
            'user_id' => $student->id,
        ]);

        app(DestroyAction::class)($thread, $admin);

        // 管理者はスレッドと回答を削除できる。
        $this->assertDatabaseMissing('qa_threads', [
            'id' => $thread->id,
        ]);

        $this->assertDatabaseMissing('qa_replies', [
            'id' => $reply->id,
        ]);
    }

    public function test_signature_is_thread_and_user(): void
    {
        $reflection = new \ReflectionMethod(
            DestroyAction::class,
            '__invoke',
        );

        $params = $reflection->getParameters();

        $this->assertCount(2, $params);

        $this->assertSame(
            QaThread::class,
            $params[0]->getType()?->getName(),
        );

        $this->assertSame(
            User::class,
            $params[1]->getType()?->getName(),
        );

        $this->assertSame(
            'void',
            $reflection->getReturnType()?->getName(),
        );
    }
}
