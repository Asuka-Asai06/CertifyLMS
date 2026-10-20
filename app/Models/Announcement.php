<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AnnouncementTargetType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Announcement extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'title',
        'body',
        'target_type',
        'target_certification_id',
        'target_user_id',
        'dispatched_count',
        'dispatched_at',
        'created_by',
    ];

    protected $casts = [
        'target_type' => AnnouncementTargetType::class,
        'dispatched_at' => 'datetime',
        'dispatched_count' => 'integer',
    ];

    public function targetCertification(): BelongsTo
    {
        return $this->belongsTo(Certification::class, 'target_certification_id');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
