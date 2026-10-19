<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\EnrollmentNote;

use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;
use App\UseCases\EnrollmentNote\UpdateAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * UpdateAction の責務:
 *
 * - EnrollmentNote の本文を UPDATE
 * - 作成者や受講登録との紐付けを維持
 * - 更新後の EnrollmentNote を返す
 */
class UpdateActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_note_body(): void
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

        $note = EnrollmentNote::factory()
            ->for($enrollment, 'enrollment')
            ->for($author, 'author')
            ->create([
                'body' => '更新前のメモです。',
            ]);

        // Act
        $updatedNote = app(UpdateAction::class)(
            $note,
            ['body' => '更新後のメモです。'],
        );

        // Assert
        $this->assertDatabaseHas('enrollment_notes', [
            'id' => $note->id,
            'enrollment_id' => $enrollment->id,
            'author_id' => $author->id,
            'body' => '更新後のメモです。',
        ]);

        $this->assertSame('更新後のメモです。', $updatedNote->body);
    }

    public function test_update_does_not_change_author_or_enrollment(): void
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

        $note = EnrollmentNote::factory()
            ->for($enrollment, 'enrollment')
            ->for($author, 'author')
            ->create([
                'body' => '更新前のメモです。',
            ]);

        // Act
        app(UpdateAction::class)(
            $note,
            [
                'body' => '本文だけを更新します。',
                'author_id' => (string) Str::ulid(),
                'enrollment_id' => (string) Str::ulid(),
            ],
        );

        // Assert
        $this->assertDatabaseHas('enrollment_notes', [
            'id' => $note->id,
            'enrollment_id' => $enrollment->id,
            'author_id' => $author->id,
            'body' => '本文だけを更新します。',
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

        $note = EnrollmentNote::factory()
            ->for($enrollment, 'enrollment')
            ->for($author, 'author')
            ->create([
                'body' => '更新前のメモです。',
            ]);

        // Act
        $updatedNote = app(UpdateAction::class)(
            $note,
            ['body' => '更新後のメモです。'],
        );

        // Assert
        $this->assertInstanceOf(EnrollmentNote::class, $updatedNote);
        $this->assertSame($note->id, $updatedNote->id);
        $this->assertSame('更新後のメモです。', $updatedNote->body);
    }

    public function test_signature_is_enrollment_note_array(): void
    {
        // Arrange
        $reflection = new \ReflectionMethod(
            UpdateAction::class,
            '__invoke',
        );

        // Act
        $params = $reflection->getParameters();

        // Assert
        $this->assertCount(2, $params);

        $this->assertSame(
            EnrollmentNote::class,
            $params[0]->getType()?->getName(),
        );

        $this->assertSame(
            'array',
            $params[1]->getType()?->getName(),
        );

        $this->assertSame(
            EnrollmentNote::class,
            $reflection->getReturnType()?->getName(),
        );
    }
}
