<?php

/**
 * CloudBSS plans. One place to define what each plan can do.
 * `features` are checked by Tenant::can('feature'); `order_cap` null = unlimited.
 * During a 30-day trial a tenant gets full 'pro' features automatically.
 */
return [
    'free' => [
        'name'      => 'Free',
        'price_usd' => 0,
        'price_ugx' => 0,
        'order_cap' => 30,
        'user_cap'  => 1,
        'features'  => ['bot', 'orders'],
    ],
    'starter' => [
        'name'      => 'Starter',
        'price_usd' => 20,
        'price_ugx' => 75000,
        'order_cap' => null,
        'user_cap'  => 2,
        'features'  => ['bot', 'orders', 'confirmations'],
    ],
    'pro' => [
        'name'      => 'Pro',
        'price_usd' => 50,
        'price_ugx' => 185000,
        'order_cap' => null,
        'user_cap'  => null,
        'features'  => ['bot', 'orders', 'confirmations', 'pos', 'dispatch', 'tracking', 'reports', 'returns', 'branding', 'multi_user'],
    ],
];
