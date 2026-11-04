<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * AIチャットのメッセージ送信者を表す Enum。
 */
enum AiChatMessageRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
}
