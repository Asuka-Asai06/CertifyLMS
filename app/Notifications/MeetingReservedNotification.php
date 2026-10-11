<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Meeting;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * 面談予約時の通知。
 *
 * 受講生が面談を予約した際、
 * 予約を受けたコーチへ通知を送信する。
 *
 * - データベース通知とメール通知を送信する
 * - 予約日時と予約者名を通知内容に含める
 * - 通知一覧から対象の面談詳細へ遷移できるURLを保持する
 *
 * @param Meeting $meeting 通知対象となる面談
 */
class MeetingReservedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Meeting $meeting,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->meeting->loadMissing('student');

        return (new MailMessage)
            ->subject('【Certify LMS】'.$this->meeting->student->name.'さんから新規面談予約')
            ->greeting('こんにちは、'.$notifiable->name.'さん')
            ->line($this->meeting->student->name.'さんから面談の予約が入りました。')
            ->line('予約日時：'.$this->meeting->scheduled_at->format('Y年m月d日 H:i'))
            ->action(
                '面談を確認する',
                route('meetings.show', $this->meeting),
            )
            ->line('CertifyLMSをご利用いただきありがとうございます。');
    }

    public function toArray(object $notifiable): array
    {
        $this->meeting->loadMissing('student');

        return [
            'notification_type' => 'meeting_reserved',
            'title' => '面談の予約が入りました',
            'message' => $this->meeting->student->name.'さんから面談の予約が入りました。',
            'body_preview' => '予約日時：'.$this->meeting->scheduled_at->format('Y年m月d日 H:i'),
            'url' => route('meetings.show', $this->meeting),
        ];
    }
}
