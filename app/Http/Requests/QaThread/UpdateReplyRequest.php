<?php

declare(strict_types=1);

namespace App\Http\Requests\QaThread;

use App\Models\QaReply;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 質問掲示板の返信を編集する際の入力検証。
 *
 * 返信の編集権限は、QaReplyPolicy によって判定する。
 */
class UpdateReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $reply = $this->route('reply');

        return $reply instanceof QaReply
            && $this->user()?->can('update', $reply) === true;
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
