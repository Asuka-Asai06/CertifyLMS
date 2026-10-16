<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;

/**
 * アバター画像のアップロードリクエスト。png / jpg / jpeg / webp の 2MB 以下に制限する。
 */
class StoreAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && in_array($user->status, [
                UserStatus::InProgress,
                UserStatus::Graduated,
            ], true);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'avatar' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'avatar' => 'アバター画像',
        ];
    }
}
