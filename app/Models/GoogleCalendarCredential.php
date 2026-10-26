<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * コーチの Google カレンダー連携情報。
 * OAuth トークンと連携対象カレンダーを保存する。
 */
class GoogleCalendarCredential extends Model
{
    use HasFactory,HasUlids;

    /**
     * 一括代入を許可する属性。
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'access_token',
        'refresh_token',
        'expires_at',
        'calendar_id',
        'connected_at',
    ];

    /**
     * この連携情報を所有するユーザー。
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 日時カラムの型変換。
     *
     * @return array<string, string>
     */
    protected $casts = [
        'expires_at' => 'datetime',
        'connected_at' => 'datetime',
    ];
}
