<?php

namespace App\Contracts;

use App\Support\WhatsAppDeliveryPayload;
use App\Support\WhatsAppDeliveryResult;
use App\Support\WhatsAppDocumentPayload;
use App\Support\WhatsAppTextPayload;

interface WhatsAppDeliveryAdapter
{
    public function sendTemplate(WhatsAppDeliveryPayload $payload): WhatsAppDeliveryResult;

    /**
     * Session (free-form) text message within an open customer messaging window.
     */
    public function sendText(WhatsAppTextPayload $payload): WhatsAppDeliveryResult;

    /**
     * Upload a local file to Meta and return a media id in providerMessageId on success.
     */
    public function uploadMedia(string $absolutePath, string $mimeType, string $filename): WhatsAppDeliveryResult;

    /**
     * Send a WhatsApp document message (PDF attachment) using a previously uploaded media id.
     */
    public function sendDocument(WhatsAppDocumentPayload $payload): WhatsAppDeliveryResult;
}
