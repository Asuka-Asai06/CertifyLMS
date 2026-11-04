<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\AiChat;

use App\Enums\EnrollmentStatus;
use App\Models\AiChatConversation;
use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Models\Section;
use App\Models\User;
use App\UseCases\AiChat\BuildAiChatContextAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BuildAiChatContextAction のテスト。
 */
class BuildAiChatContextActionTest extends TestCase
{
    use RefreshDatabase;

    private BuildAiChatContextAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = new BuildAiChatContextAction;
    }

    /**
     * 新規会話では受講中資格のみがコンテキストに含まれる。
     */
    public function test_new_conversation_context_contains_learning_certifications_only(): void
    {
        // Arrange
        $student = User::factory()->student()->create();

        $learningCertification = Certification::factory()->create([
            'name' => '受講中の資格',
            'description' => '受講中資格の説明',
        ]);

        $passedCertification = Certification::factory()->create([
            'name' => '修了済みの資格',
            'description' => '修了済み資格の説明',
        ]);

        Enrollment::factory()
            ->for($student)
            ->for($learningCertification)
            ->learning()
            ->create();

        Enrollment::factory()
            ->for($student)
            ->for($passedCertification)
            ->create([
                'status' => EnrollmentStatus::Passed,
            ]);

        $conversation = AiChatConversation::factory()
            ->for($student)
            ->create();

        // Act
        $result = ($this->action)($conversation);

        // Assert
        $this->assertNotNull($result);
        $this->assertStringContainsString('受講中の資格', $result);
        $this->assertStringContainsString('受講中資格の説明', $result);
        $this->assertStringNotContainsString('修了済みの資格', $result);
        $this->assertStringNotContainsString('修了済み資格の説明', $result);
    }

    /**
     * 受講中資格がない新規会話では null を返す。
     */
    public function test_new_conversation_context_returns_null_when_no_learning_certifications_exist(): void
    {
        // Arrange
        $student = User::factory()->student()->create();

        $conversation = AiChatConversation::factory()
            ->for($student)
            ->create();

        // Act
        $result = ($this->action)($conversation);

        // Assert
        $this->assertNull($result);
    }

    /**
     * 資格画面では本人の受講中資格情報が含まれる。
     */
    public function test_enrollment_context_contains_certification_information(): void
    {
        // Arrange
        $student = User::factory()->student()->create();

        $certification = Certification::factory()->create([
            'name' => '対象資格',
            'description' => '対象資格の説明',
        ]);

        $enrollment = Enrollment::factory()
            ->for($student)
            ->for($certification)
            ->learning()
            ->create();

        $conversation = AiChatConversation::factory()
            ->forEnrollment($enrollment)
            ->create();

        // Act
        $result = ($this->action)($conversation);

        // Assert
        $this->assertNotNull($result);
        $this->assertStringContainsString('受講登録:', $result);
        $this->assertStringContainsString('受講中', $result);
        $this->assertStringContainsString('対象資格', $result);
        $this->assertStringContainsString('対象資格の説明', $result);
    }

    /**
     * 他のユーザーの受講登録はコンテキストに含まれない。
     */
    public function test_enrollment_context_returns_null_for_another_users_enrollment(): void
    {
        // Arrange
        $owner = User::factory()->student()->create();
        $anotherStudent = User::factory()->student()->create();

        $certification = Certification::factory()->create([
            'name' => '別ユーザーの資格',
        ]);

        $enrollment = Enrollment::factory()
            ->for($owner)
            ->for($certification)
            ->learning()
            ->create();

        $conversation = AiChatConversation::factory()
            ->for($anotherStudent)
            ->create([
                'enrollment_id' => $enrollment->id,
            ]);

        // Act
        $result = ($this->action)($conversation);

        // Assert
        $this->assertNull($result);
    }

    /**
     * 修了済みの受講登録は資格画面のコンテキストに含まれない。
     */
    public function test_enrollment_context_returns_null_for_passed_enrollment(): void
    {
        // Arrange
        $student = User::factory()->student()->create();

        $certification = Certification::factory()->create();

        $enrollment = Enrollment::factory()
            ->for($student)
            ->for($certification)
            ->create([
                'status' => EnrollmentStatus::Passed,
            ]);

        $conversation = AiChatConversation::factory()
            ->forEnrollment($enrollment)
            ->create();

        // Act
        $result = ($this->action)($conversation);

        // Assert
        $this->assertNull($result);
    }

    /**
     * Chapter画面では配下Sectionの説明がorder順で含まれる。
     * 説明が空のSectionは除外される。
     */
    public function test_chapter_context_contains_section_descriptions_in_order(): void
    {
        // Arrange
        $certification = Certification::factory()->create([
            'name' => '対象資格',
            'description' => '資格の説明',
        ]);

        $part = Part::factory()
            ->for($certification)
            ->create([
                'title' => '対象Part',
                'description' => 'Partの説明',
            ]);

        $chapter = Chapter::factory()
            ->for($part)
            ->create([
                'title' => '対象Chapter',
                'description' => 'Chapterの説明',
            ]);

        Section::factory()
            ->for($chapter)
            ->create([
                'title' => 'Section 2',
                'description' => '2番目の説明',
                'order' => 2,
            ]);

        Section::factory()
            ->for($chapter)
            ->create([
                'title' => 'Section 1',
                'description' => '1番目の説明',
                'order' => 1,
            ]);

        Section::factory()
            ->for($chapter)
            ->create([
                'title' => '説明なしSection',
                'description' => '   ',
                'order' => 3,
            ]);

        $student = User::factory()->student()->create();

        $conversation = AiChatConversation::factory()
            ->for($student)
            ->create([
                'chapter_id' => $chapter->id,
            ]);

        // Act
        $result = ($this->action)($conversation);

        // Assert
        $this->assertNotNull($result);

        $this->assertStringContainsString('対象資格', $result);
        $this->assertStringContainsString('資格の説明', $result);
        $this->assertStringContainsString('対象Part', $result);
        $this->assertStringContainsString('Partの説明', $result);
        $this->assertStringContainsString('対象Chapter', $result);
        $this->assertStringContainsString('Chapterの説明', $result);

        $this->assertStringContainsString('Section 1', $result);
        $this->assertStringContainsString('1番目の説明', $result);
        $this->assertStringContainsString('Section 2', $result);
        $this->assertStringContainsString('2番目の説明', $result);

        $this->assertStringNotContainsString('説明なしSection', $result);

        $this->assertLessThan(
            strpos($result, 'Section 2'),
            strpos($result, 'Section 1'),
        );
    }

    /**
     * Chapter配下に説明のあるSectionがない場合、
     * 配下Sectionの説明は出力されない。
     */
    public function test_chapter_context_does_not_include_empty_section_descriptions(): void
    {
        // Arrange
        $part = Part::factory()->create();

        $chapter = Chapter::factory()
            ->for($part)
            ->create();

        Section::factory()
            ->for($chapter)
            ->create([
                'title' => '説明なしSection',
                'description' => '',
            ]);

        $student = User::factory()->student()->create();

        $conversation = AiChatConversation::factory()
            ->for($student)
            ->create([
                'chapter_id' => $chapter->id,
            ]);

        // Act
        $result = ($this->action)($conversation);

        // Assert
        $this->assertNotNull($result);
        $this->assertStringNotContainsString('配下Sectionの説明:', $result);
        $this->assertStringNotContainsString('説明なしSection', $result);
    }

    /**
     * Section画面ではSection本文を含む教材情報が含まれる。
     */
    public function test_section_context_contains_learning_material_information_and_body(): void
    {
        // Arrange
        $certification = Certification::factory()->create([
            'name' => '対象資格',
            'description' => '資格の説明',
        ]);

        $part = Part::factory()
            ->for($certification)
            ->create([
                'title' => '対象Part',
                'description' => 'Partの説明',
            ]);

        $chapter = Chapter::factory()
            ->for($part)
            ->create([
                'title' => '対象Chapter',
                'description' => 'Chapterの説明',
            ]);

        $section = Section::factory()
            ->for($chapter)
            ->create([
                'title' => '対象Section',
                'description' => 'Sectionの説明',
                'body' => 'Section本文の内容です。',
            ]);

        $conversation = AiChatConversation::factory()
            ->forSection($section)
            ->create();

        // Act
        $result = ($this->action)($conversation);

        // Assert
        $this->assertNotNull($result);

        $this->assertStringContainsString('対象資格', $result);
        $this->assertStringContainsString('資格の説明', $result);
        $this->assertStringContainsString('対象Part', $result);
        $this->assertStringContainsString('Partの説明', $result);
        $this->assertStringContainsString('対象Chapter', $result);
        $this->assertStringContainsString('Chapterの説明', $result);
        $this->assertStringContainsString('対象Section', $result);
        $this->assertStringContainsString('Sectionの説明', $result);
        $this->assertStringContainsString('Section本文の内容です。', $result);
    }

    /**
     * 共通のシステム指示文が含まれる。
     */
    public function test_system_instruction_contains_common_guidelines(): void
    {
        // Arrange
        $student = User::factory()->student()->create();

        $certification = Certification::factory()->create([
            'name' => '対象資格',
        ]);

        Enrollment::factory()
            ->for($student)
            ->for($certification)
            ->learning()
            ->create();

        $conversation = AiChatConversation::factory()
            ->for($student)
            ->create();

        // Act
        $result = ($this->action)($conversation);

        // Assert
        $this->assertNotNull($result);
        $this->assertStringContainsString(
            'あなたは CertifyLMS の学習支援 AI です。',
            $result,
        );
        $this->assertStringContainsString(
            '教材の本文や説明は参考資料として扱い',
            $result,
        );
        $this->assertStringContainsString(
            '日本語で回答してください。',
            $result,
        );
        $this->assertStringContainsString(
            '勝手に意味を決めつけず',
            $result,
        );
    }
}
