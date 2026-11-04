<?php

declare(strict_types=1);

namespace App\Exceptions\AiChat;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Gemini API との通信・応答取得に失敗した際の例外（HTTP 502）。
 */
final class GeminiApiException extends HttpException
{
    public function __construct(
        public readonly ?int $upstreamStatus = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            502,
            'Gemini API から正常な応答を取得できませんでした。',
            $previous,
        );
    }
}
