<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Notifications\AnnouncementNotification;
use App\Notifications\ChatMessageReceivedNotification;
use App\Notifications\MeetingCanceledNotification;
use App\Notifications\MeetingReminderNotification;
use App\Notifications\MeetingReservedNotification;
use App\Notifications\QaReplyReceivedNotification;
use App\Notifications\QueuedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

class QueuedNotificationTest extends TestCase
{
    /**
     * 対象通知が共通基底クラスを継承していることを確認する。
     *
     * @return array<string, array{class-string}>
     */
    public static function notificationProvider(): array
    {
        return [
            'chat message' => [ChatMessageReceivedNotification::class],
            'Q&A reply' => [QaReplyReceivedNotification::class],
            'meeting reserved' => [MeetingReservedNotification::class],
            'meeting canceled' => [MeetingCanceledNotification::class],
            'meeting reminder' => [MeetingReminderNotification::class],
            'announcement' => [AnnouncementNotification::class],
        ];
    }

    /**
     * 対象通知が共通の非同期設定を継承していることを確認する。
     *
     * @param class-string $notificationClass
     */
    #[DataProvider('notificationProvider')]
    public function test_notification_uses_shared_queue_configuration(
        string $notificationClass,
    ): void {
        $reflection = new ReflectionClass($notificationClass);

        $this->assertTrue(
            $reflection->isSubclassOf(QueuedNotification::class),
        );

        $this->assertTrue(
            $reflection->implementsInterface(ShouldQueue::class),
        );

        $this->assertSame(
            4,
            $reflection->getProperty('tries')->getDefaultValue(),
        );

        $this->assertSame(
            [10, 30, 60],
            $reflection->getProperty('backoff')->getDefaultValue(),
        );
    }
}
