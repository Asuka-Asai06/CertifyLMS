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
 * GeminiService の外部 API 連携テスト。
 *
 * Gemini API へのリクエスト、回答の取得、
 * API キー未設定時や API 通信・応答取得失敗時の挙動を確認する。
 *
 * @group external-api
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

    /**
     * Gemini API が HTTP エラーを返した場合、
     * GeminiApiException に変換されることを確認する。
     */
    public function test_generate_throws_api_exception_when_api_returns_http_error(): void
    {
        // Arrange
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

        // Act / Assert
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
     * Gemini API との通信に失敗した場合、
     * GeminiApiException に変換されることを確認する。
     */
    public function test_generate_throws_api_exception_when_connection_fails(): void
    {
        // Arrange
        config([
            'ai-chat.gemini.api_key' => 'test-api-key',
            'ai-chat.gemini.model' => 'gemini-3.8-flash',
        ]);

        Http::fake(function (Request $request) {
            throw new ConnectionException('Connection timed out.');
        });

        // Act / Assert
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
     * Gemini API に正しい URL とリクエスト内容が送信されることを確認する。
     */
    public function test_generate_sends_expected_request_to_gemini_api(): void
    {
        // Arrange
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

        // Act
        app(GeminiService::class)->generate($contents);

        // Assert
        Http::assertSent(function (Request $request) use ($contents): bool {
            return $request->url()
                    === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent'
                && $request->hasHeader('x-goog-api-key', 'test-api-key')
                && $request['contents'] === $contents;
        });
    }

    /**
     * システム指示が指定された場合、
     * Gemini API へのリクエストに正しい形式で含まれることを確認する。
     */
    public function test_generate_sends_system_instruction_to_gemini_api(): void
    {
        // Arrange
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

        $systemInstruction = 'あなたは親切な学習アシスタントです。';

        // Act
        app(GeminiService::class)->generate(
            $contents,
            $systemInstruction,
        );

        // Assert
        Http::assertSent(
            function (Request $request) use (
                $contents,
                $systemInstruction,
            ): bool {
                return $request['contents'] === $contents
                    && $request['systemInstruction'] === [
                        'parts' => [
                            ['text' => $systemInstruction],
                        ],
                    ];
            },
        );
    }

    /**
     * API 応答にトークン使用量が含まれない場合、
     * トークン使用量が null で返ることを確認する。
     */
    public function test_generate_returns_null_tokens_when_usage_metadata_is_missing(): void
    {
        // Arrange
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

        // Act
        $result = app(GeminiService::class)->generate([
            [
                'role' => 'user',
                'parts' => [['text' => 'テスト質問']],
            ],
        ]);

        // Assert
        $this->assertSame('テスト回答', $result['text']);
        $this->assertNull($result['input_tokens']);
        $this->assertNull($result['output_tokens']);
    }

    /**
     * 回答の複数パーツが連結され、
     * thought パーツが回答から除外されることを確認する。
     */
    public function test_generate_joins_answer_parts_and_excludes_thought_parts(): void
    {
        // Arrange
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

        // Act
        $result = app(GeminiService::class)->generate([
            [
                'role' => 'user',
                'parts' => [['text' => '挨拶して']],
            ],
        ]);

        // Assert
        $this->assertSame('こんにちは！', $result['text']);
    }
}
