<?php
return [
    // Global default driver. Per-tenant override lives in tenant->settings['whatsapp_driver'].
    'default' => env('WHATSAPP_DRIVER', 'evolution'),

    // Token a tenant pastes into Meta's webhook "Verify token" field when they
    // connect their own Cloud API number. One value for the whole platform;
    // inbound messages are routed to the right tenant by phone_number_id.
    'cloud_verify_token' => env('WHATSAPP_CLOUD_VERIFY_TOKEN', 'cloudbss-verify'),

    'drivers' => [
        'evolution' => [
            'base_url' => env('EVOLUTION_BASE_URL'),
            'api_key'  => env('EVOLUTION_API_KEY'),
        ],
        'cloud' => [
            'token'    => env('WHATSAPP_CLOUD_TOKEN'),
            'phone_id' => env('WHATSAPP_CLOUD_PHONE_ID'),
            'base_url' => 'https://graph.facebook.com/v20.0',
        ],
    ],
];
