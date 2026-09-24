<?php

namespace App\Support;

final class EmailDeliveryResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $failureReason = null,
    ) {}

    public static function ok(?string $providerMessageId = null): self
    {
        return new self(true, $providerMessageId);
    }

    public static function failed(string $reason): self
    {
        return new self(false, null, $reason);
    }
}
