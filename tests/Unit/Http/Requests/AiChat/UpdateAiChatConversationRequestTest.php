<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\AiChat;

use App\Http\Requests\AiChat\UpdateAiChatConversationRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * UpdateAiChatConversationRequest の rules() を検証する Unit テスト。
 *
 * 会話タイトルの必須条件、文字列型および最大文字数を検証する。
 */
class UpdateAiChatConversationRequestTest extends TestCase
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
            (new UpdateAiChatConversationRequest)->rules(),
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
            'title' => 'Laravelについての相談',
        ];
    }

    public function test_valid_title_passes_validation(): void
    {
        $validator = $this->validateData($this->validData());

        $this->assertFalse($validator->fails());
    }

    public function test_title_is_required(): void
    {
        $data = $this->validData();
        $data['title'] = '';

        $validator = $this->validateData($data);

        $this->assertTrue($validator->errors()->has('title'));
    }

    public function test_title_cannot_be_omitted(): void
    {
        $data = $this->validData();
        unset($data['title']);

        $validator = $this->validateData($data);

        $this->assertTrue($validator->errors()->has('title'));
    }

    public function test_title_cannot_be_null(): void
    {
        $data = $this->validData();
        $data['title'] = null;

        $validator = $this->validateData($data);

        $this->assertTrue($validator->errors()->has('title'));
    }

    public function test_title_must_be_a_string(): void
    {
        $data = $this->validData();
        $data['title'] = ['タイトル'];

        $validator = $this->validateData($data);

        $this->assertTrue($validator->errors()->has('title'));
    }

    public function test_title_can_contain_100_characters(): void
    {
        $data = $this->validData();
        $data['title'] = str_repeat('あ', 100);

        $validator = $this->validateData($data);

        $this->assertFalse($validator->fails());
    }

    public function test_title_cannot_exceed_100_characters(): void
    {
        $data = $this->validData();
        $data['title'] = str_repeat('あ', 101);

        $validator = $this->validateData($data);

        $this->assertTrue($validator->errors()->has('title'));
    }
}
