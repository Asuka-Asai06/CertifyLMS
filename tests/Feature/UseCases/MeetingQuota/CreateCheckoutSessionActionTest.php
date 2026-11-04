<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\MeetingQuota;

use App\Enums\PaymentStatus;
use App\Models\MeetingPack;
use App\Models\User;
use App\UseCases\MeetingQuota\CreateCheckoutSessionAction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiErrorException;
use Stripe\HttpClient\ClientInterface;
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
     * テスト終了後に Stripe HTTP クライアントのモックを解除する。
     */
    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

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

    /**
     * Checkout Session を作成し、Payment に Session ID を保存する。
     *
     * @group external-api
     */
    public function test_creates_checkout_session_and_saves_session_id(): void
    {
        // Arrange
        config(['services.stripe.secret' => 'sk_test_dummy']);

        $admin = User::factory()->admin()->create();
        $user = User::factory()->student()->create();

        $pack = MeetingPack::factory()->published()->create([
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
            'name' => 'テスト面談パック',
            'meeting_count' => 5,
            'price' => 5000,
        ]);

        $this->mockStripeResponse([
            'id' => 'cs_test_123',
            'object' => 'checkout.session',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_123',
        ]);

        // Act
        $checkoutUrl = app(CreateCheckoutSessionAction::class)(
            $user,
            $pack->id,
        );

        // Assert
        $this->assertSame(
            'https://checkout.stripe.com/c/pay/cs_test_123',
            $checkoutUrl,
        );

        $this->assertDatabaseHas('payments', [
            'user_id' => $user->id,
            'meeting_pack_id' => $pack->id,
            'meeting_pack_name' => $pack->name,
            'quantity' => 5,
            'amount' => 5000,
            'status' => PaymentStatus::Pending->value,
            'stripe_checkout_session_id' => 'cs_test_123',
        ]);
    }

    /**
     * Stripe API エラー発生時に Payment を失敗状態にする。
     *
     * @group external-api
     */
    public function test_marks_payment_as_failed_when_stripe_api_returns_error(): void
    {
        // Arrange
        config(['services.stripe.secret' => 'sk_test_dummy']);

        $admin = User::factory()->admin()->create();
        $user = User::factory()->student()->create();

        $pack = MeetingPack::factory()->published()->create([
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]);

        $this->mockStripeResponse([
            'error' => [
                'type' => 'invalid_request_error',
                'message' => 'テスト用のStripe APIエラー',
            ],
        ], 400);

        // Act & Assert
        try {
            app(CreateCheckoutSessionAction::class)(
                $user,
                $pack->id,
            );

            $this->fail('ApiErrorException が発生することを期待しました。');
        } catch (ApiErrorException) {
            // Stripe API エラーが呼び出し元に再送出される。
        }

        $this->assertDatabaseHas('payments', [
            'user_id' => $user->id,
            'meeting_pack_id' => $pack->id,
            'status' => PaymentStatus::Failed->value,
        ]);
    }

    /**
     * Checkout URL が取得できない場合に Payment を失敗状態にする。
     *
     * @group external-api
     */
    public function test_marks_payment_as_failed_when_checkout_url_is_empty(): void
    {
        // Arrange
        config(['services.stripe.secret' => 'sk_test_dummy']);

        $admin = User::factory()->admin()->create();
        $user = User::factory()->student()->create();

        $pack = MeetingPack::factory()->published()->create([
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]);

        $this->mockStripeResponse([
            'id' => 'cs_test_123',
            'object' => 'checkout.session',
            'url' => null,
        ]);

        // Act & Assert
        try {
            app(CreateCheckoutSessionAction::class)(
                $user,
                $pack->id,
            );

            $this->fail('RuntimeException が発生することを期待しました。');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Stripe Checkout URL を取得できませんでした。',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseHas('payments', [
            'user_id' => $user->id,
            'meeting_pack_id' => $pack->id,
            'status' => PaymentStatus::Failed->value,
            'stripe_checkout_session_id' => null,
        ]);
    }

    /**
     * Stripe API のレスポンスをモックする。
     *
     * @param array<string, mixed> $response
     */
    private function mockStripeResponse(array $response, int $statusCode = 200): void
    {
        $client = Mockery::mock(ClientInterface::class);

        $client->shouldReceive('request')
            ->once()
            ->andReturn([
                json_encode($response, JSON_THROW_ON_ERROR),
                $statusCode,
                [],
            ]);

        ApiRequestor::setHttpClient($client);
    }
}
