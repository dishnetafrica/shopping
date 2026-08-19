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

    /**
     * Device-license signing (Ed25519, libsodium). Read via config() so they survive `config:cache`.
     *   license_secret — the SIGNING PRIVATE key (base64). SERVER-SIDE ONLY — never bundle it in an
     *                    edge image, never give it to a hardware supplier. Used by LicenseSigner.
     *   license_public — the VERIFICATION public key (base64). Safe to expose; it is baked into the
     *                    edge image (SHOPBOT_LICENSE_PUBKEY) so a box can verify licenses offline.
     * Generate once:  $kp=sodium_crypto_sign_keypair();
     *   VISION_LICENSE_SECRET=base64(sodium_crypto_sign_secretkey($kp))
     *   VISION_LICENSE_PUBLIC=base64(sodium_crypto_sign_publickey($kp))
     */
    'license_secret'  => env('VISION_LICENSE_SECRET', ''),
    'license_public'  => env('VISION_LICENSE_PUBLIC', ''),

    /**
     * Phase-0 Stock & Loss reconciliation (single dispatch-door slice). OFF by default — turning it
     * on only affects MOVEMENT events (carton.move / pallet.move / zone.entry / …); classical shelf
     * events are never touched. Per-tenant overrides live in tenant settings (vision_* keys).
     */
    'reconcile' => [
        'enabled'                 => (bool) env('VISION_RECONCILE', false),
        'grace_seconds'           => (int) env('VISION_RECONCILE_GRACE', 900),
        'match_window_seconds'    => (int) env('VISION_RECONCILE_WINDOW', 300),
        'net_zero_window_seconds' => (int) env('VISION_RECONCILE_NETZERO', 300),
        // Quantity-level reconciliation is DEFERRED for the PoC (a carton is not a SKU-unit), so the
        // slice reconciles on the PRESENCE of a matching outbound transaction, not exact counts.
        'qty_tolerance'           => 1_000_000,
        'zone_location_map'       => [],   // ['D->EXIT' => 'juba'] — event zone → stock_movements.location
        'controlled_zones'        => [],   // ['D->EXIT','X'] — a red only fires on these
    ],
];
