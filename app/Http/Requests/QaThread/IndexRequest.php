<?php

declare(strict_types=1);

namespace App\Http\Requests\QaThread;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

/**
 * QaThread 一覧アクセスの入力検証。受講生 / コーチ / admin 共通で利用される。
 *
 * - 受講生 / コーチ: 閲覧可能な質問を一覧表示し、`certification_id` / `status` / `keyword` でフィルタ可
 * - admin: 全資格（公開停止・未公開を含む）の質問を横断表示し、`certification_id` / `status` / `keyword` でフィルタ可
 */
class IndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        $role = $this->user()?->role;

        return $role === UserRole::Student
            || $role === UserRole::Coach
            || $role === UserRole::Admin;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'certification_id' => ['nullable', 'ulid', 'exists:certifications,id'],
            'status' => ['nullable', 'string', 'in:unresolved,resolved'],
            'keyword' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return [
            'certification_id' => $this->input('certification_id'),
            'status' => $this->input('status'),
            'keyword' => $this->input('keyword'),
        ];
    }
}
