<?php

return [

    /*
    | Master switch. Research is OFF unless this is true AND the tenant has
    | `research_enabled` on. Two gates on purpose: an install-wide kill switch
    | that does not require touching 30 tenants, and a per-tenant opt-in.
    */
    'enabled' => (bool) env('RESEARCH_ENABLED', true),

    /*
    | Provider registry. key => class implementing
    | App\Services\Research\Contracts\ResearchProvider. Order matters: the
    | orchestrator asks them in this order and the FIRST provider to return a
    | field wins, so authoritative sources must come before inferred ones.
    */
    'providers' => [
        'barcode' => \App\Services\Research\Providers\BarcodeProvider::class,   // exact, from the EAN
        'ai'      => \App\Services\Research\Providers\AiKnowledgeProvider::class, // inferred, lower confidence
    ],

    /*
    | Open Food Facts. Free, ODbL-licensed, keyed on the exact barcode — so for
    | a product that HAS an EAN this is fact, not inference.
    |
    | Their stated rule is "1 API call = 1 real scan by a user"; scraping the
    | database through the API will be blocked. So: the interactive scan path
    | calls it once per scan (exactly as intended), and the bulk command only
    | touches it behind an explicit flag, throttled, with a hard per-run cap.
    | For a large backfill use their published data export instead.
    |
    | Attribution is required and is rendered on any product whose facts came
    | from here — see ProductResearch::ATTRIBUTION.
    */
    'barcode' => [
        'base'       => env('OFF_BASE', 'https://world.openfoodfacts.org'),
        'timeout'    => (int) env('OFF_TIMEOUT', 12),
        // They ask for a UA that identifies the app and gives them a contact.
        'user_agent' => env('OFF_UA', 'CloudBSS-ShopBot/4.29 (https://mycloudbss.com; support@mycloudbss.com)'),
        'cache_days' => (int) env('OFF_CACHE_DAYS', 30),
        'bulk_cap'   => (int) env('OFF_BULK_CAP', 250),   // max live lookups in one command run
        'bulk_delay' => (int) env('OFF_BULK_DELAY_MS', 1100),  // ms between live lookups
    ],

    /*
    | Confidence gating, mirroring App\Services\Enrichment\ProductEnrichmentService
    | so the two AI pipelines behave the same way and can be reasoned about together.
    |
    |   >= auto_apply  -> written, but ONLY into a field that is empty
    |   >= review      -> queued for a human
    |   <  review      -> dropped
    */
    'confidence' => [
        'auto_apply' => (float) env('RESEARCH_CONF_APPLY', 0.85),
        'review'     => (float) env('RESEARCH_CONF_REVIEW', 0.55),
    ],

    /*
    | Fields research is allowed to touch, ever. Deliberately excludes price,
    | cost, stock and barcode: price is commercial, stock is physical, and the
    | barcode is the lookup KEY — overwriting it would corrupt the identity of
    | the row. Adding a field here also requires a validator in ProductResearch.
    */
    'fields' => ['brand', 'unit_size', 'origin_country', 'category', 'description'],

    /*
    | There is no AI spend metering anywhere in this app yet. Until there is,
    | this is the blunt instrument: a per-tenant ceiling on research calls per
    | day, counted in cache. Prevents a runaway loop or an over-eager backfill
    | from quietly spending a month's budget in an afternoon.
    */
    'daily_cap' => (int) env('RESEARCH_DAILY_CAP', 400),

];
