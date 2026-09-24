<?php

namespace App\Support;

final class WhatsAppMediaDownloadResult
{
    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function __construct(
        public readonly bool $success,
        public readonly ?string $binary = null,
        public readonly ?string $mimeType = null,
        public readonly ?string $failureReason = null,
        public readonly bool $retryable = false,
        public readonly ?array $meta = null,
    ) {}

    public static function ok(string $binary, string $mimeType, ?array $meta = null): self
    {
        return new self(true, $binary, $mimeType, null, false, $meta);
    }

    public static function failed(string $reason, bool $retryable = false): self
    {
        return new self(false, null, null, $reason, $retryable);
    }
}
