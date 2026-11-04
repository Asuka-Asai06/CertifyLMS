<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\EnrollmentStatus;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * AIチャットの開発用シードデータ。
 *
 * 固定の受講生に対して、教材に紐づく会話、一般的な相談、
 * AIの応答が失敗した会話を作成する。
 */
class AiChatSeeder extends Seeder
{
    private const STUDENT_EMAIL = 'student@certify-lms.test';

    public function run(): void
    {
        $student = User::query()
            ->where('email', self::STUDENT_EMAIL)
            ->first();

        if ($student === null) {
            throw new RuntimeException(
                'AIチャットのシード対象となる受講生が見つかりません。UserSeederを先に実行してください。'
            );
        }

        $section = Section::query()
            ->with('chapter.part')
            ->where('title', '1.1 2 進数の表現')
            ->first();

        if ($section === null) {
            throw new RuntimeException(
                'AIチャットのシードに必要なSectionが見つかりません。ContentSeederを先に実行してください。'
            );
        }

        $certificationId = $section->chapter->part->certification_id;

        $enrollment = Enrollment::query()
            ->where('user_id', $student->id)
            ->where('certification_id', $certificationId)
            ->where('status', EnrollmentStatus::Learning->value)
            ->first();

        if ($enrollment === null) {
            throw new RuntimeException(
                '教材に対応する受講登録が見つかりません。EnrollmentSeederを先に実行してください。'
            );
        }

        $this->seedSectionConversation($student, $enrollment, $section);
        $this->seedGeneralConversation($student, $enrollment);
        $this->seedErrorConversation($student, $enrollment);
    }

    /**
     * 教材に紐づく会話。
     */
    private function seedSectionConversation(
        User $student,
        Enrollment $enrollment,
        Section $section,
    ): void {
        $conversation = $this->resetConversation(
            $student,
            '2進数の表現について',
            $enrollment,
            $section,
            now()->subDays(7),
        );

        $this->createUserMessage(
            $conversation,
            '2進数から10進数に変換する方法を教えてください。',
            now()->subDays(7)->setTime(10, 0),
        );

        $this->createAssistantMessage(
            $conversation,
            '2進数は、右端の桁から順番に2の0乗、2の1乗、2の2乗……を掛けて合計すると10進数に変換できます。例えば、1011₂ は 1×8 + 0×4 + 1×2 + 1×1 = 11 です。',
            now()->subDays(7)->setTime(10, 0, 5),
        );

        $this->createUserMessage(
            $conversation,
            'では、1101は10進数でいくつになりますか？',
            now()->subDays(6)->setTime(14, 0),
        );

        $this->createAssistantMessage(
            $conversation,
            '1101₂ は、1×8 + 1×4 + 0×2 + 1×1 = 13 なので、10進数では13です。',
            now()->subDays(6)->setTime(14, 0, 4),
        );

        $conversation->update([
            'last_message_at' => now()->subDays(6)->setTime(14, 0, 4),
        ]);
    }

    /**
     * Sectionに紐づかない一般的な学習相談。
     */
    private function seedGeneralConversation(User $student, Enrollment $enrollment): void
    {
        $conversation = $this->resetConversation(
            $student,
            '基本情報の学習計画',
            $enrollment,
            null,
            now()->subDays(3),
        );

        $this->createUserMessage(
            $conversation,
            '基本情報技術者試験の勉強を効率よく進めるには、どうすればよいですか？',
            now()->subDays(3)->setTime(19, 0),
        );

        $this->createAssistantMessage(
            $conversation,
            'まずは試験範囲を確認し、苦手分野を把握しましょう。教材で基礎を学び、問題演習で理解度を確認するサイクルがおすすめです。',
            now()->subDays(3)->setTime(19, 0, 5),
        );

        $this->createUserMessage(
            $conversation,
            '仕事がある日は、どのくらい勉強すればよいでしょうか？',
            now()->subDays(2)->setTime(20, 0),
        );

        $this->createAssistantMessage(
            $conversation,
            '仕事がある日は、まず30分程度から始め、無理なく継続できる時間を見つけるとよいでしょう。週末に一週間の復習をする方法もあります。',
            now()->subDays(2)->setTime(20, 0, 5),
        );

        $conversation->update([
            'last_message_at' => now()->subDays(2)->setTime(20, 0, 5),
        ]);
    }

    /**
     * AIの応答に失敗した会話。
     * ユーザーの質問を残し、AIのメッセージをエラー状態にする。
     */
    private function seedErrorConversation(User $student, Enrollment $enrollment): void
    {
        $conversation = $this->resetConversation(
            $student,
            'アルゴリズムの問題について',
            $enrollment,
            null,
            now()->subDay(),
        );

        $this->createUserMessage(
            $conversation,
            '再帰処理と繰り返し処理の違いを、具体例を使って説明してください。',
            now()->subDay()->setTime(16, 0),
        );

        AiChatMessage::factory()
            ->for($conversation, 'conversation')
            ->error()
            ->create([
                'content' => '',
                'error_detail' => 'AIの応答生成に失敗しました。',
                'created_at' => now()->subDay()->setTime(16, 0, 30),
                'updated_at' => now()->subDay()->setTime(16, 0, 30),
            ]);

        $conversation->update([
            'last_message_at' => now()->subDay()->setTime(16, 0, 30),
        ]);
    }

    /**
     * 会話を作成または再利用し、既存メッセージを初期化する。
     */
    private function resetConversation(
        User $student,
        string $title,
        Enrollment $enrollment,
        ?Section $section,
        mixed $lastMessageAt,
    ): AiChatConversation {
        $conversation = $student->aiChatConversations()->updateOrCreate(
            ['title' => $title],
            [
                'enrollment_id' => $enrollment->id,
                'section_id' => $section?->id,
                'last_message_at' => $lastMessageAt,
            ],
        );

        $conversation->messages()->delete();

        return $conversation;
    }

    /**
     * ユーザーの質問を作成する。
     */
    private function createUserMessage(
        AiChatConversation $conversation,
        string $content,
        mixed $createdAt,
    ): void {
        AiChatMessage::factory()
            ->for($conversation, 'conversation')
            ->userMessage()
            ->create([
                'content' => $content,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
    }

    /**
     * AIの回答を作成する。
     */
    private function createAssistantMessage(
        AiChatConversation $conversation,
        string $content,
        mixed $createdAt,
    ): void {
        AiChatMessage::factory()
            ->for($conversation, 'conversation')
            ->assistantMessage()
            ->create([
                'content' => $content,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
    }
}
