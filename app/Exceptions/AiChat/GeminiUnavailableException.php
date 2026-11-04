<?php

declare(strict_types=1);

namespace App\Exceptions\AiChat;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * AI チャットを利用できない状態を表す例外（HTTP 503）。
 */
final class GeminiUnavailableException extends ServiceUnavailableHttpException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(
            null,
            '現在 AI チャットを利用できません。',
            $previous,
        );
    }
}
