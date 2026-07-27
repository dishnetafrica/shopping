<?php
/**
 * Add to app/Http/Controllers/Storefront/StorefrontController.php
 *
 * Mirrors the existing manufacturer brand-site methods (brandSiteEnabled / brand /
 * resolveTheme). No new route — `/{shop}` already resolves to landing(); we add one
 * branch there and two helper methods. Everything reads tenant `settings` (no schema
 * change), so non-auto-parts tenants are completely unaffected.
 */

use App\Support\Vertical;

// ── 1. In landing(), before the manufacturer/brand branch ────────────────────
//
//     public function landing(string $shop)
//     {
//         $tenant = $this->resolveTenant($shop);
//
//         if ($this->autoPartsEnabled($tenant)) {
//             return $this->autoParts($tenant);          // <── ADD THIS
//         }
//         if ($this->brandSiteEnabled($tenant)) {
//             return $this->brand($tenant);
//         }
//         return $this->shop($tenant);                    // everyone else, unchanged
//     }

// ── 2. Gate ──────────────────────────────────────────────────────────────────
protected function autoPartsEnabled($tenant): bool
{
    return Vertical::of($tenant) === Vertical::AUTO_PARTS
        || (bool) $tenant->setting('auto_parts_site', false);
}

// ── 3. Render the EPC storefront ─────────────────────────────────────────────
protected function autoParts($tenant)
{
    $config = $this->resolveAutoPartsConfig($tenant);

    // Live catalogue: the tenant's own models / systems / parts.
    // Reuse the same catalogue queries the shop uses, grouped for the EPC UI.
    $config['models']   = $this->autoPartsModels($tenant);   // [{id,name,tagline,badges,hero,keySpecs}]
    $config['systems']  = $this->autoPartsSystems($tenant);  // system taxonomy + per-model counts
    $config['featured'] = $this->featuredProducts($tenant, 5);

    $html = file_get_contents(resource_path('storefront/autoparts.html'));
    $html = str_replace('__AUTOPARTS_CONFIG__', json_encode($config, JSON_UNESCAPED_SLASHES), $html);

    return response($html)->header('Content-Type', 'text/html');
}

// ── 4. Config from tenant settings (mirrors resolveTheme + data/tenant.json) ──
protected function resolveAutoPartsConfig($tenant): array
{
    return [
        'id'       => $tenant->slug,
        'vertical' => 'auto_parts',
        'brand'    => [
            'name'     => $tenant->setting('brand_name', $tenant->name),
            'wordmark' => $tenant->setting('brand_wordmark', 'GENUINE PARTS'),
        ],
        'theme'    => [
            'accent'     => $tenant->setting('theme_accent', '#1552CC'),
            'accentDark' => $tenant->setting('theme_accent_dark', '#0E3A94'),
            'secondary'  => $tenant->setting('theme_secondary', '#F5B301'),
        ],
        'market'   => [
            'country'  => $tenant->setting('country', 'Uganda'),
            'code'     => $tenant->setting('country_code', 'UG'),
            'flag'     => $tenant->setting('country_flag', '🇺🇬'),
            'currency' => [
                'code'   => $tenant->currency ?? 'UGX',
                'symbol' => $tenant->setting('currency_symbol', $tenant->currency ?? 'UGX'),
            ],
        ],
        'contact'  => [
            'whatsapp' => $tenant->whatsapp_number,
            'website'  => $tenant->setting('website', null),
        ],
        'hero'     => [
            'headline' => $tenant->setting('hero_title', "Find. Understand.\nBuy. Keep Riding."),
            'sub'      => $tenant->setting('tagline', 'Genuine parts, diagrams, manuals and AI support.'),
        ],
    ];
}
