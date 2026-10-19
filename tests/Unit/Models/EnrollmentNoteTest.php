<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EnrollmentNote モデルのリレーションを検証する Unit テスト。
 * enrollment / author の 2 リレーションを網羅する。
 */
class EnrollmentNoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_enrollment_relation_returns_parent_enrollment(): void
    {
        // Arrange
        $enrollment = Enrollment::factory()->create();
        $author = User::factory()->coach()->create();

        $note = EnrollmentNote::factory()->create([
            'enrollment_id' => $enrollment->id,
            'author_id' => $author->id,
        ]);

        // Act
        $related = $note->enrollment;

        // Assert
        $this->assertNotNull($related);
        $this->assertTrue($related->is($enrollment));
    }

    public function test_author_relation_returns_note_author(): void
    {
        // Arrange
        $enrollment = Enrollment::factory()->create();
        $author = User::factory()->coach()->create();

        $note = EnrollmentNote::factory()->create([
            'enrollment_id' => $enrollment->id,
            'author_id' => $author->id,
        ]);

        // Act
        $related = $note->author;

        // Assert
        $this->assertNotNull($related);
        $this->assertTrue($related->is($author));
    }
}
