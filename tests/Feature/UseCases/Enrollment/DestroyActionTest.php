<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Enrollment;

use App\Exceptions\Enrollment\EnrollmentInvalidTransitionException;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\EnrollmentStatsService;
use App\UseCases\Enrollment\DestroyAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 受講生による受講解除 Action のロジックを直接検証する。
 *
 * learning の受講登録は論理削除でき、管理者ダッシュボードの集計キャッシュが
 * 無効化されること、passed / failed は削除できないことを確認する。
 */
class DestroyActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    /**
     * learning の受講登録を論理削除し、集計キャッシュが無効化されること。
     */
    public function test_deletes_learning_enrollment_and_invalidates_admin_dashboard_cache(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()
            ->for($student)
            ->learning()
            ->create();

        // 管理者ダッシュボードの2種類のキャッシュを用意する。
        $stats = app(EnrollmentStatsService::class);
        $stats->adminKpi();
        $stats->completionRateByCertification();

        $this->assertTrue(Cache::has(config('dashboard.admin_kpi_cache_key')));
        $this->assertTrue(Cache::has(config('dashboard.admin_completion_rate_cache_key')));

        app(DestroyAction::class)($enrollment);

        // Enrollment は物理削除ではなく論理削除される。
        $this->assertSoftDeleted('enrollments', [
            'id' => $enrollment->id,
        ]);

        // 管理者ダッシュボードの集計キャッシュが削除される。
        $this->assertFalse(Cache::has(config('dashboard.admin_kpi_cache_key')));
        $this->assertFalse(Cache::has(config('dashboard.admin_completion_rate_cache_key')));
    }

    /**
     * passed の受講登録は削除できないこと。
     */
    public function test_throws_when_enrollment_is_passed(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()
            ->for($student)
            ->passed()
            ->create();

        $this->expectException(EnrollmentInvalidTransitionException::class);

        app(DestroyAction::class)($enrollment);
    }

    /**
     * failed の受講登録は削除できないこと。
     */
    public function test_throws_when_enrollment_is_failed(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()
            ->for($student)
            ->failed()
            ->create();

        $this->expectException(EnrollmentInvalidTransitionException::class);

        app(DestroyAction::class)($enrollment);
    }
}
