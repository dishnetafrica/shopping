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
        'order_cap' => 30,
        'features'  => ['bot', 'orders'],
    ],
    'starter' => [
        'name'      => 'Starter',
        'price_usd' => 20,
        'order_cap' => null,
        'features'  => ['bot', 'orders', 'confirmations'],
    ],
    'pro' => [
        'name'      => 'Pro',
        'price_usd' => 50,
        'order_cap' => null,
        'features'  => ['bot', 'orders', 'confirmations', 'pos', 'dispatch', 'tracking', 'reports', 'returns', 'branding', 'multi_user'],
    ],
];
