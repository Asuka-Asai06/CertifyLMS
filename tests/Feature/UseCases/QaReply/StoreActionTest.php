<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\QaReply;

use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use App\UseCases\QaReply\StoreAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * StoreAction の責務:
 *
 * - QaReply を INSERT
 * - 返信者にログイン中の User を設定
 * - 返信先に対象の QaThread を設定
 * - 指定された本文を登録
 */
class StoreActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_insert_reply(): void
    {
        $user = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $thread = QaThread::factory()->create();

        $reply = app(StoreAction::class)(
            $user,
            $thread,
            [
                'body' => 'Policyについて理解できました。ありがとうございます。',
            ],
        );

        $this->assertDatabaseHas('qa_replies', [
            'id' => $reply->id,
            'qa_thread_id' => $thread->id,
            'user_id' => $user->id,
            'body' => 'Policyについて理解できました。ありがとうございます。',
        ]);
    }

    public function test_signature_is_user_thread_array(): void
    {
        $reflection = new \ReflectionMethod(
            StoreAction::class,
            '__invoke',
        );

        $params = $reflection->getParameters();

        $this->assertCount(3, $params);
        $this->assertSame(
            User::class,
            $params[0]->getType()?->getName(),
        );
        $this->assertSame(
            QaThread::class,
            $params[1]->getType()?->getName(),
        );
        $this->assertSame(
            'array',
            $params[2]->getType()?->getName(),
        );
    }
}
