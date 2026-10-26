<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\QaReply;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use App\Notifications\QaReplyReceivedNotification;
use App\UseCases\QaReply\StoreAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * StoreAction の責務:
 *
 * - QaReply を INSERT
 * - 返信者にログイン中の User を設定
 * - 返信先に対象の QaThread を設定
 * - 指定された本文を登録
 * - 質問者のロールとステータスに応じて通知を送信
 */
class StoreActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_insert_reply(): void
    {
        $user = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $thread = QaThread::factory()->create();

        $reply = app(StoreAction::class)(
            $user,
            $thread,
            [
                'body' => 'Policyについて理解できました。ありがとうございます。',
            ],
        );

        $this->assertDatabaseHas('qa_replies', [
            'id' => $reply->id,
            'qa_thread_id' => $thread->id,
            'user_id' => $user->id,
            'body' => 'Policyについて理解できました。ありがとうございます。',
        ]);
    }

    public function test_in_progress_student_receives_notification(): void
    {
        Notification::fake();

        $author = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $recipient = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $this->storeReply($author, $recipient);

        Notification::assertSentTo(
            $recipient,
            QaReplyReceivedNotification::class,
        );
    }

    public function test_student_who_is_not_in_progress_does_not_receive_notification(): void
    {
        Notification::fake();

        $author = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $recipient = User::factory()->create([
            'role' => UserRole::Student->value,
            'status' => UserStatus::Graduated->value,
        ]);

        $this->storeReply($author, $recipient);

        Notification::assertNotSentTo(
            $recipient,
            QaReplyReceivedNotification::class,
        );
    }

    public function test_coach_receives_notification_regardless_of_status(): void
    {
        Notification::fake();

        $author = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $recipient = User::factory()->create([
            'role' => UserRole::Coach->value,
        ]);

        $this->storeReply($author, $recipient);

        Notification::assertSentTo(
            $recipient,
            QaReplyReceivedNotification::class,
        );
    }

    public function test_question_author_does_not_receive_notification_for_own_reply(): void
    {
        Notification::fake();

        $author = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $this->storeReply($author, $author);

        Notification::assertNotSentTo(
            $author,
            QaReplyReceivedNotification::class,
        );
    }

    public function test_signature_is_user_thread_array(): void
    {
        $reflection = new \ReflectionMethod(
            StoreAction::class,
            '__invoke',
        );

        $params = $reflection->getParameters();

        $this->assertCount(3, $params);
        $this->assertSame(
            User::class,
            $params[0]->getType()?->getName(),
        );
        $this->assertSame(
            QaThread::class,
            $params[1]->getType()?->getName(),
        );
        $this->assertSame(
            'array',
            $params[2]->getType()?->getName(),
        );
    }

    private function storeReply(User $author, User $recipient): QaReply
    {
        $thread = QaThread::factory()->create([
            'user_id' => $recipient->id,
        ]);

        return app(StoreAction::class)(
            $author,
            $thread,
            ['body' => 'テスト回答です。'],
        );
    }

    /**
     * Q&A 返信通知がキューに登録されることを確認する。
     */
    public function test_qa_reply_notification_is_queued(): void
    {
        Queue::fake();

        $author = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $recipient = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $this->storeReply($author, $recipient);

        Queue::assertPushed(
            SendQueuedNotifications::class,
            function (SendQueuedNotifications $job): bool {
                return $job->notification instanceof QaReplyReceivedNotification;
            },
        );
    }
}
