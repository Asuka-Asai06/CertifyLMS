<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Meeting;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * 面談キャンセル時の通知。
 *
 * 受講生またはコーチが面談をキャンセルした際、
 * キャンセルを実行した人の相手方へ通知を送信する。
 *
 * - データベース通知とメール通知を送信する
 * - キャンセルしたユーザー名と予約日時を通知内容に含める
 * - 通知一覧から対象の面談詳細へ遷移できるURLを保持する
 *
 * @param Meeting $meeting 通知対象となる面談
 */
class MeetingCanceledNotification extends QueuedNotification
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
        $this->meeting->loadMissing('student', 'coach');

        $canceledBy = $this->meeting->canceled_by_user_id === $this->meeting->student_id
            ? $this->meeting->student
            : $this->meeting->coach;

        return (new MailMessage)
            ->subject('【Certify LMS】'.$this->meeting->student->name.'さんの面談キャンセル')
            ->greeting('こんにちは、'.$notifiable->name.'さん')
            ->line($canceledBy->name.'さんが面談をキャンセルしました。')
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
        $this->meeting->loadMissing('student', 'coach');

        $canceledBy = $this->meeting->canceled_by_user_id === $this->meeting->student_id
            ? $this->meeting->student
            : $this->meeting->coach;

        return [
            'notification_type' => 'meeting_canceled',
            'title' => '面談がキャンセルされました',
            'message' => $canceledBy->name.'さんが面談をキャンセルしました。',
            'body_preview' => '予約日時：'.$this->meeting->scheduled_at->format('Y年m月d日 H:i'),
            'url' => route('meetings.show', $this->meeting),
        ];
    }
}
