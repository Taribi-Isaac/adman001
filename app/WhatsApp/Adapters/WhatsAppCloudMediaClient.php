<?php

namespace App\WhatsApp\Adapters;

use App\Contracts\WhatsAppMediaClient;
use App\Support\WhatsAppMediaDownloadResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Downloads inbound WhatsApp media via Meta Graph API into memory for private storage.
 */
final class WhatsAppCloudMediaClient implements WhatsAppMediaClient
{
    public function download(string $mediaId): WhatsAppMediaDownloadResult
    {
        $token = (string) config('adman.whatsapp.access_token', '');
        $baseUrl = rtrim((string) config('adman.whatsapp.base_url', 'https://graph.facebook.com'), '/');
        $version = trim((string) config('adman.whatsapp.api_version', 'v21.0'), '/');

        if ($token === '' || $mediaId === '') {
            return WhatsAppMediaDownloadResult::failed('WhatsApp media download is not configured.', retryable: false);
        }

        try {
            $metaResponse = Http::withToken($token)
                ->acceptJson()
                ->timeout(20)
                ->get("{$baseUrl}/{$version}/{$mediaId}");

            if (! $metaResponse->successful()) {
                $status = $metaResponse->status();

                return WhatsAppMediaDownloadResult::failed(
                    'Unable to resolve WhatsApp media metadata.',
                    retryable: $status === 429 || $status >= 500,
                );
            }

            $url = data_get($metaResponse->json(), 'url');
            $mime = (string) (data_get($metaResponse->json(), 'mime_type') ?: 'application/octet-stream');
            if (! is_string($url) || $url === '') {
                return WhatsAppMediaDownloadResult::failed('WhatsApp media URL missing.', retryable: true);
            }

            $fileResponse = Http::withToken($token)
                ->timeout(60)
                ->withHeaders(['Accept' => '*/*'])
                ->get($url);

            if (! $fileResponse->successful()) {
                $status = $fileResponse->status();

                return WhatsAppMediaDownloadResult::failed(
                    'Unable to download WhatsApp media content.',
                    retryable: $status === 429 || $status >= 500,
                );
            }

            $binary = $fileResponse->body();
            if ($binary === '') {
                return WhatsAppMediaDownloadResult::failed('WhatsApp media download returned empty content.', retryable: true);
            }

            $maxBytes = (int) config('adman.whatsapp.inbound_media_max_bytes', 15 * 1024 * 1024);
            if (strlen($binary) > $maxBytes) {
                return WhatsAppMediaDownloadResult::failed('Inbound media exceeds the allowed size limit.', retryable: false);
            }

            return WhatsAppMediaDownloadResult::ok($binary, $mime, [
                'provider_media_id' => $mediaId,
                'byte_size' => strlen($binary),
            ]);
        } catch (ConnectionException $e) {
            report($e);

            return WhatsAppMediaDownloadResult::failed('WhatsApp media download timed out.', retryable: true);
        } catch (Throwable $e) {
            report($e);

            return WhatsAppMediaDownloadResult::failed('WhatsApp media download failed unexpectedly.', retryable: true);
        }
    }
}
