<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Special-Order marketplace search
    |--------------------------------------------------------------------------
    | When a customer asks for something the shop does NOT stock, ShopBot can
    | look it up on a live marketplace, apply the tenant's landed-cost pricing,
    | and quote a "Special Order" price instead of dead-ending.
    |
    | This subsystem is PROVIDER-BASED. The bot never talks to any specific
    | marketplace — it talks to App\Services\Marketplace\MarketplaceSearch,
    | which resolves whichever provider the tenant has configured. Adding a new
    | marketplace (Amazon, Noon, Alibaba, a supplier API, a manual DB) is a new
    | class registered under `providers` below — no bot changes.
    */

    // Which provider a tenant uses if they don't override it in their settings.
    'default_provider' => env('MARKETPLACE_DEFAULT_PROVIDER', 'jumia'),

    /*
    | Provider registry. key => fully-qualified class implementing
    | App\Services\Marketplace\Contracts\MarketplaceProvider. Each class is
    | resolved from the container, so it can declare its own dependencies.
    */
    'providers' => [
        'jumia' => \App\Services\Marketplace\Providers\JumiaProvider::class,
        // 'amazon'   => \App\Services\Marketplace\Providers\AmazonProvider::class,
        // 'noon'     => \App\Services\Marketplace\Providers\NoonProvider::class,
        // 'alibaba'  => \App\Services\Marketplace\Providers\AlibabaProvider::class,
        // 'supplier' => \App\Services\Marketplace\Providers\SupplierDbProvider::class,
    ],

    /*
    | The existing Playwright price API (do NOT rebuild the scraper). Runs on a
    | box with a residential Uganda IP and exposes:
    |   GET {url}/search?key={key}&q={query}
    |   GET {url}/health
    | DishNet and ShopBot can point at the SAME api. Consumed by JumiaProvider.
    */
    'jumia' => [
        'url'     => env('PRICE_API_URL', ''),
        'key'     => env('PRICE_API_KEY', ''),
        'timeout' => (int) env('PRICE_API_TIMEOUT', 60),   // live search can take 10-20s
    ],

    /*
    | Confidence gating. A marketplace result is only quoted outright when the
    | product name is a strong match for what the customer typed. A middling
    | match asks "Did you mean X?" first; a weak match is dropped (the shop
    | confirms by hand). Tune per install; per-tenant override in settings.
    */
    'confidence' => [
        'high'   => (float) env('MARKETPLACE_CONF_HIGH', 0.60),   // >= high  -> quote
        'medium' => (float) env('MARKETPLACE_CONF_MEDIUM', 0.30), // >= medium-> "did you mean?"
    ],                                                            // < medium -> drop, log lead

    // Max marketplace options shown to a customer in one reply.
    'max_options' => (int) env('MARKETPLACE_MAX_OPTIONS', 3),

    // Default markup if the tenant hasn't set special_order_margin (fraction).
    'default_margin' => (float) env('MARKETPLACE_DEFAULT_MARGIN', 0.15),

    /*
    | Photo host allow-list. Only image URLs whose host matches one of these
    | patterns are ever forwarded to a customer, so a bad scrape can never make
    | the bot send an arbitrary URL. '*' wildcards match any label segment.
    */
    'allowed_image_hosts' => [
        '*.jumia.is',
        '*.jumia.com.ug',
        'ug.jumia.is',
        // add a provider's CDN host here when you add the provider.
    ],

    // Default special-order terms line (tenant can override in settings).
    'default_terms' => env(
        'MARKETPLACE_DEFAULT_TERMS',
        'This is a special order — stock is brought in on request, usually 1–2 days, with full advance payment.'
    ),

];
