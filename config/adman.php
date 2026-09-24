<?php

return [

    'email' => [
        'enabled' => env('ADMAN_EMAIL_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Cloud API
    |--------------------------------------------------------------------------
    |
    | Secrets stay in environment variables. Business.outbound_whatsapp_enabled
    | is the org-level switch. Template names must match Meta-approved templates
    | with body parameters: {{1}} customer name, {{2}} document number, {{3}} secure URL.
    |
    */

    'whatsapp' => [
        'enabled' => env('ADMAN_WHATSAPP_ENABLED', true),
        'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),
        'base_url' => env('WHATSAPP_API_BASE_URL', 'https://graph.facebook.com'),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),
        'webhook_verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'template_language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'en'),
        // Max inbound media size accepted for private storage (bytes).
        'inbound_media_max_bytes' => (int) env('WHATSAPP_INBOUND_MEDIA_MAX_BYTES', 15 * 1024 * 1024),
        'templates' => [
            'quote' => env('WHATSAPP_TEMPLATE_QUOTE', 'adman_quote'),
            'invoice' => env('WHATSAPP_TEMPLATE_INVOICE', 'adman_invoice'),
            'invoice_reminder' => env('WHATSAPP_TEMPLATE_INVOICE_REMINDER', 'adman_invoice_reminder'),
            'payment_acknowledgement' => env('WHATSAPP_TEMPLATE_PAYMENT_ACK', 'adman_payment_ack'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI assistant
    |--------------------------------------------------------------------------
    |
    | Provider credentials stay in the environment. Business.ai_enabled and
    | ai_customer_responses_enabled are org-level switches.
    |
    */

    'ai' => [
        'enabled' => env('ADMAN_AI_ENABLED', false),
        'provider' => env('ADMAN_AI_PROVIDER', 'openai'),
        'api_key' => env('ADMAN_AI_API_KEY'),
        'base_url' => env('ADMAN_AI_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('ADMAN_AI_MODEL', 'gpt-4o-mini'),
        'timeout' => (int) env('ADMAN_AI_TIMEOUT', 45),
    ],
];
