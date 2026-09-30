<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CertificationStatus;
use App\Enums\QaThreadStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * 開発用 質問掲示板スレッドシーダー。
 *
 * **設計思想（Seeder 業界標準: 状態分散 + 投稿者分散 + 日時分散）**:
 *
 * 1. **スレッド数の確保**: 公開済み資格 5 種それぞれに質問を 5 件ずつ、
 *    合計 25 件のスレッドを投入する。
 *    一覧のページネーション（20 件 / ページ）を実機確認するため。
 *
 * 2. **状態分散**: 各資格について未解決 3 件 + 解決済み 2 件を投入する。
 *
 * 3. **投稿者分散**: 固定 student 1 名を各資格の質問に含め、
 *    残りを受講中の受講生に分散する。
 *
 * 4. **日時分散**: 質問ごとに作成日時を 1 ～ 10 日前へ分散する。
 */
class QaThreadSeeder extends Seeder
{
    public function run(): void
    {
        $certifications = Certification::query()
            ->where(
                'status',
                CertificationStatus::Published->value,
            )
            ->orderBy('id')
            ->get();

        $students = User::query()
            ->where(
                'role',
                UserRole::Student->value,
            )
            ->where(
                'status',
                UserStatus::InProgress->value,
            )
            ->orderBy('id')
            ->get();

        if ($certifications->isEmpty()) {
            $this->command?->warn(
                '公開済みの資格が存在しないため、QaThreadSeederをスキップします。',
            );

            return;
        }

        if ($students->isEmpty()) {
            $this->command?->warn(
                '受講中の受講生が存在しないため、QaThreadSeederをスキップします。',
            );

            return;
        }

        $threadDefinitions = collect([
            [
                'status' => QaThreadStatus::Unresolved->value,
                'daysAgo' => 1,
            ],
            [
                'status' => QaThreadStatus::Resolved->value,
                'daysAgo' => 3,
            ],
            [
                'status' => QaThreadStatus::Unresolved->value,
                'daysAgo' => 5,
            ],
            [
                'status' => QaThreadStatus::Resolved->value,
                'daysAgo' => 7,
            ],
            [
                'status' => QaThreadStatus::Unresolved->value,
                'daysAgo' => 10,
            ],
        ]);

        $certifications->each(
            function (
                Certification $certification,
                int $certificationIndex,
            ) use (
                $threadDefinitions,
                $students,
            ): void {
                $threadDefinitions->each(
                    function (
                        array $definition,
                        int $threadIndex,
                    ) use (
                        $certification,
                        $certificationIndex,
                        $students,
                    ): void {
                        $user = $this->selectStudent(
                            $students,
                            $certificationIndex,
                            $threadIndex,
                        );

                        $createdAt = $this->createPostedAt(
                            $definition['daysAgo'],
                            $certificationIndex,
                            $threadIndex,
                        );

                        $resolvedAt = $definition['status']
                            === QaThreadStatus::Resolved->value
                            ? $createdAt->copy()->addHours(2)
                            : null;

                        QaThread::factory()->create([
                            'user_id' => $user->id,
                            'certification_id' => $certification->id,
                            'title' => sprintf(
                                '%sについて質問%d',
                                $certification->name,
                                $threadIndex + 1,
                            ),
                            'body' => sprintf(
                                '%sについて教えてください。',
                                $certification->name,
                            ),
                            'status' => $definition['status'],
                            'resolved_at' => $resolvedAt,
                            'created_at' => $createdAt,
                            'updated_at' => $createdAt,
                        ]);
                    },
                );
            },
        );
    }

    /**
     * 質問の投稿者を分散させる。
     *
     * 資格ごとに開始位置をずらしながら、
     * 受講中の受講生を順番に割り当てる。
     *
     * 25件の質問に対して10名の受講生を使用するため、
     * 各受講生の投稿数は2〜3件になる。
     *
     * @param Collection<int, User> $students
     */
    private function selectStudent(
        Collection $students,
        int $certificationIndex,
        int $threadIndex,
    ): User {
        $studentIndex = (
            ($certificationIndex * 2)
            + $threadIndex
        ) % $students->count();

        return $students->get($studentIndex);
    }

    /**
     * 質問の投稿日時を分散させる。
     */
    private function createPostedAt(
        int $daysAgo,
        int $certificationIndex,
        int $threadIndex,
    ): Carbon {
        $hour = 9 + (
            ($certificationIndex * 3 + $threadIndex * 2) % 11
        );

        $minute = (
            ($certificationIndex * 17 + $threadIndex * 13) % 4
        ) * 15;

        return now()
            ->subDays($daysAgo)
            ->setTime($hour, $minute);
    }
}
