<?php

/**
 * Error alerting (Constitution v1.0 · P0 Reliability — observability). Consumed by App\Support\ErrorNotifier.
 * Complements the Sentry hook already in bootstrap/app.php; works with NO external package.
 * Default-inert: with no ERROR_WEBHOOK_URL set it only enriches the log — zero behaviour change.
 */
return [
    // Slack / Discord / any incoming webhook. Empty = log-only (no alerts sent).
    'webhook'          => env('ERROR_WEBHOOK_URL', ''),
    'enabled'          => (bool) env('ERROR_WEBHOOK_ENABLED', true),
    // Dedupe identical errors (same class+file+line) within this many minutes so a loop can't flood you.
    'throttle_minutes' => (int) env('ERROR_WEBHOOK_THROTTLE', 10),
    // Only alert on server-side failures (>= this HTTP status). 4xx/validation/auth are skipped.
    'min_status'       => (int) env('ERROR_WEBHOOK_MIN_STATUS', 500),
    'log_channel'      => env('ERROR_LOG_CHANNEL', 'stack'),
    'app_name'         => env('APP_NAME', 'CloudBSS'),
];
