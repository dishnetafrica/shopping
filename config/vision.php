<?php

/**
 * Vision Gateway ingestion + fleet claim settings.
 *
 * IMPORTANT: this file exists so the values survive `php artisan config:cache` — with a cached
 * config, env() calls inside controllers return null, which is exactly the silent-401 trap the
 * original env()-reading webhook would have hit. Controllers read config('vision.*') only.
 *
 * `token` + `tenant_id` are the LEGACY single-store path (manual .env on the box, one shared
 * token). Claimed boxes use their own per-box webhook_token from the vision_boxes table and
 * don't need either value. Keep the legacy pair for the hand-configured pilot box; drop them
 * once every box in the field is claim-provisioned.
 */
return [
    'token'           => env('VISION_WEBHOOK_TOKEN', ''),
    'tenant_id'       => (int) env('VISION_TENANT_ID', 0),
    'claim_ttl_hours' => (int) env('VISION_CLAIM_TTL_HOURS', 24),
];
