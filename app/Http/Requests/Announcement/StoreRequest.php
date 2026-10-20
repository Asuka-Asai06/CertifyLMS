<?php

declare(strict_types=1);

namespace App\Http\Requests\Announcement;

use App\Enums\AnnouncementTargetType;
use App\Enums\CertificationStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Announcement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * お知らせ配信リクエスト。
 * タイトル・本文・配信対象を入力し、対象種別に応じて資格または受講生を指定する。
 */
class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Announcement::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
            'target_type' => [
                'required',
                Rule::enum(AnnouncementTargetType::class),
            ],
            'target_certification_id' => [
                'nullable',
                'required_if:target_type,'.AnnouncementTargetType::Certification->value,
                'string',
                Rule::exists('certifications', 'id')
                    ->whereIn('status', [
                        CertificationStatus::Published->value,
                        CertificationStatus::Archived->value,
                    ]),
            ],
            'target_user_id' => [
                'nullable',
                'required_if:target_type,'.AnnouncementTargetType::User->value,
                'string',
                Rule::exists('users', 'id')
                    ->where('role', UserRole::Student->value)
                    ->where('status', UserStatus::InProgress->value)
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => 'タイトル',
            'body' => '本文',
            'target_type' => '配信対象',
            'target_certification_id' => '対象資格',
            'target_user_id' => '対象受講生',
        ];
    }
}
