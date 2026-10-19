<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\EnrollmentNote;

use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;
use App\UseCases\EnrollmentNote\StoreAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * StoreAction の責務:
 *
 * - EnrollmentNote を INSERT
 * - 作成者に指定された User を設定
 * - 受講登録に対象の Enrollment を設定
 * - 指定された本文を登録
 */
class StoreActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_insert_note(): void
    {
        // Arrange
        $author = User::factory()
            ->coach()
            ->create();

        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $enrollment = Enrollment::factory()
            ->for($student)
            ->create();

        // Act
        $note = app(StoreAction::class)(
            $enrollment,
            $author,
            [
                'body' => '受講生の学習状況についてのメモです。',
            ],
        );

        // Assert
        $this->assertDatabaseHas('enrollment_notes', [
            'id' => $note->id,
            'enrollment_id' => $enrollment->id,
            'author_id' => $author->id,
            'body' => '受講生の学習状況についてのメモです。',
        ]);
    }

    public function test_returns_enrollment_note(): void
    {
        // Arrange
        $author = User::factory()
            ->coach()
            ->create();

        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $enrollment = Enrollment::factory()
            ->for($student)
            ->create();

        // Act
        $note = app(StoreAction::class)(
            $enrollment,
            $author,
            ['body' => 'テスト用のメモです。'],
        );

        // Assert
        $this->assertInstanceOf(EnrollmentNote::class, $note);
        $this->assertSame($enrollment->id, $note->enrollment_id);
        $this->assertSame($author->id, $note->author_id);
        $this->assertSame('テスト用のメモです。', $note->body);
    }

    public function test_signature_is_enrollment_user_array(): void
    {
        // Arrange
        $reflection = new \ReflectionMethod(
            StoreAction::class,
            '__invoke',
        );

        // Act
        $params = $reflection->getParameters();

        // Assert
        $this->assertCount(3, $params);

        $this->assertSame(
            Enrollment::class,
            $params[0]->getType()?->getName(),
        );

        $this->assertSame(
            User::class,
            $params[1]->getType()?->getName(),
        );

        $this->assertSame(
            'array',
            $params[2]->getType()?->getName(),
        );

        $this->assertSame(
            EnrollmentNote::class,
            $reflection->getReturnType()?->getName(),
        );
    }
}
