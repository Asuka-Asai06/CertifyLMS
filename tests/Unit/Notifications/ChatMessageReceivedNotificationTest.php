<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Models\ChatMessage;
use App\Models\User;
use App\Notifications\ChatMessageReceivedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Tests\TestCase;

/**
 * ChatMessageReceivedNotification の通知内容を検証する Unit テスト。
 *
 * チャットメッセージ受信時に送信されるメール通知と、
 * データベース通知に使用する配列が正しく生成されることを確認する。
 */
class ChatMessageReceivedNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_to_mail_has_expected_subject(): void
    {
        // Arrange
        $sender = User::factory()->create([
            'name' => '送信者',
        ]);

        $message = ChatMessage::factory()->create([
            'sender_user_id' => $sender->id,
            'body' => 'テストメッセージです。',
        ]);

        $recipient = User::factory()->create();

        $notification = new ChatMessageReceivedNotification($message);

        // Act
        $mail = $notification->toMail($recipient);

        // Assert
        $this->assertInstanceOf(MailMessage::class, $mail);
        $this->assertSame(
            '【Certify LMS】送信者さんから新着メッセージ',
            $mail->subject,
        );
    }

    public function test_to_mail_contains_sender_name_and_message(): void
    {
        // Arrange
        $sender = User::factory()->create([
            'name' => '山田太郎',
        ]);

        $message = ChatMessage::factory()->create([
            'sender_user_id' => $sender->id,
            'body' => 'こんにちは、テストメッセージです。',
        ]);

        $recipient = User::factory()->create();

        $notification = new ChatMessageReceivedNotification($message);

        // Act
        $mail = $notification->toMail($recipient);

        // Assert
        $this->assertContains(
            '山田太郎さんから新しいメッセージが届いています。',
            $mail->introLines,
        );

        $this->assertContains(
            'こんにちは、テストメッセージです。',
            $mail->introLines,
        );
    }

    public function test_to_mail_action_url_is_chat_room_url(): void
    {
        // Arrange
        $message = ChatMessage::factory()->create();
        $recipient = User::factory()->create();

        $notification = new ChatMessageReceivedNotification($message);

        // Act
        $mail = $notification->toMail($recipient);

        // Assert
        $this->assertSame(
            route('chat.show', $message->chat_room_id),
            $mail->actionUrl,
        );
    }

    public function test_to_array_contains_expected_data(): void
    {
        // Arrange
        $sender = User::factory()->create([
            'name' => '山田太郎',
        ]);

        $message = ChatMessage::factory()->create([
            'sender_user_id' => $sender->id,
            'body' => 'テストメッセージです。',
        ]);

        $recipient = User::factory()->create();

        $notification = new ChatMessageReceivedNotification($message);

        // Act
        $data = $notification->toArray($recipient);

        // Assert
        $this->assertSame(
            'chat_message_received',
            $data['notification_type'],
        );

        $this->assertSame(
            '新しいチャットメッセージ',
            $data['title'],
        );

        $this->assertSame(
            '山田太郎さんからメッセージが届きました。',
            $data['message'],
        );

        $this->assertSame(
            'テストメッセージです。',
            $data['body_preview'],
        );

        $this->assertSame(
            route('chat.show', $message->chat_room_id),
            $data['url'],
        );
    }
}
