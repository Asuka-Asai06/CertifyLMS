<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\QaThread;

use App\Http\Requests\QaThread\UpdateThreadRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * 質問掲示板スレッド投稿編集 UpdateThreadRequest の rules() を検証する Unit テスト。
 * title (required + max:200) / body (required + max:5000) を網羅する。
 */
class UpdateThreadRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_passes_with_valid_payload(): void
    {
        $validator = Validator::make([
            'title' => 'Laravelについて質問があります',
            'body' => 'Policyの使い方について教えてください。',
        ], (new UpdateThreadRequest)->rules());

        $this->assertTrue($validator->passes());
    }

    public function test_fails_when_title_missing(): void
    {
        $validator = Validator::make([
            'body' => 'Policyの使い方について教えてください。',
        ], (new UpdateThreadRequest)->rules());

        $this->assertArrayHasKey(
            'title',
            $validator->errors()->toArray(),
        );
    }

    public function test_fails_when_title_too_long(): void
    {
        $validator = Validator::make([
            'title' => str_repeat('a', 201),
            'body' => 'Policyの使い方について教えてください。',
        ], (new UpdateThreadRequest)->rules());

        $this->assertArrayHasKey(
            'title',
            $validator->errors()->toArray(),
        );
    }

    public function test_fails_when_body_missing(): void
    {
        $validator = Validator::make([
            'title' => 'Laravelについて質問があります',
        ], (new UpdateThreadRequest)->rules());

        $this->assertArrayHasKey(
            'body',
            $validator->errors()->toArray(),
        );
    }

    public function test_fails_when_body_too_long(): void
    {
        $validator = Validator::make([
            'title' => 'Laravelについて質問があります',
            'body' => str_repeat('a', 5001),
        ], (new UpdateThreadRequest)->rules());

        $this->assertArrayHasKey(
            'body',
            $validator->errors()->toArray(),
        );
    }
}
