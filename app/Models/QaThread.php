<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CertificationStatus;
use App\Enums\QaThreadStatus;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QaThread extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'user_id',
        'certification_id',
        'title',
        'body',
        'status',
        'resolved_at',
    ];

    protected $casts = [
        'status' => QaThreadStatus::class,
        'resolved_at' => 'datetime',
    ];

    /**
     * 質問の投稿者。
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)
            ->withTrashed();
    }

    /**
     * 質問対象の資格。
     *
     * @return BelongsTo<Certification, $this>
     */
    public function certification(): BelongsTo
    {
        return $this->belongsTo(Certification::class);
    }

    /**
     * 質問に対する回答。
     *
     * @return HasMany<QaReply, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(QaReply::class);
    }

    /**
     * 学生が閲覧できる質問に絞り込む。
     * 公開中の資格に紐づく質問のみを対象とする。
     */
    public function scopeVisibleToStudent(Builder $query): Builder
    {
        return $query->whereHas(
            'certification',
            fn (Builder $q): Builder => $q->where(
                'status',
                CertificationStatus::Published,
            ),
        );
    }

    /**
     * コーチが閲覧できる質問に絞り込む。
     * 現在担当している資格に紐づく質問のみを対象とする。
     *
     *  * @param User $coach 閲覧者となるコーチ
     */
    public function scopeVisibleToCoach(Builder $query, User $coach): Builder
    {
        return $query->whereHas(
            'certification',
            fn (Builder $q): Builder => $q
                ->where(
                    'status',
                    CertificationStatus::Published,
                )
                ->assignedTo($coach),
        );
    }

    /**
     * ユーザーのロールに応じて閲覧可能な質問に絞り込む。
     * Adminは全資格の質問を閲覧できる。
     *
     * @param User $user 閲覧者
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return match ($user->role) {
            UserRole::Admin => $query,
            UserRole::Coach => $query->visibleToCoach($user),
            UserRole::Student => $query->visibleToStudent(),
        };
    }

    /**
     * 質問のステータスで絞り込む。
     *
     * @param QaThreadStatus|null $status 絞り込むステータス
     */
    public function scopeByStatus(Builder $query, ?QaThreadStatus $status): Builder
    {
        return $status === null
            ? $query
            : $query->where('status', $status->value);
    }

    /**
     * 資格で絞り込む。
     *
     * @param string|null $certificationId 資格id
     */
    public function scopeByCertification(Builder $query, ?string $certificationId): Builder
    {
        return $certificationId === null
            ? $query
            : $query->where('certification_id', $certificationId);
    }

    /**
     * スレッドタイトル、スレッド本文、配下回答本文に
     * キーワードを含む質問に絞り込む。
     *
     * キーワードが未指定の場合は絞り込みを行わない。
     *
     * @param string|null $keyword 検索キーワード
     */
    public function scopeKeyword(
        Builder $query,
        ?string $keyword,
    ): Builder {
        if ($keyword === null || $keyword === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($keyword): void {
            $q->where('title', 'like', '%'.$keyword.'%')
                ->orWhere('body', 'like', '%'.$keyword.'%')
                ->orWhereHas(
                    'replies',
                    fn (Builder $replyQuery): Builder => $replyQuery->where(
                        'body',
                        'like',
                        '%'.$keyword.'%',
                    ),
                );
        });
    }

    /**
     * 新しい質問から順番に並べる。
     */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('created_at');
    }
}
