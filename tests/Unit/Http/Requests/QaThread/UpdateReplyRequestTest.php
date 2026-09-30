<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\QaThread;

use App\Http\Requests\QaThread\UpdateReplyRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * 質問掲示板スレッド返信編集 UpdateReplyRequest の rules() を検証する Unit テスト。
 * body (required + max:5000) を網羅する。
 */
class UpdateReplyRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_passes_with_valid_payload(): void
    {
        $validator = Validator::make([
            'body' => 'Policyの使い方について教えていただきありがとうございます。',
        ], (new UpdateReplyRequest)->rules());

        $this->assertTrue($validator->passes());
    }

    public function test_fails_when_body_missing(): void
    {
        $validator = Validator::make(
            [],
            (new UpdateReplyRequest)->rules(),
        );

        $this->assertArrayHasKey(
            'body',
            $validator->errors()->toArray(),
        );
    }

    public function test_fails_when_body_too_long(): void
    {
        $validator = Validator::make([
            'body' => str_repeat('a', 5001),
        ], (new UpdateReplyRequest)->rules());

        $this->assertArrayHasKey(
            'body',
            $validator->errors()->toArray(),
        );
    }
}
