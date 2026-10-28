<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\AiChat;

use App\Http\Requests\AiChat\StoreAiChatMessageRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * StoreAiChatMessageRequest の rules() を検証する Unit テスト。
 *
 * メッセージ本文の必須条件、文字列型、最小文字数および最大文字数を検証する。
 */
class StoreAiChatMessageRequestTest extends TestCase
{
    use RefreshDatabase;

    /**
     * バリデーションを実行する。
     *
     * @param array<string, mixed> $data
     */
    private function validateData(array $data): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make(
            $data,
            (new StoreAiChatMessageRequest)->rules(),
        );
    }

    /**
     * 有効な入力値を返す。
     *
     * @return array<string, mixed>
     */
    private function validData(): array
    {
        return [
            'content' => 'Laravelについて質問です。',
        ];
    }

    public function test_valid_content_passes_validation(): void
    {
        $validator = $this->validateData($this->validData());

        $this->assertFalse($validator->fails());
    }

    public function test_content_is_required(): void
    {
        $data = $this->validData();
        $data['content'] = '';

        $validator = $this->validateData($data);

        $this->assertTrue($validator->errors()->has('content'));
    }

    public function test_content_cannot_be_omitted(): void
    {
        $data = $this->validData();
        unset($data['content']);

        $validator = $this->validateData($data);

        $this->assertTrue($validator->errors()->has('content'));
    }

    public function test_content_cannot_be_null(): void
    {
        $data = $this->validData();
        $data['content'] = null;

        $validator = $this->validateData($data);

        $this->assertTrue($validator->errors()->has('content'));
    }

    public function test_content_must_be_a_string(): void
    {
        $data = $this->validData();
        $data['content'] = ['質問'];

        $validator = $this->validateData($data);

        $this->assertTrue($validator->errors()->has('content'));
    }

    public function test_content_can_contain_one_character(): void
    {
        $data = $this->validData();
        $data['content'] = 'あ';

        $validator = $this->validateData($data);

        $this->assertFalse($validator->fails());
    }

    public function test_content_cannot_exceed_2000_characters(): void
    {
        $data = $this->validData();
        $data['content'] = str_repeat('あ', 2001);

        $validator = $this->validateData($data);

        $this->assertTrue($validator->errors()->has('content'));
    }

    public function test_content_can_contain_2000_characters(): void
    {
        $data = $this->validData();
        $data['content'] = str_repeat('あ', 2000);

        $validator = $this->validateData($data);

        $this->assertFalse($validator->fails());
    }
}
