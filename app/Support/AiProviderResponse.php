<?php

namespace App\Support;

final class AiProviderResponse
{
    /**
     * @param  list<array{id: string, name: string, arguments: array<string, mixed>}>  $toolCalls
     */
    public function __construct(
        public readonly ?string $text = null,
        public readonly array $toolCalls = [],
        public readonly bool $failed = false,
        public readonly ?string $failureReason = null,
        public readonly bool $retryable = false,
    ) {}

    public static function text(string $text): self
    {
        return new self(text: $text);
    }

    /**
     * @param  list<array{id: string, name: string, arguments: array<string, mixed>}>  $toolCalls
     */
    public static function tools(array $toolCalls, ?string $assistantContent = null): self
    {
        return new self(text: $assistantContent, toolCalls: $toolCalls);
    }

    public static function failed(string $reason, bool $retryable = true): self
    {
        return new self(failed: true, failureReason: $reason, retryable: $retryable);
    }

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }
}
