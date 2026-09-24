<?php

namespace App\WhatsApp\Adapters;

use App\Contracts\WhatsAppMediaClient;
use App\Support\WhatsAppMediaDownloadResult;

final class FakeWhatsAppMediaClient implements WhatsAppMediaClient
{
    public function __construct(
        private readonly string $binary = 'fake-media-bytes',
        private readonly string $mimeType = 'image/jpeg',
    ) {}

    public function download(string $mediaId): WhatsAppMediaDownloadResult
    {
        if ($mediaId === 'fail') {
            return WhatsAppMediaDownloadResult::failed('Simulated media download failure.', retryable: false);
        }

        return WhatsAppMediaDownloadResult::ok($this->binary, $this->mimeType, [
            'provider_media_id' => $mediaId,
            'byte_size' => strlen($this->binary),
        ]);
    }
}
