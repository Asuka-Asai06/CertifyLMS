<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GoogleCalendarCredential;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoogleCalendarCredential>
 */
class GoogleCalendarCredentialFactory extends Factory
{
    protected $model = GoogleCalendarCredential::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->coach(),
            'access_token' => fake()->sha256(),
            'refresh_token' => fake()->sha256(),
            'expires_at' => now()->addHour(),
            'calendar_id' => 'primary',
            'connected_at' => now(),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'expires_at' => now()->subHour(),
        ]);
    }

    public function withoutRefreshToken(): static
    {
        return $this->state(fn () => [
            'refresh_token' => null,
        ]);
    }
}
