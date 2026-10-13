<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\EnrollmentGoal\StoreRequest;
use App\Http\Requests\EnrollmentGoal\UpdateRequest;
use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * 個人目標 Controller。受講登録に紐づく個人目標の管理機能を提供する。
 *
 * - student: 自分の受講登録に対する目標の作成 / 編集 / 削除 / 達成状態の変更
 * - coach: 担当資格に登録された受講生の目標閲覧のみ
 * - admin: 全受講生の目標閲覧のみ
 *
 * 作成 / 更新時の入力検証と認可は FormRequest に委譲する。
 * その他の操作の認可は EnrollmentGoalPolicy で判定する。
 * 達成状態は achieved_at の有無で管理する。
 *
 * 各操作の完了後は、対象の受講登録詳細ページへリダイレクトする。
 */
class EnrollmentGoalController extends Controller
{
    public function store(StoreRequest $request, Enrollment $enrollment): RedirectResponse
    {
        $enrollment->goals()->create($request->validated());

        return redirect()
            ->route('enrollments.show', $enrollment)
            ->with('success', '目標を登録しました。');
    }

    public function edit(EnrollmentGoal $goal): View
    {
        $this->authorize('update', $goal);

        return view('enrollment-goal.edit', [
            'goal' => $goal,
        ]);
    }

    public function update(UpdateRequest $request, EnrollmentGoal $goal): RedirectResponse
    {
        $goal->update($request->validated());

        return redirect()
            ->route('enrollments.show', $goal->enrollment_id)
            ->with('success', '目標を更新しました。');
    }

    public function destroy(EnrollmentGoal $goal): RedirectResponse
    {
        $this->authorize('delete', $goal);

        $enrollmentId = $goal->enrollment_id;

        $goal->delete();

        return redirect()
            ->route('enrollments.show', $enrollmentId)
            ->with('success', '目標を削除しました。');
    }

    public function markAchieved(EnrollmentGoal $goal): RedirectResponse
    {
        $this->authorize('markAchieved', $goal);

        $goal->update([
            'achieved_at' => now(),
        ]);

        return redirect()
            ->route('enrollments.show', $goal->enrollment_id)
            ->with('success', '目標を達成済みにしました。');
    }

    public function unmarkAchieved(EnrollmentGoal $goal): RedirectResponse
    {
        $this->authorize('unmarkAchieved', $goal);

        $goal->update([
            'achieved_at' => null,
        ]);

        return redirect()
            ->route('enrollments.show', $goal->enrollment_id)
            ->with('success', '目標を未達成に戻しました。');
    }
}
