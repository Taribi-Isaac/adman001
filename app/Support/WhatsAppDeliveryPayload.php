<?php

namespace App\Support;

use App\Enums\WhatsAppTemplateKey;

final class WhatsAppDeliveryPayload
{
    /**
     * @param  list<string>  $bodyParameters  Ordered body template parameters ({{1}}, {{2}}, …)
     */
    public function __construct(
        public readonly string $to,
        public readonly string $templateName,
        public readonly string $languageCode,
        public readonly WhatsAppTemplateKey $templateKey,
        public readonly array $bodyParameters,
        public readonly int $messageId,
    ) {}
}
