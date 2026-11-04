<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AiChatConversation;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiChatConversation>
 */
class AiChatConversationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->student()->inProgress(),
            'enrollment_id' => null,
            'section_id' => null,
            'title' => fake()->sentence(4),
            'last_message_at' => now(),
        ];
    }

    /**
     * 受講登録に紐づく会話。
     */
    public function forEnrollment(Enrollment $enrollment): static
    {
        return $this->state(fn () => [
            'user_id' => $enrollment->user_id,
            'enrollment_id' => $enrollment->id,
        ]);
    }

    /**
     * Sectionに紐づく会話。
     */
    public function forSection(Section $section): static
    {
        return $this->state(fn () => [
            'section_id' => $section->id,
        ]);
    }

    /**
     * 最終メッセージがない会話。
     */
    public function withoutMessages(): static
    {
        return $this->state(fn () => [
            'last_message_at' => null,
        ]);
    }
}
