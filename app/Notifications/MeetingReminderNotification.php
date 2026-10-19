<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Meeting;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * 面談リマインダー通知。
 *
 * 予約済みの面談について、前日と1時間前に
 * 受講生とコーチへリマインダーを送信する。
 *
 * - データベース通知とメール通知を送信する
 * - リマインダーの種別に応じた件名と本文を使用する
 * - 予約日時と面談詳細へのURLを通知内容に含める
 */
class MeetingReminderNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Meeting $meeting,
        private readonly string $reminderType,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->meeting->loadMissing('student');

        [$subject, $message] = $this->reminderContent();

        return (new MailMessage)
            ->subject('【Certify LMS】'.$subject)
            ->greeting('こんにちは、'.$notifiable->name.'さん')
            ->line($message)
            ->line(
                '予約日時：'.$this->meeting->scheduled_at->format('Y年m月d日 H:i')
            )
            ->action(
                '面談を確認する',
                route('meetings.show', $this->meeting),
            )
            ->line('CertifyLMSをご利用いただきありがとうございます。');
    }

    public function toArray(object $notifiable): array
    {
        $this->meeting->loadMissing('student');

        [$subject, $message] = $this->reminderContent();

        return [
            'notification_type' => 'meeting_reminder_'.$this->reminderType,
            'title' => $subject,
            'message' => $message,
            'body_preview' => '予約日時：'.$this->meeting->scheduled_at->format('Y年m月d日 H:i'),
            'url' => route('meetings.show', $this->meeting),
        ];
    }

    /**
     * リマインダー種別に応じた件名と本文を返す。
     *
     * @return array{0: string, 1: string}
     */
    private function reminderContent(): array
    {
        return match ($this->reminderType) {
            'eve' => [
                '明日、面談の予定があります',
                '明日、面談の予定があります。',
            ],
            'one_hour_before' => [
                '1時間後に面談の予定があります',
                '1時間後に面談の予定があります。',
            ],
            default => throw new \InvalidArgumentException(
                '不正なリマインダー種別です: '.$this->reminderType
            ),
        };
    }
}
