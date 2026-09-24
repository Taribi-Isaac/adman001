<?php

namespace App\Http\Controllers\WhatsApp;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\WhatsAppInboundService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    /**
     * Meta webhook verification challenge.
     */
    public function verify(Request $request): Response
    {
        $mode = (string) $request->query('hub_mode', $request->query('hub.mode', ''));
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token', ''));
        $challenge = (string) $request->query('hub_challenge', $request->query('hub.challenge', ''));

        $expected = (string) config('adman.whatsapp.webhook_verify_token', '');

        if ($mode === 'subscribe' && $expected !== '' && hash_equals($expected, $token)) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        Log::warning('whatsapp.webhook_verify_rejected');

        return response('Forbidden', 403);
    }

    /**
     * Inbound messages and delivery status events.
     */
    public function handle(Request $request, WhatsAppInboundService $inbound, AuditLogger $auditLogger): Response
    {
        if (! $this->signatureIsValid($request)) {
            $auditLogger->record(
                event: 'whatsapp.webhook_rejected',
                description: 'WhatsApp webhook signature validation failed',
                meta: [
                    'ip' => $request->ip(),
                ],
            );

            return response('Invalid signature', 403);
        }

        $payload = $request->all();
        if (! is_array($payload)) {
            return response('Bad Request', 400);
        }

        $inbound->handlePayload($payload);

        return response('EVENT_RECEIVED', 200);
    }

    private function signatureIsValid(Request $request): bool
    {
        $appSecret = (string) config('adman.whatsapp.app_secret', '');

        // Allow unsigned payloads only for local/testing scaffolding.
        // Staging and production require WHATSAPP_APP_SECRET.
        if ($appSecret === '') {
            return app()->environment('local', 'testing');
        }

        $header = (string) $request->header('X-Hub-Signature-256', '');
        if (! str_starts_with($header, 'sha256=')) {
            return false;
        }

        $provided = substr($header, 7);
        $expected = hash_hmac('sha256', $request->getContent(), $appSecret);

        return hash_equals($expected, $provided);
    }
}
