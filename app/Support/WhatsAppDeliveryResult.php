<?php

namespace App\Support;

final class WhatsAppDeliveryResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $failureReason = null,
        public readonly bool $retryable = true,
    ) {}

    public static function ok(string $providerMessageId): self
    {
        return new self(true, $providerMessageId);
    }

    public static function failed(string $reason, bool $retryable = true): self
    {
        return new self(false, null, $reason, $retryable);
    }
}
