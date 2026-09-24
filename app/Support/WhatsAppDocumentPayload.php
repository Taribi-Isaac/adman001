<?php

namespace App\Support;

final class WhatsAppDocumentPayload
{
    public function __construct(
        public readonly string $to,
        public readonly string $mediaId,
        public readonly string $filename,
        public readonly string $mimeType,
        public readonly ?string $caption,
        public readonly int $messageId,
    ) {}
}
