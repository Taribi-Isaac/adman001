<?php

namespace App\Http\Controllers\Settings;

use App\Enums\EmailTemplateKey;
use App\Enums\WhatsAppTemplateKey;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Support\Permissions;
use Inertia\Inertia;
use Inertia\Response;

class CommunicationSettingsController extends Controller
{
    public function edit(): Response
    {
        $this->authorize(Permissions::SETTINGS_ACCESS);

        $business = Business::current();

        $emailConfigured = filled(config('mail.default'))
            && (string) config('mail.default') !== '';

        $whatsappConfigured = filled(config('adman.whatsapp.access_token'))
            && filled(config('adman.whatsapp.phone_number_id'));

        return Inertia::render('settings/communication/Edit', [
            'channels' => [
                'email' => [
                    'outbound_enabled' => (bool) $business->outbound_email_enabled,
                    'from_address' => $business->email,
                    'reply_to' => $business->email_reply_to,
                    'mailer' => (string) config('mail.default'),
                    'provider_configured' => $emailConfigured,
                    'settings_href' => route('settings.business.edit', absolute: false),
                ],
                'whatsapp' => [
                    'outbound_enabled' => (bool) $business->outbound_whatsapp_enabled,
                    'feature_enabled' => (bool) config('adman.whatsapp.enabled', true),
                    'phone_number_id_set' => filled(config('adman.whatsapp.phone_number_id')),
                    'access_token_set' => filled(config('adman.whatsapp.access_token')),
                    'app_secret_set' => filled(config('adman.whatsapp.app_secret')),
                    'verify_token_set' => filled(config('adman.whatsapp.webhook_verify_token')),
                    'provider_configured' => $whatsappConfigured,
                    'document_delivery' => 'pdf_attachment',
                    'settings_href' => route('settings.business.edit', absolute: false),
                ],
            ],
            'message_types' => [
                [
                    'key' => EmailTemplateKey::Quote->value,
                    'label' => 'Quote',
                    'email' => true,
                    'whatsapp' => true,
                    'delivery' => 'PDF attachment (email + WhatsApp)',
                ],
                [
                    'key' => EmailTemplateKey::Invoice->value,
                    'label' => 'Invoice',
                    'email' => true,
                    'whatsapp' => true,
                    'delivery' => 'PDF attachment (email + WhatsApp)',
                ],
                [
                    'key' => EmailTemplateKey::InvoiceReminder->value,
                    'label' => 'Invoice reminder',
                    'email' => true,
                    'whatsapp' => true,
                    'delivery' => 'PDF attachment (email + WhatsApp)',
                ],
                [
                    'key' => EmailTemplateKey::PaymentAcknowledgement->value,
                    'label' => 'Payment acknowledgement',
                    'email' => true,
                    'whatsapp' => true,
                    'delivery' => 'PDF attachment (email + WhatsApp)',
                ],
                [
                    'key' => 'ai_session',
                    'label' => 'AI / staff session chat',
                    'email' => false,
                    'whatsapp' => true,
                    'delivery' => 'WhatsApp session text',
                ],
            ],
            'whatsapp_templates' => collect(WhatsAppTemplateKey::cases())->map(fn (WhatsAppTemplateKey $key) => [
                'key' => $key->value,
                'label' => $key->label(),
                'configured_name' => (string) config('adman.whatsapp.templates.'.$key->configKey(), ''),
            ])->values()->all(),
            'related' => [
                'business' => route('settings.business.edit', absolute: false),
                'automation' => route('settings.automation.reminders.edit', absolute: false),
                'ai' => route('settings.ai.edit', absolute: false),
                'knowledge' => route('settings.knowledge.index', absolute: false),
            ],
            'canUpdateBusiness' => auth()->user()?->can(Permissions::BUSINESS_UPDATE) ?? false,
        ]);
    }
}
