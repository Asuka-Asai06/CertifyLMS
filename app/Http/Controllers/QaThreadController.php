<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CertificationStatus;
use App\Enums\QaThreadStatus;
use App\Enums\UserRole;
use App\Http\Requests\QaThread\IndexRequest;
use App\Http\Requests\QaThread\StoreThreadRequest;
use App\Http\Requests\QaThread\UpdateThreadRequest;
use App\Models\Certification;
use App\Models\QaThread;
use App\UseCases\QaThread\StoreAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * QaThread Controller。受講生 / コーチ / admin 共通で利用される。
 *
 * - index: 質問スレッド一覧を表示。閲覧者の role に応じて閲覧可能な質問を取得し、資格 / 解決状態 / キーワードで絞り込み可能。
 * - show: 質問スレッド詳細と返信一覧を表示。
 * - create: 受講生の質問投稿画面を表示する。
 * - store: 受講生が新しい質問を投稿する。
 * - edit / update: 質問投稿者が自分の質問を編集する。
 * - destroy: 質問投稿者またはadminが質問を削除する。
 * - resolve / unresolve: 質問投稿者が解決状態を変更する。
 */
class QaThreadController extends Controller
{
    public function index(IndexRequest $request): View
    {
        $viewer = $request->user();
        $filters = $request->filters();

        $status = $filters['status'] !== null
            ? QaThreadStatus::tryFrom($filters['status'])
            : null;

        $threads = QaThread::query()
            ->visibleTo($viewer)
            ->byCertification($filters['certification_id'])
            ->byStatus($status)
            ->keyword($filters['keyword'])
            ->with(['user', 'certification'])
            ->withCount('replies')
            ->latestFirst()
            ->paginate(20)
            ->withQueryString();

        $certifications = Certification::query()
            ->when(
                $viewer->role !== UserRole::Admin,
                fn ($query) => $query->where(
                    'status',
                    CertificationStatus::Published->value,
                ),
            )
            ->orderBy('name')
            ->get();

        $isAdminContext = $viewer->role === UserRole::Admin;

        return view('qa-thread.index', [
            'viewer' => $viewer,
            'isAdminContext' => $isAdminContext,
            'threads' => $threads,
            'filters' => $filters,
            'certifications' => $certifications,
            'publishedStatus' => CertificationStatus::Published,
        ]);
    }

    public function show(QaThread $thread): View
    {
        $this->authorize('view', $thread);

        $thread->load([
            'user',
            'certification',
            'replies.user',
        ]);

        return view('qa-thread.show', [
            'thread' => $thread,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', QaThread::class);

        $certifications = Certification::query()
            ->where(
                'status',
                CertificationStatus::Published->value,
            )
            ->orderBy('name')
            ->get();

        return view('qa-thread.create', [
            'certifications' => $certifications,
        ]);
    }

    public function store(
        StoreThreadRequest $request,
        StoreAction $action,
    ): RedirectResponse {
        $thread = $action(
            $request->user(),
            $request->validated(),
        );

        return redirect()
            ->route('qa-board.index')
            ->with('success', '質問を投稿しました。');
    }

    public function edit(QaThread $thread): View
    {
        $this->authorize('update', $thread);

        return view('qa-thread.edit', [
            'thread' => $thread,
        ]);
    }

    public function update(
        UpdateThreadRequest $request,
        QaThread $thread,
    ): RedirectResponse {
        $thread->update($request->validated());

        return redirect()
            ->route('qa-board.show', $thread)
            ->with('success', '質問を更新しました。');
    }

    public function destroy(QaThread $thread): RedirectResponse
    {
        $this->authorize('delete', $thread);

        $thread->delete();

        $indexRoute = request()->routeIs('admin.*')
            ? 'admin.qa-board.index'
            : 'qa-board.index';

        return redirect()
            ->route($indexRoute)
            ->with('success', '質問を削除しました。');
    }

    public function resolve(QaThread $thread): RedirectResponse
    {
        $this->authorize('resolve', $thread);

        $thread->update([
            'status' => QaThreadStatus::Resolved,
            'resolved_at' => now(),
        ]);

        return redirect()
            ->route('qa-board.show', $thread)
            ->with('success', '質問を解決済みにしました。');
    }

    public function unresolve(QaThread $thread): RedirectResponse
    {
        $this->authorize('unresolve', $thread);

        $thread->update([
            'status' => QaThreadStatus::Unresolved,
            'resolved_at' => null,
        ]);

        return redirect()
            ->route('qa-board.show', $thread)
            ->with('success', '質問を未解決に戻しました。');
    }
}
