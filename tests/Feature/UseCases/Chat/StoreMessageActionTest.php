<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Chat;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Events\ChatMessageSent;
use App\Models\ChatMember;
use App\Models\ChatRoom;
use App\Models\Enrollment;
use App\Models\User;
use App\Notifications\ChatMessageReceivedNotification;
use App\UseCases\Chat\StoreMessageAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * StoreMessageAction の責務:
 *
 * - ChatMessage INSERT + 送信者の ChatMember.last_read_at = now() 更新
 * - DB::afterCommit() で ChatMessageSent broadcast を発火
 * - 通知対象のメンバーに受信通知を送信
 * - シグネチャは `__invoke(User, ChatRoom, array)`
 */
class StoreMessageActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_insert_message_and_update_sender_last_read_at(): void
    {
        Event::fake([ChatMessageSent::class]);

        $sender = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($sender)->create();
        $room = ChatRoom::factory()->for($enrollment)->create();

        $senderMember = ChatMember::factory()->create([
            'chat_room_id' => $room->id,
            'user_id' => $sender->id,
            'last_read_at' => null,
        ]);

        ChatMember::factory()->create([
            'chat_room_id' => $room->id,
            'user_id' => $coach->id,
            'last_read_at' => null,
        ]);

        $message = app(StoreMessageAction::class)(
            $sender,
            $room,
            ['body' => 'こんにちは'],
        );

        $this->assertDatabaseHas('chat_messages', [
            'id' => $message->id,
            'chat_room_id' => $room->id,
            'sender_user_id' => $sender->id,
            'body' => 'こんにちは',
        ]);

        $this->assertNotNull($senderMember->fresh()->last_read_at);

        Event::assertDispatched(ChatMessageSent::class);
    }

    public function test_in_progress_student_receives_notification(): void
    {
        Notification::fake();

        $sender = User::factory()->student()->inProgress()->create();
        $recipient = User::factory()->student()->inProgress()->create();

        $this->sendMessageToMembers($sender, [$recipient]);

        Notification::assertSentTo(
            $recipient,
            ChatMessageReceivedNotification::class,
        );
    }

    public function test_student_who_is_not_in_progress_does_not_receive_notification(): void
    {
        Notification::fake();

        $sender = User::factory()->student()->inProgress()->create();

        $recipient = User::factory()->create([
            'role' => UserRole::Student->value,
            'status' => UserStatus::Graduated->value,
        ]);

        $this->sendMessageToMembers($sender, [$recipient]);

        Notification::assertNotSentTo(
            $recipient,
            ChatMessageReceivedNotification::class,
        );
    }

    public function test_coach_receives_notification_regardless_of_status(): void
    {
        Notification::fake();

        $sender = User::factory()->student()->inProgress()->create();

        $recipient = User::factory()->create([
            'role' => UserRole::Coach->value,
        ]);

        $this->sendMessageToMembers($sender, [$recipient]);

        Notification::assertSentTo(
            $recipient,
            ChatMessageReceivedNotification::class,
        );
    }

    public function test_sender_does_not_receive_notification_for_own_message(): void
    {
        Notification::fake();

        $sender = User::factory()->student()->inProgress()->create();

        $this->sendMessageToMembers($sender, []);

        Notification::assertNotSentTo(
            $sender,
            ChatMessageReceivedNotification::class,
        );
    }

    public function test_signature_is_user_chat_room_array(): void
    {
        $reflection = new \ReflectionMethod(
            StoreMessageAction::class,
            '__invoke',
        );

        $params = $reflection->getParameters();

        $this->assertCount(3, $params);
        $this->assertSame(
            User::class,
            $params[0]->getType()?->getName(),
        );
        $this->assertSame(
            ChatRoom::class,
            $params[1]->getType()?->getName(),
        );
        $this->assertSame(
            'array',
            $params[2]->getType()?->getName(),
        );
    }

    /**
     * 指定したユーザーをチャットルームのメンバーにしてメッセージを送信する。
     *
     * @param array<int, User> $recipients
     */
    private function sendMessageToMembers(
        User $sender,
        array $recipients,
    ): void {
        $enrollment = Enrollment::factory()->for($sender)->create();
        $room = ChatRoom::factory()->for($enrollment)->create();

        ChatMember::factory()->create([
            'chat_room_id' => $room->id,
            'user_id' => $sender->id,
        ]);

        foreach ($recipients as $recipient) {
            // 送信者を重複登録しない
            if ($recipient->id === $sender->id) {
                continue;
            }

            ChatMember::factory()->create([
                'chat_room_id' => $room->id,
                'user_id' => $recipient->id,
            ]);
        }

        app(StoreMessageAction::class)(
            $sender,
            $room,
            ['body' => 'テストメッセージです。'],
        );
    }

    /**
     * チャット受信通知がキューに登録されることを確認する。
     */
    public function test_chat_message_notification_is_queued(): void
    {
        Queue::fake();

        $sender = User::factory()->student()->inProgress()->create();
        $recipient = User::factory()->student()->inProgress()->create();

        $this->sendMessageToMembers($sender, [$recipient]);

        Queue::assertPushed(
            SendQueuedNotifications::class,
            function (SendQueuedNotifications $job): bool {
                return $job->notification instanceof ChatMessageReceivedNotification;
            },
        );
    }
}
