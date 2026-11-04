<?php

declare(strict_types=1);

namespace App\Http\Requests\MeetingQuota;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('purchase-meeting-pack', $user);
    }

    public function rules(): array
    {
        return [
            'meeting_pack_id' => [
                'required',
                'string',
                Rule::exists('meeting_packs', 'id')
                    ->where('status', 'published'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'meeting_pack_id.required' => '購入する面談パックを選択してください。',
            'meeting_pack_id.exists' => '選択した面談パックは購入できません。',
        ];
    }
}
