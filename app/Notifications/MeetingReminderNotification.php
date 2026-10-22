<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Meeting;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * 面談リマインダー通知。
 *
 * 予約済みの面談について、前日と1時間前に受講生とコーチへリマインダーを送信する。
 *
 * - データベース通知とメール通知を送信する
 * - リマインダーの種別に応じた件名と本文を使用する
 */
class MeetingReminderNotification extends QueuedNotification
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
        $this->meeting->loadMissing('enrollment.certification');

        [$subject, $message] = $this->reminderContent();

        return (new MailMessage)
            ->subject('【Certify LMS】'.$subject)
            ->greeting('こんにちは、'.$notifiable->name.'さん')
            ->line($message)
            ->line('資格名：'.$this->meeting->enrollment->certification->name)
            ->line('相談内容：'.$this->meeting->topic)
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
        $this->meeting->loadMissing('enrollment.certification');

        [$subject, $message] = $this->reminderContent();

        return [
            'notification_type' => 'meeting_reminder_'.$this->reminderType,
            'title' => $subject,
            'message' => $message,
            'certification_name' => $this->meeting->enrollment->certification->name,
            'topic' => $this->meeting->topic,
            'body_preview' => '資格名：'.$this->meeting->enrollment->certification->name
                .' / 相談内容：'.$this->meeting->topic
                .' / 予約日時：'.$this->meeting->scheduled_at->format('Y年m月d日 H:i'),
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
        $startAt = $this->meeting->scheduled_at;
        $endAt = $startAt->copy()->addHour();

        $scheduledAt = $startAt->format('Y年m月d日 H:i')
            .'～'
            .$endAt->format('H:i');

        return match ($this->reminderType) {
            'eve' => [
                '明日、面談の予定があります',
                $scheduledAt.'に予定されている面談が明日となりました。',
            ],
            'one_hour_before' => [
                '1時間後に面談の予定があります',
                $scheduledAt.'に予定されている面談が1時間後となりました。',
            ],
            default => throw new \InvalidArgumentException(
                '不正なリマインダー種別です: '.$this->reminderType
            ),
        };
    }
}
