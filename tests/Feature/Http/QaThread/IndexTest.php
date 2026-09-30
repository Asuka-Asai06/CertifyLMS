<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaThread;

use App\Models\Certification;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /qa-board` の挙動を検証する。
 *
 * - 受講生は公開中資格の質問のみ一覧表示できる
 * - コーチは担当中かつ公開中資格の質問のみ一覧表示できる
 * - admin は公開状態に関係なくすべての質問を一覧表示できる
 * - 解決状態・資格・キーワードによる絞り込み / 検索ができる
 * - 質問は新しいものから順に表示され、20 件ごとにページネーションされる
 */
class IndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_view_threads_of_published_certifications(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $publishedCertification = Certification::factory()->published()->create();
        $draftCertification = Certification::factory()->draft()->create();

        $publishedThread = QaThread::factory()->create([
            'certification_id' => $publishedCertification->id,
            'title' => '公開中資格の質問',
        ]);

        $draftThread = QaThread::factory()->create([
            'certification_id' => $draftCertification->id,
            'title' => '下書き資格の質問',
        ]);

        $this->actingAs($student)
            ->get(route('qa-board.index'))
            ->assertOk()
            ->assertViewIs('qa-thread.index')
            ->assertSee('公開中資格の質問')
            ->assertDontSee('下書き資格の質問')
            ->assertViewHas('threads', function ($threads) use ($publishedThread, $draftThread): bool {
                return $threads->contains($publishedThread)
                    && ! $threads->contains($draftThread);
            });
    }

    public function test_coach_can_view_threads_of_assigned_published_certifications(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $admin = User::factory()->admin()->inProgress()->create();

        $assignedCertification = Certification::factory()->published()->create();
        $unassignedCertification = Certification::factory()->published()->create();

        $assignedCertification->coaches()->attach($coach->id, [
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);

        $assignedThread = QaThread::factory()->create([
            'certification_id' => $assignedCertification->id,
            'title' => '担当資格の質問',
        ]);

        $unassignedThread = QaThread::factory()->create([
            'certification_id' => $unassignedCertification->id,
            'title' => '未担当資格の質問',
        ]);

        $this->actingAs($coach)
            ->get(route('qa-board.index'))
            ->assertOk()
            ->assertSee('担当資格の質問')
            ->assertDontSee('未担当資格の質問')
            ->assertViewHas('threads', function ($threads) use (
                $assignedThread,
                $unassignedThread,
            ): bool {
                return $threads->contains($assignedThread)
                    && ! $threads->contains($unassignedThread);
            });
    }

    public function test_coach_cannot_view_threads_of_unpublished_certification(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $admin = User::factory()->admin()->inProgress()->create();

        $draftCertification = Certification::factory()->draft()->create();

        $draftCertification->coaches()->attach($coach->id, [
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);

        $thread = QaThread::factory()->create([
            'certification_id' => $draftCertification->id,
            'title' => '非公開資格の質問',
        ]);

        $this->actingAs($coach)
            ->get(route('qa-board.index'))
            ->assertOk()
            ->assertDontSee('非公開資格の質問')
            ->assertViewHas('threads', function ($threads) use ($thread): bool {
                return ! $threads->contains($thread);
            });
    }

    public function test_admin_can_view_threads_of_all_certifications(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();

        $publishedCertification = Certification::factory()->published()->create();
        $draftCertification = Certification::factory()->draft()->create();

        $publishedThread = QaThread::factory()->create([
            'certification_id' => $publishedCertification->id,
            'title' => '公開中資格の質問',
        ]);

        $draftThread = QaThread::factory()->create([
            'certification_id' => $draftCertification->id,
            'title' => '下書き資格の質問',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.qa-board.index'))
            ->assertOk()
            ->assertSee('公開中資格の質問')
            ->assertSee('下書き資格の質問')
            ->assertViewHas('threads', function ($threads) use (
                $publishedThread,
                $draftThread,
            ): bool {
                return $threads->contains($publishedThread)
                    && $threads->contains($draftThread);
            });
    }

    public function test_can_filter_threads_by_unresolved_status(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $unresolvedThread = QaThread::factory()->unresolved()->create([
            'certification_id' => $certification->id,
            'title' => '未解決の質問',
        ]);

        $resolvedThread = QaThread::factory()->resolved()->create([
            'certification_id' => $certification->id,
            'title' => '解決済みの質問',
        ]);

        $this->actingAs($student)
            ->get(route('qa-board.index', [
                'status' => 'unresolved',
            ]))
            ->assertOk()
            ->assertSee('未解決の質問')
            ->assertDontSee('解決済みの質問')
            ->assertViewHas('threads', function ($threads) use (
                $unresolvedThread,
                $resolvedThread,
            ): bool {
                return $threads->contains($unresolvedThread)
                    && ! $threads->contains($resolvedThread);
            });
    }

    public function test_can_filter_threads_by_resolved_status(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $unresolvedThread = QaThread::factory()->unresolved()->create([
            'certification_id' => $certification->id,
            'title' => '未解決の質問',
        ]);

        $resolvedThread = QaThread::factory()->resolved()->create([
            'certification_id' => $certification->id,
            'title' => '解決済みの質問',
        ]);

        $this->actingAs($student)
            ->get(route('qa-board.index', [
                'status' => 'resolved',
            ]))
            ->assertOk()
            ->assertSee('解決済みの質問')
            ->assertDontSee('未解決の質問')
            ->assertViewHas('threads', function ($threads) use (
                $unresolvedThread,
                $resolvedThread,
            ): bool {
                return $threads->contains($resolvedThread)
                    && ! $threads->contains($unresolvedThread);
            });
    }

    public function test_can_filter_threads_by_certification(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $targetCertification = Certification::factory()->published()->create();
        $otherCertification = Certification::factory()->published()->create();

        $targetThread = QaThread::factory()->create([
            'certification_id' => $targetCertification->id,
            'title' => '対象資格の質問',
        ]);

        $otherThread = QaThread::factory()->create([
            'certification_id' => $otherCertification->id,
            'title' => '別資格の質問',
        ]);

        $this->actingAs($student)
            ->get(route('qa-board.index', [
                'certification_id' => $targetCertification->id,
            ]))
            ->assertOk()
            ->assertSee('対象資格の質問')
            ->assertDontSee('別資格の質問')
            ->assertViewHas('threads', function ($threads) use (
                $targetThread,
                $otherThread,
            ): bool {
                return $threads->contains($targetThread)
                    && ! $threads->contains($otherThread);
            });
    }

    public function test_can_search_threads_by_keyword_in_title(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $matchedThread = QaThread::factory()->create([
            'certification_id' => $certification->id,
            'title' => 'Laravelの認証について',
            'body' => '質問本文です。',
        ]);

        $unmatchedThread = QaThread::factory()->create([
            'certification_id' => $certification->id,
            'title' => 'PHPについて',
            'body' => '別の質問です。',
        ]);

        $this->actingAs($student)
            ->get(route('qa-board.index', [
                'keyword' => 'Laravel',
            ]))
            ->assertOk()
            ->assertSee('Laravelの認証について')
            ->assertDontSee('PHPについて')
            ->assertViewHas('threads', function ($threads) use (
                $matchedThread,
                $unmatchedThread,
            ): bool {
                return $threads->contains($matchedThread)
                    && ! $threads->contains($unmatchedThread);
            });
    }

    public function test_can_search_threads_by_keyword_in_body(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $matchedThread = QaThread::factory()->create([
            'certification_id' => $certification->id,
            'title' => '質問タイトル',
            'body' => 'Laravelの認証について質問します。',
        ]);

        $unmatchedThread = QaThread::factory()->create([
            'certification_id' => $certification->id,
            'title' => '別の質問',
            'body' => 'PHPについて質問します。',
        ]);

        $this->actingAs($student)
            ->get(route('qa-board.index', [
                'keyword' => 'Laravel',
            ]))
            ->assertOk()
            ->assertSee('質問タイトル')
            ->assertDontSee('別の質問')
            ->assertViewHas('threads', function ($threads) use (
                $matchedThread,
                $unmatchedThread,
            ): bool {
                return $threads->contains($matchedThread)
                    && ! $threads->contains($unmatchedThread);
            });
    }

    public function test_can_search_threads_by_keyword_in_reply(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $matchedThread = QaThread::factory()->create([
            'certification_id' => $certification->id,
            'title' => '質問タイトル',
            'body' => '質問本文です。',
        ]);

        $unmatchedThread = QaThread::factory()->create([
            'certification_id' => $certification->id,
            'title' => '別の質問',
            'body' => '別の質問本文です。',
        ]);

        QaReply::factory()->create([
            'qa_thread_id' => $matchedThread->id,
            'body' => 'Laravelについての回答です。',
        ]);

        $this->actingAs($student)
            ->get(route('qa-board.index', [
                'keyword' => 'Laravel',
            ]))
            ->assertOk()
            ->assertSee('質問タイトル')
            ->assertDontSee('別の質問')
            ->assertViewHas('threads', function ($threads) use (
                $matchedThread,
                $unmatchedThread,
            ): bool {
                return $threads->contains($matchedThread)
                    && ! $threads->contains($unmatchedThread);
            });
    }

    public function test_threads_are_displayed_in_latest_first_order(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $oldThread = QaThread::factory()->create([
            'certification_id' => $certification->id,
            'title' => '古い質問',
            'created_at' => now()->subDays(2),
        ]);

        $newThread = QaThread::factory()->create([
            'certification_id' => $certification->id,
            'title' => '新しい質問',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($student)
            ->get(route('qa-board.index'))
            ->assertOk();

        $threads = $response->viewData('threads');

        $this->assertTrue($threads->first()->is($newThread));
        $this->assertTrue($threads->last()->is($oldThread));
    }

    public function test_threads_are_paginated(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        QaThread::factory()
            ->count(21)
            ->create([
                'certification_id' => $certification->id,
            ]);

        $response = $this->actingAs($student)
            ->get(route('qa-board.index'))
            ->assertOk();

        $threads = $response->viewData('threads');

        $this->assertSame(20, $threads->perPage());
        $this->assertSame(21, $threads->total());
        $this->assertSame(1, $threads->currentPage());
        $this->assertTrue($threads->hasMorePages());
    }

    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        $this->get(route('qa-board.index'))
            ->assertRedirect(route('login'));
    }
}
