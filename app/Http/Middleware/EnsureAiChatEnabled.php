<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AI チャット機能が無効な場合、アクセスを拒否する Middleware。
 *
 * config('ai-chat.enabled') が false の場合は 404 を返す。
 */
class EnsureAiChatEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('ai-chat.enabled', false), 404);

        return $next($request);
    }
}
