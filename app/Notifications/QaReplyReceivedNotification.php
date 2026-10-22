<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\QaReply;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * 質問掲示板への回答投稿時の通知。
 *
 * 自分が投稿した質問に回答が投稿された際、
 * 質問の投稿者へ通知を送信する。
 *
 * - データベース通知とメール通知を送信する
 * - メールには投稿された回答本文を含める
 * - 通知一覧から対象の質問詳細へ遷移できるURLを保持する
 *
 * @param QaReply $reply 通知対象となる質問への回答
 */
class QaReplyReceivedNotification extends QueuedNotification
{
    use Queueable;

    public function __construct(
        private readonly QaReply $reply,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->reply->loadMissing('thread');

        return (new MailMessage)
            ->subject('【Certify LMS】質問掲示板に回答が投稿されました')
            ->greeting('こんにちは、'.$notifiable->name.'さん')
            ->line('あなたが投稿した質問に回答が投稿されました。')
            ->line($this->reply->body)
            ->action(
                '質問を確認する',
                route('qa-board.show', $this->reply->thread),
            )
            ->line('CertifyLMSをご利用いただきありがとうございます。');
    }

    public function toArray(object $notifiable): array
    {
        $this->reply->loadMissing('thread', 'user');

        return [
            'notification_type' => 'qa_reply_received',
            'title' => '質問掲示板に回答が投稿されました',
            'message' => $this->reply->user->name.'さんが質問に回答しました。',
            'body_preview' => $this->reply->body,
            'url' => route('qa-board.show', $this->reply->thread),
        ];
    }
}
