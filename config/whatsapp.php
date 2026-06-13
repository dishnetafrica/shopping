<?php
return [
    // Global default driver. Per-tenant override lives in tenant->settings['whatsapp_driver'].
    'default' => env('WHATSAPP_DRIVER', 'evolution'),

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
