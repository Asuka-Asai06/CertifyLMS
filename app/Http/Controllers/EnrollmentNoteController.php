<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\EnrollmentNote\StoreRequest;
use App\Http\Requests\EnrollmentNote\UpdateRequest;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\UseCases\EnrollmentNote\StoreAction;
use App\UseCases\EnrollmentNote\UpdateAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * 受講生メモ Controller。
 *
 * - admin: 全受講登録のメモを作成・編集・削除できる
 * - coach: 担当資格のメモを作成でき、自分のメモのみ編集・削除できる
 * - student: 操作不可
 *
 * 入力検証は FormRequest、認可は Policy、データ操作は Action に委譲する。
 */
class EnrollmentNoteController extends Controller
{
    public function store(
        StoreRequest $request,
        Enrollment $enrollment,
        StoreAction $action,
    ): RedirectResponse {
        $action($enrollment, $request->user(), $request->validated());

        return redirect()
            ->route('enrollments.show', $enrollment)
            ->with('success', '受講生メモを追加しました。');
    }

    public function edit(EnrollmentNote $note): View
    {
        $this->authorize('update', $note);

        return view('enrollment-note.edit', [
            'note' => $note,
        ]);
    }

    public function update(
        EnrollmentNote $note,
        UpdateRequest $request,
        UpdateAction $action,
    ): RedirectResponse {
        $action($note, $request->validated());

        return redirect()
            ->route('enrollments.show', $note->enrollment_id)
            ->with('success', '受講生メモを更新しました。');
    }

    public function destroy(EnrollmentNote $note): RedirectResponse
    {
        $this->authorize('delete', $note);

        $enrollmentId = $note->enrollment_id;

        $note->delete();

        return redirect()
            ->route('enrollments.show', $enrollmentId)
            ->with('success', '受講生メモを削除しました。');
    }
}
