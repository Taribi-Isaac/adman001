<?php

namespace App\Support;

use App\Enums\WhatsAppTemplateKey;

final class WhatsAppDeliveryPayload
{
    /**
     * @param  list<string>  $bodyParameters  Ordered body template parameters ({{1}}, {{2}}, …)
     * @param  string|null  $headerDocumentMediaId  Uploaded media id for a DOCUMENT header (PDF attachment)
     */
    public function __construct(
        public readonly string $to,
        public readonly string $templateName,
        public readonly string $languageCode,
        public readonly WhatsAppTemplateKey $templateKey,
        public readonly array $bodyParameters,
        public readonly int $messageId,
        public readonly ?string $headerDocumentMediaId = null,
        public readonly ?string $headerDocumentFilename = null,
    ) {}
}
