<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\CertificationStatus;
use App\Enums\QaThreadStatus;
use App\Models\Certification;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * QaThread モデルのリレーション・Scope・Cast を検証する Unit テスト。
 *
 * 3 リレーション (user / certification / replies) +
 * 7 scope (visibleToStudent / visibleToCoach / visibleTo /
 * byStatus / byCertification / keyword / latestFirst) +
 * 2 cast (status / resolved_at) を網羅する。
 */
class QaThreadTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_relation_returns_thread_user(): void
    {
        // Arrange
        $user = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $thread = QaThread::factory()->create([
            'user_id' => $user->id,
        ]);

        // Act
        $related = $thread->user;

        // Assert
        $this->assertTrue($related->is($user));
    }

    public function test_user_relation_returns_soft_deleted_user(): void
    {
        // Arrange
        $user = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $thread = QaThread::factory()->create([
            'user_id' => $user->id,
        ]);

        $user->delete();

        // Act
        $related = $thread->user;

        // Assert
        $this->assertNotNull($related);
        $this->assertTrue($related->is($user));
        $this->assertTrue($related->trashed());
    }

    public function test_certification_relation_returns_thread_certification(): void
    {
        // Arrange
        $certification = Certification::factory()->create();

        $thread = QaThread::factory()->create([
            'certification_id' => $certification->id,
        ]);

        // Act
        $related = $thread->certification;

        // Assert
        $this->assertTrue($related->is($certification));
    }

    public function test_replies_relation_returns_thread_replies(): void
    {
        // Arrange
        $thread = QaThread::factory()->create();

        $reply = QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
        ]);

        // Act
        $replies = $thread->replies;

        // Assert
        $this->assertCount(1, $replies);
        $this->assertTrue($replies->first()->is($reply));
    }

    public function test_scope_visible_to_student_returns_published_certification_threads(): void
    {
        // Arrange
        $published = Certification::factory()->create([
            'status' => CertificationStatus::Published,
        ]);

        $draft = Certification::factory()->create([
            'status' => CertificationStatus::Draft,
        ]);

        $archived = Certification::factory()->create([
            'status' => CertificationStatus::Archived,
        ]);

        $visible = QaThread::factory()->create([
            'certification_id' => $published->id,
        ]);

        QaThread::factory()->create([
            'certification_id' => $draft->id,
        ]);

        QaThread::factory()->create([
            'certification_id' => $archived->id,
        ]);

        // Act
        $results = QaThread::visibleToStudent()->get();

        // Assert
        $this->assertCount(1, $results);
        $this->assertTrue($results->first()->is($visible));
    }

    public function test_scope_visible_to_coach_returns_assigned_published_threads(): void
    {
        // Arrange
        $coach = User::factory()
            ->coach()
            ->inProgress()
            ->create();

        $admin = User::factory()
            ->admin()
            ->inProgress()
            ->create();

        $assignedPublished = Certification::factory()->create([
            'status' => CertificationStatus::Published,
        ]);

        $assignedDraft = Certification::factory()->create([
            'status' => CertificationStatus::Draft,
        ]);

        $assignedArchived = Certification::factory()->create([
            'status' => CertificationStatus::Archived,
        ]);

        $unassignedPublished = Certification::factory()->create([
            'status' => CertificationStatus::Published,
        ]);

        $assignedPublished->coaches()->attach($coach->id, [
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);

        $assignedDraft->coaches()->attach($coach->id, [
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);

        $assignedArchived->coaches()->attach($coach->id, [
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);

        $visible = QaThread::factory()->create([
            'certification_id' => $assignedPublished->id,
        ]);

        QaThread::factory()->create([
            'certification_id' => $assignedDraft->id,
        ]);

        QaThread::factory()->create([
            'certification_id' => $assignedArchived->id,
        ]);

        QaThread::factory()->create([
            'certification_id' => $unassignedPublished->id,
        ]);

        // Act
        $results = QaThread::visibleToCoach($coach)->get();

        // Assert
        $this->assertCount(1, $results);
        $this->assertTrue($results->first()->is($visible));
    }

    public function test_scope_visible_to_returns_all_threads_for_admin(): void
    {
        // Arrange
        $admin = User::factory()
            ->admin()
            ->inProgress()
            ->create();

        QaThread::factory()->count(3)->create();

        // Act
        $results = QaThread::visibleTo($admin)->get();

        // Assert
        $this->assertCount(3, $results);
    }

    public function test_scope_visible_to_returns_published_threads_for_student(): void
    {
        // Arrange
        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $published = Certification::factory()->create([
            'status' => CertificationStatus::Published,
        ]);

        $draft = Certification::factory()->create([
            'status' => CertificationStatus::Draft,
        ]);

        $archived = Certification::factory()->create([
            'status' => CertificationStatus::Archived,
        ]);

        $visible = QaThread::factory()->create([
            'certification_id' => $published->id,
        ]);

        QaThread::factory()->create([
            'certification_id' => $draft->id,
        ]);

        QaThread::factory()->create([
            'certification_id' => $archived->id,
        ]);

        // Act
        $results = QaThread::visibleTo($student)->get();

        // Assert
        $this->assertCount(1, $results);
        $this->assertTrue($results->first()->is($visible));
    }

    public function test_scope_visible_to_returns_assigned_published_threads_for_coach(): void
    {
        // Arrange
        $coach = User::factory()
            ->coach()
            ->inProgress()
            ->create();

        $admin = User::factory()
            ->admin()
            ->inProgress()
            ->create();

        $assignedPublished = Certification::factory()->create([
            'status' => CertificationStatus::Published,
        ]);

        $assignedDraft = Certification::factory()->create([
            'status' => CertificationStatus::Draft,
        ]);

        $unassignedPublished = Certification::factory()->create([
            'status' => CertificationStatus::Published,
        ]);

        $assignedPublished->coaches()->attach($coach->id, [
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);

        $assignedDraft->coaches()->attach($coach->id, [
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);

        $visible = QaThread::factory()->create([
            'certification_id' => $assignedPublished->id,
        ]);

        QaThread::factory()->create([
            'certification_id' => $assignedDraft->id,
        ]);

        QaThread::factory()->create([
            'certification_id' => $unassignedPublished->id,
        ]);

        // Act
        $results = QaThread::visibleTo($coach)->get();

        // Assert
        $this->assertCount(1, $results);
        $this->assertTrue($results->first()->is($visible));
    }

    public function test_scope_by_status_filters_by_status(): void
    {
        // Arrange
        $resolved = QaThread::factory()
            ->resolved()
            ->create();

        QaThread::factory()
            ->unresolved()
            ->create();

        // Act
        $results = QaThread::byStatus(
            QaThreadStatus::Resolved,
        )->get();

        // Assert
        $this->assertCount(1, $results);
        $this->assertTrue($results->first()->is($resolved));
    }

    public function test_scope_by_status_returns_all_threads_when_status_is_null(): void
    {
        // Arrange
        QaThread::factory()->count(2)->create();

        // Act
        $results = QaThread::byStatus(null)->get();

        // Assert
        $this->assertCount(2, $results);
    }

    public function test_scope_by_certification_filters_by_certification(): void
    {
        // Arrange
        $certification = Certification::factory()->create();

        $visible = QaThread::factory()->create([
            'certification_id' => $certification->id,
        ]);

        QaThread::factory()->create();

        // Act
        $results = QaThread::byCertification(
            $certification->id,
        )->get();

        // Assert
        $this->assertCount(1, $results);
        $this->assertTrue($results->first()->is($visible));
    }

    public function test_scope_by_certification_returns_all_threads_when_id_is_null(): void
    {
        // Arrange
        QaThread::factory()->count(2)->create();

        // Act
        $results = QaThread::byCertification(null)->get();

        // Assert
        $this->assertCount(2, $results);
    }

    public function test_scope_keyword_filters_by_title(): void
    {
        // Arrange
        $matched = QaThread::factory()->create([
            'title' => 'LaravelのPolicyについて',
        ]);

        QaThread::factory()->create([
            'title' => 'PHPの質問です',
        ]);

        // Act
        $results = QaThread::keyword('Policy')->get();

        // Assert
        $this->assertCount(1, $results);
        $this->assertTrue($results->first()->is($matched));
    }

    public function test_scope_keyword_filters_by_body(): void
    {
        // Arrange
        $matched = QaThread::factory()->create([
            'body' => 'LaravelのPolicyについて教えてください。',
        ]);

        QaThread::factory()->create([
            'body' => 'PHPについて教えてください。',
        ]);

        // Act
        $results = QaThread::keyword('Policy')->get();

        // Assert
        $this->assertCount(1, $results);
        $this->assertTrue($results->first()->is($matched));
    }

    public function test_scope_keyword_filters_by_reply_body(): void
    {
        // Arrange
        $matched = QaThread::factory()->create();

        QaReply::factory()->create([
            'qa_thread_id' => $matched->id,
            'body' => 'Policyについて回答します。',
        ]);

        QaThread::factory()->create();

        // Act
        $results = QaThread::keyword('Policy')->get();

        // Assert
        $this->assertCount(1, $results);
        $this->assertTrue($results->first()->is($matched));
    }

    public function test_scope_keyword_returns_all_threads_when_keyword_is_null(): void
    {
        // Arrange
        QaThread::factory()->count(2)->create();

        // Act
        $results = QaThread::keyword(null)->get();

        // Assert
        $this->assertCount(2, $results);
    }

    public function test_scope_keyword_returns_all_threads_when_keyword_is_empty(): void
    {
        // Arrange
        QaThread::factory()->count(2)->create();

        // Act
        $results = QaThread::keyword('')->get();

        // Assert
        $this->assertCount(2, $results);
    }

    public function test_scope_latest_first_orders_by_created_at_descending(): void
    {
        // Arrange
        $old = QaThread::factory()->create([
            'created_at' => now()->subDays(2),
        ]);

        $new = QaThread::factory()->create([
            'created_at' => now()->subDay(),
        ]);

        // Act
        $results = QaThread::latestFirst()->get();

        // Assert
        $this->assertTrue($results->first()->is($new));
        $this->assertTrue($results->last()->is($old));
    }

    public function test_status_cast_returns_qa_thread_status_enum(): void
    {
        // Arrange
        $thread = QaThread::factory()->create([
            'status' => QaThreadStatus::Resolved,
        ]);

        // Act
        $fresh = $thread->fresh();

        // Assert
        $this->assertInstanceOf(
            QaThreadStatus::class,
            $fresh->status,
        );
    }

    public function test_resolved_at_cast_returns_carbon(): void
    {
        // Arrange
        $thread = QaThread::factory()
            ->resolved()
            ->create([
                'resolved_at' => '2026-05-20 10:00:00',
            ]);

        // Act
        $fresh = $thread->fresh();

        // Assert
        $this->assertInstanceOf(
            Carbon::class,
            $fresh->resolved_at,
        );
    }
}
