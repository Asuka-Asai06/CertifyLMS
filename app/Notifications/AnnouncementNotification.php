<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Announcement;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * 管理者お知らせの配信通知。
 *
 * 管理者が作成したお知らせを受講生へ配信する。
 *
 * - データベース通知とメール通知を送信する
 * - アプリ内通知から通知詳細ページへ遷移できる
 * - メールにはお知らせのタイトルと本文を含める
 */
class AnnouncementNotification extends Notification
{
    use Queueable;

    private readonly string $notificationId;

    public function __construct(
        private readonly Announcement $announcement,
    ) {
        $this->notificationId = (string) Str::uuid();
        $this->id = $this->notificationId;
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('【Certify LMS】'.$this->announcement->title)
            ->markdown('emails.announcements', [
                'recipientName' => $notifiable->name,
                'announcement' => $this->announcement,
                'notificationUrl' => route(
                    'notifications.show',
                    $this->notificationId,
                ),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'notification_type' => 'admin_announcement',
            'title' => $this->announcement->title,
            'message' => '管理者からのお知らせが届いています。',
            'body' => $this->announcement->body,
            'announcement_id' => $this->announcement->id,
            'url' => route('notifications.show', $this->notificationId),
        ];
    }
}
