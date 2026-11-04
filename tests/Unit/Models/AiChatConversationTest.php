<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * AiChatConversation モデルのリレーション・Cast を検証する Unit テスト。
 *
 * 4 リレーション (user / enrollment / section / messages) と
 * 1 cast (last_message_at) を検証する。
 */
class AiChatConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_relation_returns_owner(): void
    {
        // Arrange
        $user = User::factory()->student()->create();
        $conversation = AiChatConversation::factory()
            ->for($user)
            ->create();

        // Act
        $owner = $conversation->user;

        // Assert
        $this->assertTrue($owner->is($user));
    }

    public function test_enrollment_relation_returns_related_enrollment(): void
    {
        // Arrange
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()
            ->for($student)
            ->learning()
            ->create();

        $conversation = AiChatConversation::factory()
            ->for($student)
            ->for($enrollment)
            ->create();

        // Act
        $relatedEnrollment = $conversation->enrollment;

        // Assert
        $this->assertTrue($relatedEnrollment->is($enrollment));
    }

    public function test_section_relation_returns_origin_section(): void
    {
        // Arrange
        $section = Section::factory()->create();
        $conversation = AiChatConversation::factory()
            ->forSection($section)
            ->create();

        // Act
        $relatedSection = $conversation->section;

        // Assert
        $this->assertTrue($relatedSection->is($section));
    }

    public function test_messages_relation_returns_only_messages_for_conversation(): void
    {
        // Arrange
        $conversation = AiChatConversation::factory()->create();
        $otherConversation = AiChatConversation::factory()->create();

        AiChatMessage::factory()
            ->count(2)
            ->for($conversation, 'conversation')
            ->create();

        AiChatMessage::factory()
            ->for($otherConversation, 'conversation')
            ->create();

        // Act
        $messages = $conversation->messages;

        // Assert
        $this->assertCount(
            2,
            $messages,
            '対象 conversation に紐づく message のみ取得されるはず',
        );

        foreach ($messages as $message) {
            $this->assertSame($conversation->id, $message->conversation_id);
        }
    }

    public function test_last_message_at_cast_returns_carbon(): void
    {
        // Arrange
        $lastMessageAt = Carbon::parse('2026-10-27 12:30:00');

        $conversation = AiChatConversation::factory()->create([
            'last_message_at' => $lastMessageAt,
        ]);

        // Act
        $fresh = $conversation->fresh();

        // Assert
        $this->assertInstanceOf(Carbon::class, $fresh->last_message_at);
        $this->assertTrue($fresh->last_message_at->equalTo($lastMessageAt));
    }

    public function test_last_message_at_can_be_null(): void
    {
        // Arrange
        $conversation = AiChatConversation::factory()->create([
            'last_message_at' => null,
        ]);

        // Act
        $fresh = $conversation->fresh();

        // Assert
        $this->assertNull($fresh->last_message_at);
    }
}
