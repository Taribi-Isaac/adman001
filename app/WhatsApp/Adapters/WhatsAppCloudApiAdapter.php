<?php

namespace App\WhatsApp\Adapters;

use App\Contracts\WhatsAppDeliveryAdapter;
use App\Support\WhatsAppDeliveryPayload;
use App\Support\WhatsAppDeliveryResult;
use App\Support\WhatsAppDocumentPayload;
use App\Support\WhatsAppTextPayload;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * WhatsApp Cloud API (Meta Graph) delivery adapter.
 * Credentials and endpoints come from config/adman.php — never from Business settings.
 */
final class WhatsAppCloudApiAdapter implements WhatsAppDeliveryAdapter
{
    public function sendTemplate(WhatsAppDeliveryPayload $payload): WhatsAppDeliveryResult
    {
        $credentials = $this->credentials();
        if ($credentials instanceof WhatsAppDeliveryResult) {
            return $credentials;
        }

        [$token, $phoneNumberId, $url] = $credentials;

        $bodyParams = [];
        foreach ($payload->bodyParameters as $text) {
            $bodyParams[] = [
                'type' => 'text',
                'text' => $text,
            ];
        }

        $requestBody = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $payload->to,
            'type' => 'template',
            'template' => [
                'name' => $payload->templateName,
                'language' => [
                    'code' => $payload->languageCode,
                ],
                'components' => [
                    [
                        'type' => 'body',
                        'parameters' => $bodyParams,
                    ],
                ],
            ],
        ];

