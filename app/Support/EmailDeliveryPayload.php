<?php

namespace App\Support;

use App\Enums\EmailTemplateKey;

final class EmailDeliveryPayload
{
    /**
     * @param  array<string, mixed>  $viewData
     */
    public function __construct(
        public readonly string $toAddress,
        public readonly string $toName,
        public readonly string $subject,
        public readonly EmailTemplateKey $templateKey,
        public readonly array $viewData,
        public readonly string $fromAddress,
        public readonly string $fromName,
        public readonly ?string $replyTo,
        public readonly int $messageId,
        public readonly ?string $attachmentDisk = null,
        public readonly ?string $attachmentPath = null,
        public readonly ?string $attachmentFilename = null,
        public readonly string $attachmentMime = 'application/pdf',
    ) {}

    public function hasAttachment(): bool
    {
        return is_string($this->attachmentDisk)
            && $this->attachmentDisk !== ''
            && is_string($this->attachmentPath)
            && $this->attachmentPath !== ''
            && is_string($this->attachmentFilename)
            && $this->attachmentFilename !== '';
    }
}
