<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * AIチャットのメッセージ処理状態を表す Enum。
 *
 * - Pending: AIの応答処理中
 * - Completed: 処理完了
 * - Error: AIの応答処理失敗
 */
enum AiChatMessageStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Error = 'error';
}
