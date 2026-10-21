<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EnrollmentStatus;
use App\Http\Requests\Meeting\AvailabilityRequest;
use App\Http\Requests\Meeting\IndexAsCoachRequest;
use App\Http\Requests\Meeting\IndexRequest;
use App\Http\Requests\Meeting\StoreRequest;
use App\Http\Requests\Meeting\UpsertMemoRequest;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Services\MeetingQuotaService;
use App\UseCases\Meeting\CancelAction;
use App\UseCases\Meeting\CreateFallbackAction;
use App\UseCases\Meeting\FetchAvailabilityAction;
use App\UseCases\Meeting\IndexAction;
use App\UseCases\Meeting\IndexAsCoachAction;
use App\UseCases\Meeting\ShowAction;
use App\UseCases\Meeting\StoreAction;
use App\UseCases\Meeting\UpsertMemoAction;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * 1on1 面談予約 (Meeting) の HTTP エントリポイント。
 *
 * 受講生視点(index / show / create / store / cancel / fetchAvailability)とコーチ視点
 * (indexAsCoach / upsertMemo)を 1 Controller に集約する。予約 / キャンセル / メモ保存の
 * 状態変更系は残面談回数の消費・返却、通知発火、トランザクション境界を method 内で扱い、
 * 取得系はクエリ組み立てを method 内で行う。認可は $this->authorize() または FormRequest::authorize()。
 */
class MeetingController extends Controller
{
    /**
     * 受講生本人の面談一覧。
     */
    public function index(IndexRequest $request, IndexAction $indexAction): View
    {
        return view(
            'meeting.index',
            $indexAction(
                $request->user(),
                $request->validated('filter'),
            ),
        );
    }

    /**
     * コーチ宛の面談一覧。
     */
    public function indexAsCoach(IndexAsCoachRequest $request, IndexAsCoachAction $indexAsCoachAction): View
    {
        return view(
            'meeting.coach.index',
            $indexAsCoachAction(
                $request->user(),
                $request->validated(),
            ),
        );
    }

    /**
     * 面談詳細(当事者共通)。Policy で coach/student の閲覧範囲を絞る。
     */
    public function show(Meeting $meeting, ShowAction $showAction): View
    {
        $this->authorize('view', $meeting);

        return view('meeting.show', [
            'meeting' => $showAction($meeting),
        ]);
    }

    /**
     * 予約画面(受講生): URL に Enrollment を含む正規ルートで表示する。
     */
    public function create(Enrollment $enrollment, MeetingQuotaService $meetingQuota): View
    {
        $this->authorize('create', Meeting::class);

        abort_unless($enrollment->user_id === auth()->id(), 403);
        abort_unless($enrollment->status === EnrollmentStatus::Learning, 403);

        $enrollment->loadMissing('certification');

        return view('meeting.create', [
            'enrollment' => $enrollment,
            'meetingsRemaining' => $meetingQuota->remaining(auth()->user()),
        ]);
    }

    /**
     * 予約画面のエントリポイント(URL に Enrollment 無し)。
     * `resolve-default-enrollment` Middleware が default 資格に redirect するため、
     * 本 method に到達するのは default 未設定 + 残存 Enrollment が 0 件 or 2+ 件のケース。
     */
    public function createFallback(CreateFallbackAction $createFallbackAction): View
    {
        return view('meeting.empty-state', [
            'enrollments' => $createFallbackAction(auth()->user()),
        ]);
    }

    /**
     * 受講生の予約申請。
     */
    public function store(
        Enrollment $enrollment,
        StoreRequest $request,
        StoreAction $storeAction,
    ): RedirectResponse {
        $meeting = $storeAction($enrollment, $request->validated());

        return redirect()
            ->route('meetings.show', $meeting)
            ->with('success', '面談を予約しました。');
    }

    /**
     * 当事者による面談キャンセル。
     */
    public function cancel(
        Meeting $meeting,
        CancelAction $cancelAction,
    ): RedirectResponse {
        $this->authorize('cancel', $meeting);

        $actor = auth()->user();

        abort_if($actor === null, 403);

        $cancelAction($meeting, $actor);

        return redirect()
            ->route('meetings.show', $meeting)
            ->with('success', '面談をキャンセルしました。面談回数を返却しました。');
    }

    /**
     * 担当コーチによる面談メモ作成・更新。canceled の面談にはメモを残せない。
     */
    public function upsertMemo(
        Meeting $meeting,
        UpsertMemoRequest $request,
        UpsertMemoAction $action,
    ): RedirectResponse {
        $action($meeting, $request->validated('body'));

        return redirect()
            ->route('meetings.show', $meeting)
            ->with('success', '面談メモを保存しました。');
    }

    /**
     * 予約画面が呼ぶ空き枠取得 JSON エンドポイント。
     */
    public function fetchAvailability(
        Enrollment $enrollment,
        AvailabilityRequest $request,
        FetchAvailabilityAction $action,
    ): JsonResponse {
        $date = Carbon::parse($request->validated('date'));
        $slots = $action($enrollment, $date);

        return response()->json([
            'date' => $date->toDateString(),
            'slots' => $slots->map(fn (array $slot) => [
                'slot_start' => $slot['slot_start']->toIso8601String(),
                'slot_end' => $slot['slot_end']->toIso8601String(),
                'available_coach_count' => $slot['available_coach_count'],
            ])->all(),
        ]);
    }
}
