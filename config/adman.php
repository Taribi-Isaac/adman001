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
    | is the org-level switch.
    |
    | Transactional templates are used only when the 24-hour customer service
    | window is closed. A template is used only when `enabled` is true AND a name
    | is set; enable it only after Meta shows it as APPROVED (Utility, DOCUMENT
    | header). Parameter contract: technical_Docs/008-whatsapp-communication.md.
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
            'quote' => [
                'name' => env('WHATSAPP_TEMPLATE_QUOTE'),
                'language' => env('WHATSAPP_TEMPLATE_QUOTE_LANGUAGE'),
                'enabled' => (bool) env('WHATSAPP_TEMPLATE_QUOTE_ENABLED', false),
            ],
            'invoice' => [
                'name' => env('WHATSAPP_TEMPLATE_INVOICE'),
                'language' => env('WHATSAPP_TEMPLATE_INVOICE_LANGUAGE'),
                'enabled' => (bool) env('WHATSAPP_TEMPLATE_INVOICE_ENABLED', false),
            ],
            'invoice_reminder' => [
                'name' => env('WHATSAPP_TEMPLATE_INVOICE_REMINDER'),
                'language' => env('WHATSAPP_TEMPLATE_INVOICE_REMINDER_LANGUAGE'),
                'enabled' => (bool) env('WHATSAPP_TEMPLATE_INVOICE_REMINDER_ENABLED', false),
            ],
            'payment_acknowledgement' => [
                'name' => env('WHATSAPP_TEMPLATE_PAYMENT_ACK'),
                'language' => env('WHATSAPP_TEMPLATE_PAYMENT_ACK_LANGUAGE'),
                'enabled' => (bool) env('WHATSAPP_TEMPLATE_PAYMENT_ACK_ENABLED', false),
            ],
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
