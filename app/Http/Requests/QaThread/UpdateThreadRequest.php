<?php

declare(strict_types=1);

namespace App\Http\Requests\QaThread;

use App\Models\QaThread;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 質問掲示板の投稿を編集する際の入力検証。
 *
 * 質問の作成権限はQaThreadPolicy によって判定する。
 */
class UpdateThreadRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var QaThread|null $thread */
        $thread = $this->route('thread');

        if ($thread === null) {
            return false;
        }

        return $this->user()?->can(
            'update',
            $thread,
        ) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
        ];
    }
}
