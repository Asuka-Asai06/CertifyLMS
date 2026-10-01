<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\MeetingPack;
use App\Models\User;
use App\Policies\MeetingPackPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * MeetingPackPolicy の ability × Role のマトリクス検証。
 * viewAny / view / 管理系 6 ability (create / update / delete / publish / archive / unarchive)
 * を 3 ブロックに分けて網羅する。
 *
 * 面談パック管理は admin 専用画面のため、
 * coach / student は一覧・詳細を含むすべての管理操作を許可しない。
 */
class MeetingPackPolicyTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('viewAnyMatrix')]
    public function test_view_any_returns_expected_for_role(string $actingRole, bool $expected): void
    {
        // Arrange
        $actor = User::factory()->{$actingRole}()->create();
        $policy = new MeetingPackPolicy;

        // Act
        $result = $policy->viewAny($actor);

        // Assert
        $this->assertSame(
            $expected,
            $result,
            "{$actingRole} の viewAny は "
            .($expected ? 'true' : 'false')
            .' を返すはず',
        );
    }

    #[DataProvider('viewMatrix')]
    public function test_view_returns_expected_for_role(string $actingRole, bool $expected): void
    {
        // Arrange
        $actor = User::factory()->{$actingRole}()->create();
        $plan = MeetingPack::factory()->published()->create();
        $policy = new MeetingPackPolicy;

        // Act
        $result = $policy->view($actor, $plan);

        // Assert
        $this->assertSame(
            $expected,
            $result,
            "{$actingRole} の view は "
            .($expected ? 'true' : 'false')
            .' を返すはず',
        );
    }

    #[DataProvider('adminOnlyAbilityMatrix')]
    public function test_admin_only_abilities_match_role_expectation(
        string $actingRole,
        string $policyMethod,
        bool $expected,
    ): void {
        // Arrange
        $actor = User::factory()->{$actingRole}()->create();
        $plan = MeetingPack::factory()->published()->create();
        $policy = new MeetingPackPolicy;

        // Act
        $result = $policyMethod === 'create'
            ? $policy->create($actor)
            : $policy->{$policyMethod}($actor, $plan);

        // Assert
        $this->assertSame(
            $expected,
            $result,
            "{$actingRole} が {$policyMethod} で "
            .($expected ? 'true' : 'false')
            .' を返すはず',
        );
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function viewAnyMatrix(): array
    {
        return [
            'admin は一覧画面に到達できる' => ['admin', true],
            'coach は一覧画面に到達できない' => ['coach', false],
            'student は一覧画面に到達できない' => ['student', false],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function viewMatrix(): array
    {
        return [
            'admin は面談パックを view 可' => ['admin', true],
            'coach は面談パックを view 不可' => ['coach', false],
            'student は面談パックを view 不可' => ['student', false],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function adminOnlyAbilityMatrix(): array
    {
        $abilities = [
            'create',
            'update',
            'delete',
            'publish',
            'archive',
            'unarchive',
        ];

        $roles = [
            'admin' => true,
            'coach' => false,
            'student' => false,
        ];

        $cases = [];

        foreach ($roles as $role => $expected) {
            foreach ($abilities as $ability) {
                $caseKey = $expected
                    ? "{$role} は {$ability} を実行できる"
                    : "{$role} は {$ability} を実行できない";

                $cases[$caseKey] = [$role, $ability, $expected];
            }
        }

        return $cases;
    }
}
