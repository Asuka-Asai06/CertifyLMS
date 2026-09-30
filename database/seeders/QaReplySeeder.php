<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * 開発用 質問掲示板 返信シーダー。
 *
 * **設計思想（Seeder 業界標準: 回答数分散 + 回答者分散 + 時系列整合）**:
 *
 * 1. **回答数分散**: スレッドごとに回答数を 0 / 1 / 2 / 3 / 5 件へ分散し、
 *    回答なし・複数回答の質問が混在する状態を作る。
 *    スレッド一覧の回答数表示や質問詳細の回答一覧を実機確認するため。
 *
 * 2. **回答者分散**: 受講中の受講生・コーチを回答者として使用し、
 *    学生同士の回答とコーチからの回答を混在させる。
 *    回答者のロール表示や回答権限を実機確認するため。
 *
 * 3. **資格との整合性**: コーチは担当している資格の質問を優先して回答者にする。
 *    コーチが担当外の資格へ回答できないという Policy の条件と整合した
 *    開発用データを作成するため。
 *
 * 4. **時系列整合**: 回答日時を質問の作成日時より後に設定し、
 *    実際の質問・回答の流れに近いデータを作成する。
 */
class QaReplySeeder extends Seeder
{
    private const REPLY_COUNTS = [
        0,
        1,
        2,
        3,
        5,
    ];

    public function run(): void
    {
        $threads = QaThread::query()
            ->with('certification.coaches')
            ->orderBy('created_at')
            ->get();

        $users = User::query()
            ->whereIn('role', [
                UserRole::Student->value,
                UserRole::Coach->value,
            ])
            ->where(
                'status',
                UserStatus::InProgress->value,
            )
            ->orderBy('id')
            ->get();

        if ($threads->isEmpty()) {
            $this->command?->warn(
                '質問が存在しないため、QaReplySeederをスキップします。',
            );

            return;
        }

        if ($users->isEmpty()) {
            $this->command?->warn(
                '受講中の受講生・コーチが存在しないため、QaReplySeederをスキップします。',
            );

            return;
        }

        $threads->values()->each(
            function (QaThread $thread, int $index) use ($users): void {
                $replyCount = self::REPLY_COUNTS[
                    $index % count(self::REPLY_COUNTS)
                ];

                if ($replyCount === 0) {
                    return;
                }

                $replyUsers = $this->selectReplyUsers(
                    $users,
                    $thread,
                    $replyCount,
                );

                $replyUsers->each(
                    function (User $user, int $replyIndex) use (
                        $thread,
                    ): void {
                        $createdAt = $thread->created_at
                            ->copy()
                            ->addHours($replyIndex + 1);

                        QaReply::factory()->create([
                            'qa_thread_id' => $thread->id,
                            'user_id' => $user->id,
                            'body' => sprintf(
                                '%sについて回答します。',
                                $thread->certification->name,
                            ),
                            'created_at' => $createdAt,
                            'updated_at' => $createdAt,
                        ]);
                    },
                );
            },
        );
    }

    /**
     * 質問に対する回答者を選択する。
     *
     * 担当コーチが存在する資格ではコーチを優先し、
     * 不足する場合は受講中の受講生を回答者として補う。
     *
     * @param Collection<int, User> $users
     *
     * @return Collection<int, User>
     */
    private function selectReplyUsers(
        Collection $users,
        QaThread $thread,
        int $replyCount,
    ): Collection {
        $coaches = $thread->certification->coaches
            ->filter(
                fn (User $coach): bool => $coach->status
                    === UserStatus::InProgress,
            )
            ->values();

        $students = $users
            ->where('role', UserRole::Student->value)
            ->values();

        $replyUsers = $coaches->take($replyCount);

        if ($replyUsers->count() < $replyCount) {
            $replyUsers = $replyUsers->merge(
                $students->take(
                    $replyCount - $replyUsers->count(),
                ),
            );
        }

        return $replyUsers
            ->unique('id')
            ->take($replyCount)
            ->values();
    }
}
