<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\QaThread;

use App\Enums\QaThreadStatus;
use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use App\UseCases\QaThread\StoreAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * StoreAction の責務:
 *
 * - QaThread を INSERT
 * - 投稿者にログイン中の User を設定
 * - 指定された Certification に紐付ける
 * - 新規質問を未解決状態で登録
 * - resolved_at を null で登録
 */
class StoreActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_insert_thread_with_unresolved_status(): void
    {
        $user = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $certification = Certification::factory()->create();

        $thread = app(StoreAction::class)(
            $user,
            [
                'certification_id' => $certification->id,
                'title' => 'Laravelについて質問があります',
                'body' => 'LaravelのPolicyについて教えてください。',
            ],
        );

        $this->assertDatabaseHas('qa_threads', [
            'id' => $thread->id,
            'user_id' => $user->id,
            'certification_id' => $certification->id,
            'title' => 'Laravelについて質問があります',
            'body' => 'LaravelのPolicyについて教えてください。',
            'status' => QaThreadStatus::Unresolved->value,
            'resolved_at' => null,
        ]);
    }

    public function test_signature_is_user_array(): void
    {
        $reflection = new \ReflectionMethod(
            StoreAction::class,
            '__invoke',
        );

        $params = $reflection->getParameters();

        $this->assertCount(2, $params);
        $this->assertSame(
            User::class,
            $params[0]->getType()?->getName(),
        );
        $this->assertSame(
            'array',
            $params[1]->getType()?->getName(),
        );
    }
}
