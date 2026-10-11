<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MeetingStatus;
use App\Models\ChatMessage;
use App\Models\Meeting;
use App\Models\QaReply;
use App\Models\User;
use App\Notifications\ChatMessageReceivedNotification;
use App\Notifications\MeetingCanceledNotification;
use App\Notifications\MeetingReservedNotification;
use App\Notifications\QaReplyReceivedNotification;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * 開発用 通知シーダー。
 *
 * 既存のチャット・質問掲示板・面談データを利用して、
 * 通知一覧の表示・既読状態・ページネーション・
 * 通知クリック時の遷移を確認するための通知データを作成する。
 *
 * - 固定の受講生・コーチに通知を投入する
 * - 既読・未読を混在させる
 * - 20件を超える通知を作成し、ページネーションを確認できるようにする
 * - チャット、質問掲示板、面談予約、面談キャンセルの4種類の通知を混在させる
 *
 * 通知そのものをSeederから直接作成することで、
 * 通知を発生させるために業務処理を再実行することを避ける。
 */
class NotificationSeeder extends Seeder
{
    private const NOTIFICATIONS_PER_USER = 24;

    public function run(): void
    {
        $student = User::query()
            ->where('email', 'student@certify-lms.test')
            ->first();

        $coach = User::query()
            ->where('email', 'coach@certify-lms.test')
            ->first();

        if ($student === null || $coach === null) {
            $this->command?->warn(
                '固定の受講生・コーチが存在しないため、NotificationSeederをスキップします。',
            );

            return;
        }

        $chatMessage = $this->findChatMessage($student, $coach);
        $qaReply = $this->findQaReply($student);
        $reservedMeeting = $this->findReservedMeeting($student, $coach);
        $canceledMeeting = $this->findCanceledMeeting($coach);

        if (
            $chatMessage === null
            || $qaReply === null
            || $reservedMeeting === null
            || $canceledMeeting === null
        ) {
            $this->command?->warn(
                '通知の遷移先となる既存データが不足しているため、NotificationSeederをスキップします。',
            );

            return;
        }

        $this->seedStudentNotifications(
            $student,
            $chatMessage,
            $qaReply,
        );

        $this->seedCoachNotifications(
            $coach,
            $reservedMeeting,
            $canceledMeeting,
        );
    }

    /**
     * 受講生向け通知を作成する。
     */
    private function seedStudentNotifications(
        User $student,
        ChatMessage $chatMessage,
        QaReply $qaReply,
    ): void {
        $definitions = [
            new ChatMessageReceivedNotification($chatMessage),
            new QaReplyReceivedNotification($qaReply),
        ];

        $this->createNotifications(
            $student,
            $definitions,
        );
    }

    /**
     * コーチ向け通知を作成する。
     */
    private function seedCoachNotifications(
        User $coach,
        Meeting $reservedMeeting,
        Meeting $canceledMeeting,
    ): void {
        $definitions = [
            new MeetingReservedNotification($reservedMeeting),
            new MeetingCanceledNotification($canceledMeeting),
        ];

        $this->createNotifications(
            $coach,
            $definitions,
        );
    }

    /**
     * 指定した通知を繰り返して通知データを作成する。
     *
     * 1ユーザーあたり24件作成し、通知一覧の20件ページネーションを
     * 2ページ目まで確認できるようにする。
     *
     * @param array<int, Notification> $definitions
     */
    private function createNotifications(User $user, array $definitions): void
    {
        for ($index = 0; $index < self::NOTIFICATIONS_PER_USER; $index++) {
            $notification = $definitions[
                $index % count($definitions)
            ];

            $createdAt = Carbon::now()
                ->subHours(self::NOTIFICATIONS_PER_USER - $index);

            $this->createDatabaseNotification(
                $user,
                $notification,
                $index % 3 !== 0,
                $createdAt,
            );
        }
    }

    /**
     * データベース通知を作成する。
     */
    private function createDatabaseNotification(
        User $user,
        Notification $notification,
        bool $read,
        Carbon $createdAt,
    ): void {
        $data = $notification->toArray($user);

        DatabaseNotification::query()->create([
            'id' => (string) Str::uuid(),
            'type' => $notification::class,
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => $data,
            'read_at' => $read ? $createdAt->copy() : null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    /**
     * 固定の受講生が参加しているチャットの、
     * コーチから送信されたメッセージを取得する。
     */
    private function findChatMessage(User $student, User $coach): ?ChatMessage
    {
        return ChatMessage::query()
            ->where('sender_user_id', $coach->id)
            ->whereHas(
                'chatRoom.members',
                fn ($query) => $query->where(
                    'user_id',
                    $student->id,
                ),
            )
            ->latest('created_at')
            ->first();
    }

    /**
     * 固定の受講生が投稿した質問への回答を取得する。
     */
    private function findQaReply(User $student): ?QaReply
    {
        return QaReply::query()
            ->where('user_id', '!=', $student->id)
            ->whereHas(
                'thread',
                fn ($query) => $query->where(
                    'user_id',
                    $student->id,
                ),
            )
            ->with('thread')
            ->latest('created_at')
            ->first();
    }

    /**
     * 固定の受講生・コーチ間の予約済み面談を取得する。
     */
    private function findReservedMeeting(User $student, User $coach): ?Meeting
    {
        return Meeting::query()
            ->where('student_id', $student->id)
            ->where('coach_id', $coach->id)
            ->where(
                'status',
                MeetingStatus::Reserved->value,
            )
            ->latest('scheduled_at')
            ->first();
    }

    /**
     * 固定コーチが関係するキャンセル済み面談を取得する。
     *
     * 受講生がキャンセルした面談を優先して取得し、
     * キャンセル実行者の相手方であるコーチを通知先とする。
     */
    private function findCanceledMeeting(User $coach): ?Meeting
    {
        return Meeting::query()
            ->where('coach_id', $coach->id)
            ->where(
                'status',
                MeetingStatus::Canceled->value,
            )
            ->whereColumn(
                'canceled_by_user_id',
                '!=',
                'coach_id',
            )
            ->latest('canceled_at')
            ->first();
    }
}
