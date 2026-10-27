<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnrollmentGoal extends Model
{
    use HasFactory, HasUlids;

    /**
     * 一括代入を許可する属性。
     *
     * @var list<string>
     */
    protected $fillable = [
        'enrollment_id',
        'title',
        'target_date',
        'description',
        'achieved_at',
    ];

    /**
     * 属性の型変換。
     *
     * @var array<string, string>
     */
    protected $casts = [
        'target_date' => 'date',
        'achieved_at' => 'datetime',
    ];

    /**
     * この目標が属する受講登録。
     *
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /**
     * 個人目標を表示順に並べる。
     *
     * 未達成を先にし、目標期日の昇順、作成日時の降順とする。
     * 目標期日が未設定の目標は、期日が設定された目標より後にする。
     *
     * @param Builder<static> $query
     *
     * @return Builder<static>
     */
    public function scopeDisplayOrder(Builder $query): Builder
    {
        return $query
            ->orderByRaw('CASE WHEN achieved_at IS NULL THEN 0 ELSE 1 END')
            ->orderByRaw('CASE WHEN target_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('target_date')
            ->orderByDesc('created_at');
    }

    /**
     * 個人目標が達成済みか判定する。
     */
    public function isAchieved(): bool
    {
        return $this->achieved_at !== null;
    }
}
