<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\AiChat\GeminiApiException;
use App\Services\GeminiService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * GeminiService のテスト。
 *
 * Gemini API へのリクエスト、回答の取得、
 * API キー未設定時や回答取得失敗時の挙動を確認する。
 */
class GeminiServiceTest extends TestCase
{
    /**
     * Gemini API から回答とトークン使用量を取得できることを確認する。
     */
    public function test_generate_returns_answer_and_token_usage(): void
    {
        // Arrange
        config([
            'ai-chat.gemini.api_key' => 'test-api-key',
            'ai-chat.gemini.model' => 'gemini-3.8-flash',
            'ai-chat.gemini.timeout' => 30,
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => 'こんにちは。'],
                            ],
                        ],
                    ],
                ],
                'usageMetadata' => [
                    'promptTokenCount' => 12,
                    'candidatesTokenCount' => 8,
                ],
            ], 200),
        ]);

        $service = new GeminiService;

        $contents = [
            [
                'role' => 'user',
                'parts' => [
                    ['text' => 'こんにちは'],
                ],
            ],
        ];

        // Act
        $result = $service->generate($contents);

        // Assert
        $this->assertSame('こんにちは。', $result['text']);
        $this->assertSame('gemini-3.8-flash', $result['model']);
        $this->assertSame(12, $result['input_tokens']);
        $this->assertSame(8, $result['output_tokens']);

        Http::assertSent(
            fn (Request $request): bool => $request->url()
                    === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent'
                && $request->hasHeader(
                    'x-goog-api-key',
                    'test-api-key',
                )
                && $request['contents'] === $contents,
        );
    }

    /**
     * API キーが未設定の場合、例外が発生することを確認する。
     */
    public function test_generate_throws_exception_when_api_key_is_missing(): void
    {
        // Arrange
        config([
            'ai-chat.gemini.api_key' => '',
        ]);

        Http::fake();

        $service = new GeminiService;

        // Assert
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            '現在 AI チャットを利用できません。',
        );

        // Act
        $service->generate([
            [
                'role' => 'user',
                'parts' => [
                    ['text' => 'こんにちは'],
                ],
            ],
        ]);
    }

    /**
     * Gemini API から回答テキストが返らない場合、
     * 例外が発生することを確認する。
     */
    public function test_generate_throws_exception_when_answer_is_empty(): void
    {
        // Arrange
        config([
            'ai-chat.gemini.api_key' => 'test-api-key',
            'ai-chat.gemini.model' => 'gemini-3.8-flash',
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $service = new GeminiService;

        // Assert
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Gemini API から正常な応答を取得できませんでした。',
        );

        // Act
        $service->generate([
            [
                'role' => 'user',
                'parts' => [
                    ['text' => 'こんにちは'],
                ],
            ],
        ]);
    }

    public function test_generate_throws_api_exception_when_api_returns_http_error(): void
    {
        config([
            'ai-chat.gemini.api_key' => 'test-api-key',
            'ai-chat.gemini.model' => 'gemini-3.8-flash',
        ]);

        Http::fake([
            '*' => Http::response([
                'error' => [
                    'message' => 'Internal server error',
                ],
            ], 500),
        ]);

        try {
            app(GeminiService::class)->generate([
                [
                    'role' => 'user',
                    'parts' => [['text' => 'テスト質問']],
                ],
            ]);

            $this->fail('GeminiApiException が発生することを期待しました。');
        } catch (GeminiApiException $exception) {
            $this->assertSame(500, $exception->upstreamStatus);
        }
    }

    /**
     * 通信に失敗した場合、GeminiApiException に変換されることを確認する。
     */
    public function test_generate_throws_api_exception_when_connection_fails(): void
    {
        config([
            'ai-chat.gemini.api_key' => 'test-api-key',
            'ai-chat.gemini.model' => 'gemini-3.8-flash',
        ]);

        Http::fake(function (Request $request) {
            throw new ConnectionException('Connection timed out.');
        });

        try {
            app(GeminiService::class)->generate([
                [
                    'role' => 'user',
                    'parts' => [['text' => 'テスト質問']],
                ],
            ]);

            $this->fail('GeminiApiException が発生することを期待しました。');
        } catch (GeminiApiException $exception) {
            $this->assertNull($exception->upstreamStatus);
            $this->assertInstanceOf(
                ConnectionException::class,
                $exception->getPrevious(),
            );
        }
    }

    /**
     * Gemini API に正しいURLとリクエスト内容が送信されることを確認する。
     */
    public function test_generate_sends_expected_request_to_gemini_api(): void
    {
        config([
            'ai-chat.gemini.api_key' => 'test-api-key',
            'ai-chat.gemini.model' => 'gemini-3.8-flash',
        ]);

        Http::fake([
            '*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => 'テスト回答'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $contents = [
            [
                'role' => 'user',
                'parts' => [['text' => 'テスト質問']],
            ],
        ];

        app(GeminiService::class)->generate($contents);

        Http::assertSent(function (Request $request) use ($contents): bool {
            return $request->url()
                    === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent'
                && $request->hasHeader('x-goog-api-key', 'test-api-key')
                && $request['contents'] === $contents;
        });
    }

    /**
     * API応答にトークン使用量が含まれない場合、null が返ることを確認する。
     */
    public function test_generate_returns_null_tokens_when_usage_metadata_is_missing(): void
    {
        config([
            'ai-chat.gemini.api_key' => 'test-api-key',
            'ai-chat.gemini.model' => 'gemini-3.8-flash',
        ]);

        Http::fake([
            '*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => 'テスト回答'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result = app(GeminiService::class)->generate([
            [
                'role' => 'user',
                'parts' => [['text' => 'テスト質問']],
            ],
        ]);

        $this->assertSame('テスト回答', $result['text']);
        $this->assertNull($result['input_tokens']);
        $this->assertNull($result['output_tokens']);
    }

    /**
     * 回答の複数パーツが連結され、thought パーツが除外されることを確認する。
     */
    public function test_generate_joins_answer_parts_and_excludes_thought_parts(): void
    {
        config([
            'ai-chat.gemini.api_key' => 'test-api-key',
            'ai-chat.gemini.model' => 'gemini-3.8-flash',
        ]);

        Http::fake([
            '*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => '内部推論',
                                    'thought' => true,
                                ],
                                ['text' => 'こんにちは'],
                                ['text' => '！'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result = app(GeminiService::class)->generate([
            [
                'role' => 'user',
                'parts' => [['text' => '挨拶して']],
            ],
        ]);

        $this->assertSame('こんにちは！', $result['text']);
    }
}
