<?php

declare(strict_types=1);

namespace Tests\Feature\Http\EnrollmentGoal;

use Tests\TestCase;

class EnrollmentGoalControllerTest extends TestCase
{
    /**
     * A basic feature test example.
     */
    public function test_example(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
