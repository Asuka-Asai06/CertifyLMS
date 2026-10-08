<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Models\QaReply;
use App\Models\User;
use App\Notifications\QaReplyReceivedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Tests\TestCase;

/**
 * QaReplyReceivedNotification の通知内容を検証する Unit テスト。
 *
 * 自分が作成した質問に回答が投稿されたときに送信される
 * メール通知と、データベース通知に使用する配列が
 * 正しく生成されることを確認する。
 */
class QaReplyReceivedNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_to_mail_has_expected_subject(): void
    {
        // Arrange
        $reply = QaReply::factory()->create();
        $recipient = User::factory()->create();

        $notification = new QaReplyReceivedNotification($reply);

        // Act
        $mail = $notification->toMail($recipient);

        // Assert
        $this->assertInstanceOf(MailMessage::class, $mail);
        $this->assertSame(
            '質問掲示板に回答が投稿されました',
            $mail->subject,
        );
    }

    public function test_to_mail_contains_reply_body(): void
    {
        // Arrange
        $reply = QaReply::factory()->create([
            'body' => '質問への回答テストです。',
        ]);

        $recipient = User::factory()->create();

        $notification = new QaReplyReceivedNotification($reply);

        // Act
        $mail = $notification->toMail($recipient);

        // Assert
        $this->assertContains(
            'あなたが投稿した質問に回答が投稿されました。',
            $mail->introLines,
        );

        $this->assertContains(
            '質問への回答テストです。',
            $mail->introLines,
        );
    }

    public function test_to_mail_action_url_is_qa_thread_url(): void
    {
        // Arrange
        $reply = QaReply::factory()->create();
        $recipient = User::factory()->create();

        $notification = new QaReplyReceivedNotification($reply);

        // Act
        $mail = $notification->toMail($recipient);

        // Assert
        $this->assertSame(
            route('qa-board.show', $reply->thread),
            $mail->actionUrl,
        );
    }

    public function test_to_array_contains_expected_data(): void
    {
        // Arrange
        $replyUser = User::factory()->create([
            'name' => '山田太郎',
        ]);

        $reply = QaReply::factory()->create([
            'user_id' => $replyUser->id,
            'body' => '質問への回答テストです。',
        ]);

        $recipient = User::factory()->create();

        $notification = new QaReplyReceivedNotification($reply);

        // Act
        $data = $notification->toArray($recipient);

        // Assert
        $this->assertSame(
            'qa_reply_received',
            $data['notification_type'],
        );

        $this->assertSame(
            '質問掲示板に回答が投稿されました',
            $data['title'],
        );

        $this->assertSame(
            '山田太郎さんが質問に回答しました。',
            $data['message'],
        );

        $this->assertSame(
            '質問への回答テストです。',
            $data['body_preview'],
        );

        $this->assertSame(
            route('qa-board.show', $reply->thread),
            $data['url'],
        );
    }
}
