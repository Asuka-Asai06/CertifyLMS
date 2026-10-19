<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CertificationStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\Certification;
use App\Models\User;
use App\Notifications\AnnouncementNotification;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * 開発用のお知らせシーダー。
 *
 * **設計思想（配信対象の状態網羅 + 既存データの利用）**:
 *
 * 1. **配信対象3種類のお知らせを作成**: 全受講生 / 資格指定 / ユーザー指定の
 *    配信履歴を作成し、管理者画面の一覧・詳細表示を確認できるようにする。
 *
 * 2. **配信対象に応じた通知を作成**: 既存の AnnouncementNotification を利用し、
 *    受講生側の通知一覧・詳細表示で通常配信と同じデータ形式を確認できるようにする。
 *
 * 3. **通知件数と配信履歴を一致させる**: 実際に通知データを作成する受講生を先に取得し、
 *    その人数を dispatched_count に保存する。
 *
 * 4. **開発用の既存データを利用**: 管理者・受講生・資格・受講登録は既存の
 *    Seeder で作成されたデータを利用し、不要なユーザーや受講登録の重複を避ける。
 *
 * 依存順序: `UserSeeder` → `CertificationSeeder` → `EnrollmentSeeder` → 本 Seeder。
 */
final class AnnouncementSeeder extends Seeder
{
    public function run(): void
    {
        // 配信者となる管理者を取得する。
        $admin = User::query()
            ->where('role', UserRole::Admin->value)
            ->first();

        if ($admin === null) {
            $this->command?->warn(
                'AnnouncementSeeder: 管理者が存在しません。先に UserSeeder を実行してください。',
            );

            return;
        }

        // 配信対象となる受講中の受講生を取得する。
        $students = User::query()
            ->inProgressStudents()
            ->orderBy('created_at')
            ->get();

        if ($students->isEmpty()) {
            $this->command?->warn(
                'AnnouncementSeeder: 受講中の受講生が存在しません。先に UserSeeder を実行してください。',
            );

            return;
        }

        // 配信対象3種類のお知らせを作成する。
        $this->seedAllStudentsAnnouncement($admin, $students);
        $this->seedCertificationAnnouncement($admin);
        $this->seedIndividualAnnouncement($admin);
    }

    /**
     * 全受講生向けのお知らせと通知を作成する。
     *
     * @param Collection<int, User> $students
     */
    private function seedAllStudentsAnnouncement(
        User $admin,
        Collection $students,
    ): void {
        $announcement = Announcement::factory()
            ->forAllStudents()
            ->create([
                'title' => 'システムメンテナンスのお知らせ',
                'body' => "システムメンテナンスを実施します。\n\n詳細は運営からの案内をご確認ください。",
                'dispatched_count' => $students->count(),
                'dispatched_at' => now(),
                'created_by' => $admin->id,
            ]);

        // 全受講中の受講生に通知を登録する。
        $this->createNotifications($students, $announcement);
    }

    /**
     * 基本情報技術者試験向けのお知らせと通知を作成する。
     *
     * 固定の資格名で対象資格を特定し、当該資格を受講中の受講生だけに通知する。
     * 管理画面の資格指定配信履歴と、受講生側の通知表示を安定して確認するための開発用データ。
     *
     * 依存: CertificationSeeder / EnrollmentSeeder。
     */
    private function seedCertificationAnnouncement(User $admin): void
    {
        // 固定の資格「基本情報技術者試験」を取得する。
        $certification = Certification::query()
            ->where('name', '基本情報技術者試験')
            ->where('status', CertificationStatus::Published->value)
            ->first();

        if ($certification === null) {
            $this->command?->warn(
                'AnnouncementSeeder: 基本情報技術者試験が存在しないか、公開状態ではありません。先に CertificationSeeder を実行してください。',
            );

            return;
        }

        // 基本情報技術者試験を受講中の受講生を取得する。
        $recipients = User::query()
            ->inProgressStudents()
            ->whereHas('enrollments', function ($query) use ($certification): void {
                $query
                    ->where('certification_id', $certification->id)
                    ->where('status', EnrollmentStatus::Learning->value);
            })
            ->get();

        if ($recipients->isEmpty()) {
            $this->command?->warn(
                'AnnouncementSeeder: 基本情報技術者試験を受講中の受講生が存在しません。先に EnrollmentSeeder を実行してください。',
            );

            return;
        }

        $announcement = Announcement::factory()
            ->forCertification($certification)
            ->create([
                'title' => '基本情報技術者試験の学習に関するお知らせ',
                'body' => "基本情報技術者試験を受講中の皆さんへ。\n\nアルゴリズムやネットワークなど、各分野の学習を進めていきましょう。\n学習状況を定期的に確認し、疑問点はコーチに相談してください。",
                'dispatched_count' => $recipients->count(),
                'dispatched_at' => now(),
                'created_by' => $admin->id,
            ]);

        // 対象資格を受講中の受講生にだけ通知を登録する。
        $this->createNotifications($recipients, $announcement);
    }

    /**
     * 固定アカウントの受講生花子向けのお知らせと通知を作成する。
     *
     * 開発用の固定メールアドレスで受講生を特定し、
     * 管理画面の配信履歴と受講生側の通知表示を安定して確認できるようにする。
     */
    private function seedIndividualAnnouncement(User $admin): void
    {
        // 固定アカウントの受講生花子を取得する。
        $student = User::query()
            ->inProgressStudents()
            ->where('email', 'student@certify-lms.test')
            ->first();

        if ($student === null) {
            $this->command?->warn(
                'AnnouncementSeeder: 固定アカウントの受講生花子が存在しません。先に UserSeeder を実行してください。',
            );

            return;
        }

        $announcement = Announcement::factory()
            ->forUser($student)
            ->create([
                'title' => '受講生花子さんへの個別のお知らせ',
                'body' => "受講に関する個別のお知らせです。\n\n内容をご確認ください。",
                'dispatched_count' => 1,
                'dispatched_at' => now(),
                'created_by' => $admin->id,
            ]);

        // 受講生花子だけに通知を登録する。
        $this->createNotifications(
            collect([$student]),
            $announcement,
        );
    }

    /**
     * 対象受講生にデータベース通知を登録する。
     *
     * シーダーではメール送信を行わず、通知画面の確認に必要な database チャネルのみを使用する。
     *
     * @param Collection<int, User> $recipients
     */
    private function createNotifications(
        Collection $recipients,
        Announcement $announcement,
    ): void {
        foreach ($recipients as $recipient) {
            // 通知インスタンスを受信者ごとに生成し、通知IDの重複を防ぐ。
            Notification::sendNow(
                [$recipient],
                new AnnouncementNotification($announcement),
                ['database'],
            );
        }
    }
}
