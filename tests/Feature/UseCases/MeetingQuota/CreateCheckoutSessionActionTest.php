<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\MeetingQuota;

use App\Models\MeetingPack;
use App\Models\User;
use App\UseCases\MeetingQuota\CreateCheckoutSessionAction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * CreateCheckoutSessionAction のテスト。
 *
 * Stripe の Secret Key や購入対象の面談パックに関する
 * 事前条件を確認する。Stripe API への通信は行わない。
 */
class CreateCheckoutSessionActionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Secret Key が未設定の場合は例外を投げる。
     */
    public function test_throws_when_stripe_secret_key_is_not_configured(): void
    {
        // Arrange
        config(['services.stripe.secret' => '']);

        $user = User::factory()->student()->create();

        // Act & Assert
        try {
            app(CreateCheckoutSessionAction::class)(
                $user,
                '01J00000000000000000000000',
            );

            $this->fail('RuntimeException が発生することを期待しました。');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Stripe の Secret Key が設定されていません。',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount('payments', 0);
    }

    /**
     * 公開されていない面談パックは購入できない。
     */
    public function test_throws_when_meeting_pack_is_not_published(): void
    {
        // Arrange
        config(['services.stripe.secret' => 'sk_test_dummy']);

        $admin = User::factory()->admin()->create();
        $user = User::factory()->student()->create();

        $draftPack = MeetingPack::factory()->draft()->create([
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]);

        // Act & Assert
        try {
            app(CreateCheckoutSessionAction::class)(
                $user,
                $draftPack->id,
            );

            $this->fail('ModelNotFoundException が発生することを期待しました。');
        } catch (ModelNotFoundException) {
            // 公開済みではないため、対象のパックが見つからない。
        }

        $this->assertDatabaseCount('payments', 0);
    }

    /**
     * 存在しない面談パックを指定した場合は購入記録を作成しない。
     */
    public function test_throws_when_meeting_pack_does_not_exist(): void
    {
        // Arrange
        config(['services.stripe.secret' => 'sk_test_dummy']);

        $user = User::factory()->student()->create();

        // Act & Assert
        try {
            app(CreateCheckoutSessionAction::class)(
                $user,
                '01J00000000000000000000000',
            );

            $this->fail('ModelNotFoundException が発生することを期待しました。');
        } catch (ModelNotFoundException) {
            // 対象のパックが存在しないため、購入記録は作成されない。
        }

        $this->assertDatabaseCount('payments', 0);
    }
}
