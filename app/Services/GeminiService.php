<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AiChat\GeminiApiException;
use App\Exceptions\AiChat\GeminiUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class GeminiService
{
    private const API_BASE_URL =
        'https://generativelanguage.googleapis.com/v1beta/models/';

    /**
     * Gemini API に質問を送り、回答と利用情報を取得する。
     *
     * @param array<int, array{
     *     role: string,
     *     parts: array<int, array{text: string}>
     * }> $contents
     * @param string|null $systemInstruction AI への指示・コンテキスト
     *
     * @return array{
     *     text: string,
     *     model: string,
     *     input_tokens: int|null,
     *     output_tokens: int|null
     * }
     *
     * @throws GeminiUnavailableException APIキーが未設定の場合
     * @throws GeminiApiException API通信または応答取得に失敗した場合
     */
    public function generate(
        array $contents,
        ?string $systemInstruction = null,
    ): array {
        $apiKey = (string) config('ai-chat.gemini.api_key', '');
        $model = (string) config(
            'ai-chat.gemini.model',
            'gemini-3.8-flash',
        );
        $timeout = (int) config('ai-chat.gemini.timeout', 30);

        if ($apiKey === '') {
            throw new GeminiUnavailableException;
        }

        // 従来の会話履歴を維持し、指示がある場合のみ追加する。
        $payload = [
            'contents' => $contents,
        ];

        if (
            $systemInstruction !== null
            && trim($systemInstruction) !== ''
        ) {
            $payload['systemInstruction'] = [
                'parts' => [
                    ['text' => $systemInstruction],
                ],
            ];
        }

        try {
            $response = Http::acceptJson()
                ->withHeaders([
                    'x-goog-api-key' => $apiKey,
                ])
                ->timeout($timeout)
                ->post(
                    self::API_BASE_URL.$model.':generateContent',
                    $payload,
                );

            $response->throw();
        } catch (ConnectionException $exception) {
            throw new GeminiApiException(
                null,
                $exception,
            );
        } catch (RequestException $exception) {
            throw new GeminiApiException(
                $exception->response->status(),
                $exception,
            );
        }

        $data = $response->json();

        $parts = $data['candidates'][0]['content']['parts'] ?? [];

        $text = collect($parts)
            ->filter(
                fn (array $part): bool => isset($part['text'])
                    && ! ($part['thought'] ?? false),
            )
            ->pluck('text')
            ->implode('');

        if ($text === '') {
            throw new GeminiApiException;
        }

        $usage = $data['usageMetadata'] ?? [];

        return [
            'text' => $text,
            'model' => $model,
            'input_tokens' => isset($usage['promptTokenCount'])
                ? (int) $usage['promptTokenCount']
                : null,
            'output_tokens' => isset($usage['candidatesTokenCount'])
                ? (int) $usage['candidatesTokenCount']
                : null,
        ];
    }
}
