<?php

/**
 * Payment providers. A provider is "enabled" only when its keys are set,
 * so you can turn each on/off purely from the environment.
 *  - Flutterwave: MTN + Airtel Mobile Money (UGX) — and cards if you enable them there.
 *  - Stripe: international cards (USD), with true monthly auto-renew.
 */
return [
    'flutterwave' => [
        'secret'  => env('FLW_SECRET_KEY'),
        'public'  => env('FLW_PUBLIC_KEY'),
        'hash'    => env('FLW_WEBHOOK_HASH'),               // the "verif-hash" you set in the FLW dashboard
        'base'    => env('FLW_BASE_URL', 'https://api.flutterwave.com/v3'),
    ],
    'stripe' => [
        'secret'         => env('STRIPE_SECRET_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'currency'       => env('STRIPE_CURRENCY', 'usd'),
    ],
];
