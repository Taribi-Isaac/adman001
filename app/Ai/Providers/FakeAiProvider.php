<?php

namespace App\Ai\Providers;

use App\Contracts\AiProvider;
use App\Support\AiProviderResponse;

/**
 * Deterministic provider for automated tests and local runs without an API key.
 *
 * Script responses via {@see script()} / {@see respondWith()}.
 */
final class FakeAiProvider implements AiProvider
{
    /** @var list<AiProviderResponse> */
    private static array $queue = [];

    private static ?AiProviderResponse $default = null;

    public static function reset(): void
    {
        self::$queue = [];
        self::$default = null;
    }

    public static function respondWith(string $text): void
    {
        self::$default = AiProviderResponse::text($text);
    }

    /**
     * @param  list<AiProviderResponse>  $responses
     */
    public static function script(array $responses): void
    {
        self::$queue = array_values($responses);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public static function nextToolCall(string $name, array $arguments = [], string $id = 'call_test_1'): void
    {
        self::$queue[] = AiProviderResponse::tools([
            [
                'id' => $id,
                'name' => $name,
                'arguments' => $arguments,
            ],
        ]);
    }

    public function complete(array $messages, array $tools = [], array $options = []): AiProviderResponse
    {
        if (self::$queue !== []) {
            return array_shift(self::$queue);
        }

        if (self::$default !== null) {
            return self::$default;
        }

        // Heuristic fallback for unscripted local use: echo last user message context.
        $lastUser = '';
        foreach (array_reverse($messages) as $message) {
            if (($message['role'] ?? null) === 'user' && is_string($message['content'] ?? null)) {
                $lastUser = $message['content'];
                break;
            }
        }

        if (preg_match('/human|speak to someone|talk to (a )?person|real person|support agent/i', $lastUser) === 1) {
            return AiProviderResponse::tools([
                [
                    'id' => 'call_handoff',
                    'name' => 'request_human_handoff',
                    'arguments' => ['reason' => 'Customer requested a human'],
                ],
            ]);
        }

        return AiProviderResponse::text(
            'Thank you for your message. I can help with general business information and, when your account is linked, with your invoices and payments. How can I assist you?'
        );
    }
}
