<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\ChatMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * チャットメッセージ受信時の通知。
 *
 * チャットルームに新しいメッセージが投稿された際、
 * 送信者以外のチャット参加者へ通知を送信する。
 *
 * - データベース通知とメール通知を送信する
 * - メールにはメッセージ本文を含める
 * - 通知一覧から対象のチャットルームへ遷移できるURLを保持する
 *
 * @param ChatMessage $message 通知対象となるチャットメッセージ
 */
class ChatMessageReceivedNotification extends QueuedNotification
{
    use Queueable;

    public function __construct(
        private readonly ChatMessage $message,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->message->loadMissing('sender');

        return (new MailMessage)
            ->subject('【Certify LMS】'.$this->message->sender->name.'さんから新着メッセージ')
            ->greeting('こんにちは、'.$notifiable->name.'さん')
            ->line($this->message->sender->name.'さんから新しいメッセージが届いています。')
            ->line($this->message->body)
            ->action(
                'チャットを確認する',
                route('chat.show', $this->message->chat_room_id),
            )
            ->line('CertifyLMSをご利用いただきありがとうございます。');
    }

    public function toArray(object $notifiable): array
    {
        $this->message->loadMissing('sender');

        return [
            'notification_type' => 'chat_message_received',
            'title' => '新しいチャットメッセージ',
            'message' => $this->message->sender->name.'さんからメッセージが届きました。',
            'body_preview' => $this->message->body,
            'url' => route('chat.show', $this->message->chat_room_id),
        ];
    }
}
