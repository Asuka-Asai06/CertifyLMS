<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\AiChat;

use App\Http\Requests\AiChat\StoreAiChatConversationRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * StoreAiChatConversationRequest の rules() を検証する Unit テスト。
 *
 * enrollment_id / chapter_id / section_id は任意の ULID 形式であること、
 * 初回メッセージは任意とし、指定時は文字列かつ2000文字以内であること、
 * source は full-screen または widget のいずれかであることを検証する。
 */
class StoreAiChatConversationRequestTest extends TestCase
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
            (new StoreAiChatConversationRequest)->rules(),
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
            'enrollment_id' => null,
            'chapter_id' => null,
            'section_id' => null,
            'message' => null,
        ];
    }

    public function test_all_fields_are_optional(): void
    {
        $validator = $this->validateData([]);

        $this->assertFalse($validator->fails());
    }

    public function test_null_values_pass_validation(): void
    {
        $validator = $this->validateData($this->validData());

        $this->assertFalse($validator->fails());
    }

    public function test_valid_ulid_enrollment_id_passes_validation(): void
    {
        $data = $this->validData();
        $data['enrollment_id'] = (string) Str::ulid();

        $validator = $this->validateData($data);

        $this->assertFalse($validator->fails());
    }

    public function test_invalid_enrollment_id_fails_validation(): void
    {
        $data = $this->validData();
        $data['enrollment_id'] = 'invalid-ulid';

        $validator = $this->validateData($data);

        $this->assertTrue($validator->errors()->has('enrollment_id'));
    }

    public function test_valid_ulid_chapter_id_passes_validation(): void
    {
        $data = $this->validData();
        $data['chapter_id'] = (string) Str::ulid();

        $validator = $this->validateData($data);

        $this->assertFalse($validator->fails());
    }

    public function test_invalid_chapter_id_fails_validation(): void
    {
        $data = $this->validData();
        $data['chapter_id'] = 'invalid-ulid';

        $validator = $this->validateData($data);

        $this->assertTrue($validator->errors()->has('chapter_id'));
    }

    public function test_valid_ulid_section_id_passes_validation(): void
    {
        $data = $this->validData();
        $data['section_id'] = (string) Str::ulid();

        $validator = $this->validateData($data);

        $this->assertFalse($validator->fails());
    }

    public function test_invalid_section_id_fails_validation(): void
    {
        $data = $this->validData();
        $data['section_id'] = 'invalid-ulid';

        $validator = $this->validateData($data);

        $this->assertTrue($validator->errors()->has('section_id'));
    }

    public function test_message_can_be_omitted(): void
    {
        $data = $this->validData();
        unset($data['message']);

        $validator = $this->validateData($data);

        $this->assertFalse($validator->fails());
    }

    public function test_message_can_be_null(): void
    {
        $data = $this->validData();
        $data['message'] = null;

        $validator = $this->validateData($data);

        $this->assertFalse($validator->fails());
    }

    public function test_message_string_passes_validation(): void
    {
        $data = $this->validData();
        $data['message'] = 'Laravelについて質問です。';

        $validator = $this->validateData($data);

        $this->assertFalse($validator->fails());
    }

    public function test_message_can_contain_2000_characters(): void
    {
        $data = $this->validData();
        $data['message'] = str_repeat('あ', 2000);

        $validator = $this->validateData($data);

        $this->assertFalse($validator->fails());
    }

    public function test_message_cannot_exceed_2000_characters(): void
    {
        $data = $this->validData();
        $data['message'] = str_repeat('あ', 2001);

        $validator = $this->validateData($data);

        $this->assertTrue($validator->errors()->has('message'));
    }

    public function test_message_must_be_a_string(): void
    {
        $data = $this->validData();
        $data['message'] = ['質問'];

        $validator = $this->validateData($data);

        $this->assertTrue($validator->errors()->has('message'));
    }

    public function test_full_screen_source_passes_validation(): void
    {
        $data = $this->validData();
        $data['source'] = 'full-screen';

        $validator = $this->validateData($data);

        $this->assertFalse($validator->fails());
    }

    public function test_widget_source_passes_validation(): void
    {
        $data = $this->validData();
        $data['source'] = 'widget';

        $validator = $this->validateData($data);

        $this->assertFalse($validator->fails());
    }

    public function test_invalid_source_fails_validation(): void
    {
        $data = $this->validData();
        $data['source'] = 'invalid-source';

        $validator = $this->validateData($data);

        $this->assertTrue($validator->errors()->has('source'));
    }

    public function test_source_must_be_a_string(): void
    {
        $data = $this->validData();
        $data['source'] = ['widget'];

        $validator = $this->validateData($data);

        $this->assertTrue($validator->errors()->has('source'));
    }
}
