<?php

declare(strict_types=1);

namespace App\Exceptions\AiChat;

use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * AI チャットの日次送信上限を超過した際の例外（HTTP 429）。
 */
final class AiChatDailyLimitExceededException extends TooManyRequestsHttpException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(
            null,
            '本日のAIチャット送信上限に達しました。明日以降にお試しください。',
            $previous,
        );
    }
}
