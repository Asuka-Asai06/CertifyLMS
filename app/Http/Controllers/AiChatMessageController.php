<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\AiChat\GeminiApiException;
use App\Exceptions\AiChat\GeminiUnavailableException;
use App\Http\Requests\AiChat\StoreAiChatMessageRequest;
use App\Models\AiChatConversation;
use App\Services\GeminiService;
use App\UseCases\AiChat\SendMessageAction;
use Illuminate\Http\JsonResponse;

final class AiChatMessageController extends Controller
{
    public function store(
        StoreAiChatMessageRequest $request,
        AiChatConversation $conversation,
        SendMessageAction $action,
        GeminiService $geminiService,
    ): JsonResponse {
        try {
            $result = $action(
                $conversation,
                $request->validated(),
                $geminiService,
            );

            return response()->json($result);
        } catch (GeminiApiException $exception) {
            return response()->json([
                'message' => 'AI が応答できませんでした。再度お試しください。',
                'upstream_status' => $exception->upstreamStatus,
            ], 502);
        } catch (GeminiUnavailableException $exception) {
            return response()->json([
                'message' => '現在 AI チャットを利用できません。',
            ], 503);
        }
    }
}
