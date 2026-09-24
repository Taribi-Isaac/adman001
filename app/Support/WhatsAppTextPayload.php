<?php

namespace App\Support;

final class WhatsAppTextPayload
{
    public function __construct(
        public readonly string $to,
        public readonly string $body,
        public readonly int $messageId,
    ) {}
}
