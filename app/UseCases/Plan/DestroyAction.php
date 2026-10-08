<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Exceptions\Plan\PlanNotDeletableException;
use App\Models\Plan;
use Illuminate\Support\Facades\DB;

/**
 * プランを削除するユースケース。
 *
 * 下書き状態かつ受講者が紐づいていない場合のみ削除可能。
 * UserPlanLog から参照されている場合は外部キー制約(restrictOnDelete)で
 * 削除が阻止されるため、履歴の整合性は DB レベルで保護される。
 */
final class DestroyAction
{
    /**
     * @throws PlanNotDeletableException
     */
    public function __invoke(Plan $plan): void
    {
        if ($plan->status !== PlanStatus::Draft) {
            throw new PlanNotDeletableException(
                '下書き状態のプランのみ削除できます。先に下書きに戻すか、アーカイブを利用してください。'
            );
        }

        if ($plan->users()->exists()) {
            throw new PlanNotDeletableException(
                'このプランは受講者が紐づいているため削除できません。'
            );
        }

        DB::transaction(fn () => $plan->delete());
    }
}
