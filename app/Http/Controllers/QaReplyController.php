<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\QaThread\StoreReplyRequest;
use App\Http\Requests\QaThread\UpdateReplyRequest;
use App\Models\QaReply;
use App\Models\QaThread;
use App\UseCases\QaReply\StoreAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * QaReply Controller。受講生 / コーチ / admin 共通で利用される。
 *
 * - store: 受講生 / コーチが質問に返信する。
 * - edit: 返信投稿者が自分の返信を編集する画面を表示する。
 * - update: 返信投稿者が自分の返信を更新する。
 * - destroy: 返信投稿者またはadminが返信を削除する。
 *
 * admin は返信の作成・編集を行わず、削除のみ可能。
 */
class QaReplyController extends Controller
{
    public function store(
        QaThread $thread,
        StoreReplyRequest $request,
        StoreAction $action,
    ): RedirectResponse {
        $thread = $request->route('thread');

        $action(
            $request->user(),
            $thread,
            $request->validated(),
        );

        return redirect()
            ->route('qa-board.show', $thread)
            ->with('success', '回答を投稿しました。');
    }

    public function edit(QaReply $reply): View
    {
        $this->authorize('update', $reply);

        return view('qa-thread.reply-edit', [
            'reply' => $reply,
        ]);
    }

    public function update(
        UpdateReplyRequest $request,
        QaReply $reply,
    ): RedirectResponse {
        $reply->update($request->validated());

        return redirect()
            ->route('qa-board.show', $reply->thread)
            ->with('success', '回答を更新しました。');
    }

    public function destroy(QaReply $reply): RedirectResponse
    {
        $this->authorize('delete', $reply);

        $thread = $reply->thread;

        $reply->delete();

        $showRoute = request()->routeIs('admin.*')
            ? 'admin.qa-board.show'
            : 'qa-board.show';

        return redirect()
            ->route($showRoute, $thread)
            ->with('success', '回答を削除しました。');
    }
}
