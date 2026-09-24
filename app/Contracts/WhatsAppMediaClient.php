<?php

namespace App\Contracts;

use App\Support\WhatsAppMediaDownloadResult;

interface WhatsAppMediaClient
{
    public function download(string $mediaId): WhatsAppMediaDownloadResult;
}
