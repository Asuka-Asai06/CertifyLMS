<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * キューで送信する通知の共通設定。
 *
 * - 通知送信を非同期化する
 * - 最大試行回数を初回を含めて4回とする
 * - 失敗時の待機時間を段階的に延ばす
 */
abstract class QueuedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * 初回を含む最大試行回数。
     */
    public int $tries = 4;

    /**
     * 再試行時の待機時間（秒）。
     *
     * @var array<int, int>
     */
    public array $backoff = [10, 30, 60];
}
