<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\AiChat\StoreAiChatConversationRequest;
use App\Http\Requests\AiChat\UpdateAiChatConversationRequest;
use App\Models\AiChatConversation;
use App\UseCases\AiChat\CreateConversationAction;
use App\UseCases\AiChat\IndexAction;
use App\UseCases\AiChat\ShowConversationAction;
use App\UseCases\AiChat\UpdateConversationAction;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * 受講生向け AI チャット会話管理 Controller。
 *
 * AI チャット画面の表示と、会話の作成・表示・タイトル変更・削除を提供する。
 * 会話の取得や更新などの処理本体は各 Action が担い、
 * 会話の所有者に対する認可は Policy が担う。
 */
final class AiChatConversationController extends Controller
{
    public function index(Request $request, IndexAction $action): View
    {
        $user = $request->user();

        abort_unless($user !== null, 401);

        return view('ai-chat.empty-state', $action($user));
    }

    public function store(
        StoreAiChatConversationRequest $request,
        CreateConversationAction $action,
    ): RedirectResponse|JsonResponse {
        $user = $request->user();

        abort_unless($user !== null, 401);

        $result = $action($user, $request->validated());
        $conversation = $result['conversation'];

        if ($request->expectsJson()) {
            return response()->json([
                'conversation' => [
                    'id' => $conversation->id,
                ],
            ], 201);
        }

        return redirect()->route(
            'ai-chat.conversations.show',
            ['conversation' => $conversation],
        );
    }

    public function show(
        Request $request,
        AiChatConversation $conversation,
        ShowConversationAction $action,
    ): View|JsonResponse {
        $this->authorize('view', $conversation);

        $conversation = $action($conversation);

        if ($request->expectsJson()) {
            return response()->json([
                'messages' => $conversation->messages
                    ->map(fn ($message): array => [
                        'role' => $message->role->value,
                        'content' => $message->content,
                        'status' => $message->status->value,
                    ])
                    ->values(),
            ]);
        }

        return view('ai-chat.show', [
            'conversation' => $conversation,
        ]);
    }

    public function update(
        UpdateAiChatConversationRequest $request,
        AiChatConversation $conversation,
        UpdateConversationAction $action,
    ): RedirectResponse {

        $action($conversation, $request->validated());

        return redirect()
            ->route('ai-chat.conversations.show', ['conversation' => $conversation])
            ->with('success', '会話タイトルを変更しました。');
    }

    public function destroy(AiChatConversation $conversation): RedirectResponse
    {
        $this->authorize('delete', $conversation);

        $conversation->delete();

        return redirect()
            ->route('ai-chat.index')
            ->with('success', '会話を削除しました。');
    }
}
