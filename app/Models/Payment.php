<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Payment extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'user_id',
        'meeting_pack_id',
        'meeting_pack_name',
        'quantity',
        'amount',
        'status',
        'stripe_checkout_session_id',
        'stripe_payment_intent_id',
        'paid_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'amount' => 'integer',
        'status' => PaymentStatus::class,
        'paid_at' => 'datetime',
    ];

    /**
     * 購入した受講生を取得する。
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 購入元の面談パックを取得する。
     */
    public function meetingPack(): BelongsTo
    {
        return $this->belongsTo(MeetingPack::class);
    }

    /**
     * この購入に紐づく面談回数の取引履歴を取得する。
     */
    public function quotaTransactions(): HasMany
    {
        return $this->hasMany(
            MeetingQuotaTransaction::class,
            'related_payment_id',
        );
    }
}
