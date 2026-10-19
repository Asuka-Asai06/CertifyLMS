<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AnnouncementTargetType;
use App\Models\Announcement;
use App\Models\Certification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Announcement>
 */
class AnnouncementFactory extends Factory
{
    protected $model = Announcement::class;

    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'body' => fake()->paragraphs(3, true),
            'target_type' => AnnouncementTargetType::AllStudents,
            'target_certification_id' => null,
            'target_user_id' => null,
            'dispatched_count' => fake()->numberBetween(0, 100),
            'dispatched_at' => now(),
            'created_by' => User::factory()->admin(),
        ];
    }

    /**
     * 資格を指定したお知らせを作成する。
     */
    public function forCertification(Certification $certification): static
    {
        return $this->state(fn () => [
            'target_type' => AnnouncementTargetType::Certification,
            'target_certification_id' => $certification->id,
            'target_user_id' => null,
        ]);
    }

    /**
     * 受講生を指定したお知らせを作成する。
     */
    public function forUser(User $user): static
    {
        return $this->state(fn () => [
            'target_type' => AnnouncementTargetType::User,
            'target_certification_id' => null,
            'target_user_id' => $user->id,
        ]);
    }

    /**
     * 全受講生を対象としたお知らせを作成する。
     */
    public function forAllStudents(): static
    {
        return $this->state(fn () => [
            'target_type' => AnnouncementTargetType::AllStudents,
            'target_certification_id' => null,
            'target_user_id' => null,
        ]);
    }
}
