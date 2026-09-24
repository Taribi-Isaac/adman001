<?php

namespace App\Ai\Providers;

use App\Contracts\AiProvider;
use App\Support\AiProviderResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * OpenAI-compatible Chat Completions API (OpenAI, compatible proxies).
 */
final class OpenAiCompatibleProvider implements AiProvider
{
    public function complete(array $messages, array $tools = [], array $options = []): AiProviderResponse
    {
        $apiKey = (string) config('adman.ai.api_key', '');
        $baseUrl = rtrim((string) config('adman.ai.base_url', 'https://api.openai.com/v1'), '/');
        $model = (string) config('adman.ai.model', 'gpt-4o-mini');
        $timeout = (int) config('adman.ai.timeout', 45);

        if ($apiKey === '') {
            return AiProviderResponse::failed('AI provider is not configured (missing API key).', retryable: false);
        }

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => (float) ($options['temperature'] ?? 0.2),
        ];

        if ($tools !== []) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = $options['tool_choice'] ?? 'auto';
        }

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout($timeout)
                ->post($baseUrl.'/chat/completions', $payload);

            if (! $response->successful()) {
                $status = $response->status();
                $retryable = $status === 429 || $status >= 500;

                return AiProviderResponse::failed(
                    'AI provider request failed (HTTP '.$status.').',
                    retryable: $retryable,
                );
            }

            $choice = data_get($response->json(), 'choices.0.message');
            if (! is_array($choice)) {
                return AiProviderResponse::failed('AI provider returned an empty response.', retryable: true);
            }

            $toolCallsRaw = $choice['tool_calls'] ?? [];
            $toolCalls = [];
            if (is_array($toolCallsRaw)) {
                foreach ($toolCallsRaw as $call) {
                    if (! is_array($call)) {
                        continue;
                    }
                    $id = (string) ($call['id'] ?? uniqid('call_', true));
                    $name = (string) data_get($call, 'function.name', '');
                    $argsJson = (string) data_get($call, 'function.arguments', '{}');
                    $decoded = json_decode($argsJson, true);
                    if ($name === '') {
                        continue;
                    }
                    $toolCalls[] = [
                        'id' => $id,
                        'name' => $name,
                        'arguments' => is_array($decoded) ? $decoded : [],
                    ];
                }
            }

            $text = isset($choice['content']) && is_string($choice['content'])
                ? trim($choice['content'])
                : null;

            if ($toolCalls !== []) {
                return AiProviderResponse::tools($toolCalls, $text !== '' ? $text : null);
            }

            if ($text === null || $text === '') {
                return AiProviderResponse::failed('AI provider returned no content.', retryable: true);
            }

            return AiProviderResponse::text($text);
        } catch (ConnectionException $e) {
            report($e);

            return AiProviderResponse::failed('AI provider connection timed out.', retryable: true);
        } catch (Throwable $e) {
            report($e);

            return AiProviderResponse::failed('AI provider failed unexpectedly.', retryable: true);
        }
    }
}
