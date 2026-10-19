<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\Announcement;

use App\Enums\AnnouncementTargetType;
use App\Models\Certification;
use App\Models\User;
use App\Http\Requests\Announcement\StoreRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * お知らせ配信 StoreRequest の rules() を検証する Unit テスト。
 *
 * タイトル・本文の必須および文字数上限、配信対象の種別と ID の必須条件、
 * 公開中の資格および受講中の受講生に限定する条件を Validator::make で検証する。
 */

class StoreRequestTest extends TestCase
{
    use RefreshDatabase;

    /**
     * バリデーションを実行する。
     *
     * @param array<string, mixed> $data
     */
    private function validateData(array $data): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($data, (new StoreRequest())->rules());
    }

    /**
     * 全受講生向けのお知らせに必要な入力値を返す。
     *
     * @return array<string, mixed>
     */
    private function validAllStudentsData(): array
    {
        return [
            'title' => 'メンテナンスのお知らせ',
            'body' => 'システムメンテナンスを実施します。',
            'target_type' => AnnouncementTargetType::AllStudents->value,
            'target_certification_id' => null,
            'target_user_id' => null,
        ];
    }

    public function test_all_students_announcement_passes_validation(): void
    {
        $validator = $this->validateData($this->validAllStudentsData());

        $this->assertFalse($validator->fails());
    }

    public function test_title_is_required(): void
    {
        $data = $this->validAllStudentsData();
        $data['title'] = '';

        $validator = $this->validateData($data);

        $this->assertTrue($validator->errors()->has('title'));
    }

    public function test_title_cannot_exceed_200_characters(): void
    {
        $data = $this->validAllStudentsData();
        $data['title'] = str_repeat('あ', 201);

        $validator = $this->validateData($data);

        $this->assertTrue($validator->errors()->has('title'));
    }

    public function test_body_is_required(): void
    {
        $data = $this->validAllStudentsData();
        $data['body'] = '';

        $validator = $this->validateData($data);

        $this->assertTrue($validator->errors()->has('body'));
    }

    public function test_body_cannot_exceed_5000_characters(): void
    {
        $data = $this->validAllStudentsData();
        $data['body'] = str_repeat('あ', 5001);

        $validator = $this->validateData($data);

        $this->assertTrue($validator->errors()->has('body'));
    }

    public function test_target_type_is_required(): void
    {
        $data = $this->validAllStudentsData();
        $data['target_type'] = '';

        $validator = $this->validateData($data);

        $this->assertTrue($validator->errors()->has('target_type'));
    }

    public function test_invalid_target_type_fails_validation(): void
    {
        $data = $this->validAllStudentsData();
        $data['target_type'] = 'invalid';

        $validator = $this->validateData($data);

        $this->assertTrue($validator->errors()->has('target_type'));
    }

    public function test_certification_target_requires_certification_id(): void
    {
        $data = $this->validAllStudentsData();
        $data['target_type'] = AnnouncementTargetType::Certification->value;

        $validator = $this->validateData($data);

        $this->assertTrue(
            $validator->errors()->has('target_certification_id'),
        );
    }

    public function test_published_certification_passes_validation(): void
    {
        $certification = Certification::factory()->published()->create();

        $data = $this->validAllStudentsData();
        $data['target_type'] = AnnouncementTargetType::Certification->value;
        $data['target_certification_id'] = $certification->id;

        $validator = $this->validateData($data);

        $this->assertFalse($validator->fails());
    }

    #[DataProvider('unpublishedCertificationStates')]
    public function test_non_published_certification_fails_validation(
        string $state,
    ): void {
        $certification = Certification::factory()->{$state}()->create();

        $data = $this->validAllStudentsData();
        $data['target_type'] = AnnouncementTargetType::Certification->value;
        $data['target_certification_id'] = $certification->id;

        $validator = $this->validateData($data);

        $this->assertTrue(
            $validator->errors()->has('target_certification_id'),
        );
    }

    /**
     * 公開中以外の資格のファクトリ状態。
     *
     * @return array<string, array{string}>
     */
    public static function unpublishedCertificationStates(): array
    {
        return [
            'draft certification' => ['draft'],
            'archived certification' => ['archived'],
        ];
    }

    public function test_nonexistent_certification_fails_validation(): void
    {
        $data = $this->validAllStudentsData();
        $data['target_type'] = AnnouncementTargetType::Certification->value;
        $data['target_certification_id'] = '01J00000000000000000000000';

        $validator = $this->validateData($data);

        $this->assertTrue(
            $validator->errors()->has('target_certification_id'),
        );
    }

    public function test_user_target_requires_user_id(): void
    {
        $data = $this->validAllStudentsData();
        $data['target_type'] = AnnouncementTargetType::User->value;

        $validator = $this->validateData($data);

        $this->assertTrue($validator->errors()->has('target_user_id'));
    }

    public function test_in_progress_student_passes_validation(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $data = $this->validAllStudentsData();
        $data['target_type'] = AnnouncementTargetType::User->value;
        $data['target_user_id'] = $student->id;

        $validator = $this->validateData($data);

        $this->assertFalse($validator->fails());
    }

    #[DataProvider('invalidRecipientStates')]
    public function test_user_who_is_not_an_in_progress_student_fails_validation(
        string $role,
        string $status,
    ): void {
        $user = User::factory()->{$role}()->{$status}()->create();

        $data = $this->validAllStudentsData();
        $data['target_type'] = AnnouncementTargetType::User->value;
        $data['target_user_id'] = $user->id;

        $validator = $this->validateData($data);

        $this->assertTrue($validator->errors()->has('target_user_id'));
    }

    /**
     * 個別配信の対象にできないユーザー状態。
     *
     * @return array<string, array{string, string}>
     */
    public static function invalidRecipientStates(): array
    {
        return [
            'invited student' => ['student', 'invited'],
            'graduated student' => ['student', 'graduated'],
            'withdrawn student' => ['student', 'withdrawn'],
            'coach' => ['coach', 'inProgress'],
            'admin' => ['admin', 'inProgress'],
        ];
    }

    public function test_nonexistent_user_fails_validation(): void
    {
        $data = $this->validAllStudentsData();
        $data['target_type'] = AnnouncementTargetType::User->value;
        $data['target_user_id'] = '01J00000000000000000000000';

        $validator = $this->validateData($data);

        $this->assertTrue($validator->errors()->has('target_user_id'));
    }
}