<?php

declare(strict_types=1);

namespace App\Http\Requests\QaThread;

use App\Models\QaReply;
use App\Models\QaThread;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 質問掲示板に返信する際の入力検証。
 *
 * 返信対象の質問に対する返信権限は、
 * QaReplyPolicy によって判定する。
 */
class StoreReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $thread = $this->route('thread');

        return $thread instanceof QaThread
            && $this->user()?->can(
                'create',
                [QaReply::class, $thread],
            ) === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
        ];
    }
}