        return $this->postMessage($url, $token, $requestBody);
    }

    public function sendText(WhatsAppTextPayload $payload): WhatsAppDeliveryResult
    {
        $credentials = $this->credentials();
        if ($credentials instanceof WhatsAppDeliveryResult) {
            return $credentials;
        }

        [$token, $phoneNumberId, $url] = $credentials;

        $body = trim($payload->body);
        if ($body === '') {
            return WhatsAppDeliveryResult::failed('Message body is empty.', retryable: false);
        }

        // WhatsApp Cloud API text body limit is 4096 characters.
        if (mb_strlen($body) > 4096) {
            $body = mb_substr($body, 0, 4093).'...';
        }

        $requestBody = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $payload->to,
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => $body,
            ],
        ];

        return $this->postMessage($url, $token, $requestBody);
    }

    public function uploadMedia(string $absolutePath, string $mimeType, string $filename): WhatsAppDeliveryResult
    {
        $credentials = $this->credentials();
        if ($credentials instanceof WhatsAppDeliveryResult) {
            return $credentials;
        }

        [$token, $phoneNumberId] = $credentials;

        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            return WhatsAppDeliveryResult::failed('Document file is missing or unreadable.', retryable: false);
        }

        $baseUrl = rtrim((string) config('adman.whatsapp.base_url', 'https://graph.facebook.com'), '/');
        $version = trim((string) config('adman.whatsapp.api_version', 'v21.0'), '/');
        $uploadUrl = "{$baseUrl}/{$version}/{$phoneNumberId}/media";

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(60)
                ->attach('file', (string) file_get_contents($absolutePath), $filename, ['Content-Type' => $mimeType])
                ->post($uploadUrl, [
                    'messaging_product' => 'whatsapp',
                    'type' => $mimeType,
                ]);

            if ($response->successful()) {
                $mediaId = data_get($response->json(), 'id');
                if (! is_string($mediaId) || $mediaId === '') {
                    return WhatsAppDeliveryResult::failed(
                        'WhatsApp accepted the media upload but did not return a media id.',
                        retryable: true,
                    );
                }

                return WhatsAppDeliveryResult::ok($mediaId);
            }

            $status = $response->status();
            $retryable = $status === 429 || $status >= 500;

            return WhatsAppDeliveryResult::failed(
                $this->staffSafeFailure($status, null),
                retryable: $retryable,
            );
        } catch (ConnectionException $e) {
            report($e);

            return WhatsAppDeliveryResult::failed(
                'WhatsApp media upload timed out or failed to connect. Please retry later.',
                retryable: true,
            );
        } catch (Throwable $e) {
            report($e);

            return WhatsAppDeliveryResult::failed(
                'WhatsApp media upload failed unexpectedly. Please retry later.',
                retryable: true,
            );
        }
    }

    public function sendDocument(WhatsAppDocumentPayload $payload): WhatsAppDeliveryResult
    {
        $credentials = $this->credentials();
        if ($credentials instanceof WhatsAppDeliveryResult) {
            return $credentials;
        }

        [$token, $phoneNumberId, $url] = $credentials;

        $document = [
            'id' => $payload->mediaId,
            'filename' => $payload->filename,
        ];

        $caption = trim((string) ($payload->caption ?? ''));
        if ($caption !== '') {
            if (mb_strlen($caption) > 1024) {
                $caption = mb_substr($caption, 0, 1021).'...';
            }
            $document['caption'] = $caption;
        }

        $requestBody = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $payload->to,
            'type' => 'document',
            'document' => $document,
        ];

        return $this->postMessage($url, $token, $requestBody);
    }

    /**
     * @return array{0: string, 1: string, 2: string}|WhatsAppDeliveryResult
     */
    private function credentials(): array|WhatsAppDeliveryResult
    {
        $token = (string) config('adman.whatsapp.access_token', '');
        $phoneNumberId = (string) config('adman.whatsapp.phone_number_id', '');
        $baseUrl = rtrim((string) config('adman.whatsapp.base_url', 'https://graph.facebook.com'), '/');
        $version = trim((string) config('adman.whatsapp.api_version', 'v21.0'), '/');

        if ($token === '' || $phoneNumberId === '') {
            return WhatsAppDeliveryResult::failed(
                'WhatsApp is not configured. Set WHATSAPP_ACCESS_TOKEN and WHATSAPP_PHONE_NUMBER_ID.',
                retryable: false,
            );
        }

        return [$token, $phoneNumberId, "{$baseUrl}/{$version}/{$phoneNumberId}/messages"];
    }

    /**
     * @param  array<string, mixed>  $requestBody
     */
    private function postMessage(string $url, string $token, array $requestBody): WhatsAppDeliveryResult
    {
        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(20)
                ->post($url, $requestBody);

            if ($response->successful()) {
                $providerId = data_get($response->json(), 'messages.0.id');
                if (! is_string($providerId) || $providerId === '') {
                    return WhatsAppDeliveryResult::failed(
                        'WhatsApp accepted the request but did not return a message id.',
                        retryable: true,
                    );
                }

                return WhatsAppDeliveryResult::ok($providerId);
            }

            $status = $response->status();
            $errorCode = data_get($response->json(), 'error.code');
            $retryable = $status === 429 || $status >= 500;

            if (in_array($status, [400, 401, 403, 404], true)) {
                $retryable = false;
            }

            return WhatsAppDeliveryResult::failed(
                $this->staffSafeFailure($status, is_scalar($errorCode) ? (string) $errorCode : null),
                retryable: $retryable,
            );
        } catch (ConnectionException $e) {
            report($e);

            return WhatsAppDeliveryResult::failed(
                'WhatsApp network timeout or connection failure. Please retry later.',
                retryable: true,
            );
        } catch (Throwable $e) {
            report($e);

            return WhatsAppDeliveryResult::failed(
                'WhatsApp delivery failed unexpectedly. Please retry later.',
                retryable: true,
            );
        }
    }

    private function staffSafeFailure(int $status, ?string $errorCode): string
    {
        return match (true) {
            $status === 401, $status === 403 => 'WhatsApp authentication failed. Check server credentials.',
            $status === 404 => 'WhatsApp phone number configuration looks invalid.',
            $status === 429 => 'WhatsApp rate limit reached. Please retry later.',
            $errorCode === '132000', $errorCode === '132001' => 'WhatsApp template is missing or not approved.',
            $errorCode === '132012' => 'WhatsApp template parameters do not match the approved template.',
            $status >= 400 && $status < 500 => 'WhatsApp rejected the message. Check recipient and template configuration.',
            default => 'WhatsApp provider rejected or failed the send. Please retry later.',
        };
    }
}
