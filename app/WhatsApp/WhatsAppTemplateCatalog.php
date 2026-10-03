<?php

namespace App\WhatsApp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Read-only lookup of approved template wording in WhatsApp Manager, used only to show staff
 * a preview. Never used to decide whether or what to send.
 */
class WhatsAppTemplateCatalog
{
    private const CACHE_SECONDS = 600;

    /**
     * The BODY text of an APPROVED template, or null when it cannot be read.
     */
    public function approvedBody(string $name, string $language): ?string
    {
        $token = (string) config('adman.whatsapp.access_token', '');
        $accountId = (string) config('adman.whatsapp.business_account_id', '');

        if ($token === '' || $accountId === '' || $name === '' || $language === '') {
            return null;
        }

        $body = Cache::remember(
            'whatsapp-template-body:'.sha1($accountId.'|'.$name.'|'.$language),
            self::CACHE_SECONDS,
            fn () => $this->fetch($token, $accountId, $name, $language) ?? '',
        );

        return $body === '' ? null : $body;
    }

    private function fetch(string $token, string $accountId, string $name, string $language): ?string
    {
        $baseUrl = rtrim((string) config('adman.whatsapp.base_url', 'https://graph.facebook.com'), '/');
        $version = trim((string) config('adman.whatsapp.api_version', 'v21.0'), '/');

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(5)
                ->get("{$baseUrl}/{$version}/{$accountId}/message_templates", [
                    'name' => $name,
                    'fields' => 'name,language,status,components',
                    'limit' => 50,
                ]);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        foreach ((array) data_get($response->json(), 'data', []) as $template) {
            if (data_get($template, 'name') !== $name
                || data_get($template, 'language') !== $language
                || data_get($template, 'status') !== 'APPROVED') {
                continue;
            }

            foreach ((array) data_get($template, 'components', []) as $component) {
                if (data_get($component, 'type') === 'BODY' && is_string(data_get($component, 'text'))) {
                    return (string) data_get($component, 'text');
                }
            }
        }

        return null;
    }
}
