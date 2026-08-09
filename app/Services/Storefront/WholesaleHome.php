<?php declare(strict_types=1);

namespace App\Services\Storefront;

/**
 * v4.67 (ADR-017, M1) — the wholesale storefront home, as data.
 *
 * PURE. A settings array in, a config array (or null) out. No models, no database, no framework.
 *
 * ── why this exists ────────────────────────────────────────────────────────────────────────────
 *
 * One shop.html serves every tenant. Family Shoppers wants a wholesale-positioned homepage; the
 * other twelve shops must not change by a pixel. So the new home is DATA the server injects —
 * `cfg.home` — and the SPA renders it only when that data says so. This class is the single place
 * that decides what goes into `cfg.home`, which makes the two guarantees testable:
 *
 *   1. `build()` returns NULL unless the tenant has explicitly set `storefront_home = wholesale`.
 *      Null in, unchanged homepage out. The default is silence, and a mutant that flips this
 *      default turns twelve shops red overnight — which is why the suite kills it first.
 *   2. Every string on the new home comes from a tenant setting or a neutral fallback. Nothing in
 *      the shared file is Family Shoppers copy, so nothing can leak to another shop.
 *
 * The fallbacks are deliberately generic ("in {city}", not "in Juba"): they only ever render for a
 * tenant who opted in, but even then they must be true for ANY wholesale shop that flips the
 * setting next year. Shop-specific claims — "Min. 5 Cartons" — are settings, never defaults.
 */
final class WholesaleHome
{
    public const MODE = 'wholesale';

    /** Is the wholesale home switched on for this settings map? */
    public static function enabled(array $settings): bool
    {
        return strtolower(trim((string) ($settings['storefront_home'] ?? ''))) === self::MODE;
    }

    /**
     * The home config the SPA renders, or null for the unchanged classic home.
     *
     * Image values are returned as stored (path or URL); the controller maps them through its
     * imageUrl() helper, which is where storage knowledge lives.
     *
     * @return array{enabled:bool,hero:array{title:string,title2:string,sub:string,img:string,imgM:string},benefits:array<int,array{t:string,s:string,s2:string}>,trust:array<int,array{t:string,s:string}>,ctas:array{0:string,1:string}}|null
     */
    public static function build(array $settings, string $city = ''): ?array
    {
        if (! self::enabled($settings)) {
            return null;
        }

        $city = trim($city);
        $str  = fn (string $key, string $fallback = ''): string => trim((string) ($settings[$key] ?? '')) !== ''
            ? trim((string) $settings[$key])
            : $fallback;

        return [
            'enabled' => true,
            'hero'    => [
                'title'  => $str('home_hero_title', 'Your Trusted Wholesale Partner'),
                'title2' => $str('home_hero_title2', $city !== '' ? 'in ' . $city : ''),
                'title3' => $str('home_hero_title3'),
                'sub'    => $str('home_hero_sub', $str('tagline', 'Everything your business buys — at wholesale prices.')),
                'img'    => $str('home_hero_image'),
                'imgM'   => $str('home_hero_image_mobile'),
            ],
            'benefits' => self::items($settings['home_benefits'] ?? null, self::defaultBenefits($city), 4, true),
            'trust'    => self::items($settings['home_trust'] ?? null, self::defaultTrust(), 6, false),
            'ctas'     => [
                $str('home_cta_primary', 'SHOP WHOLESALE PACKS'),
                $str('home_cta_secondary', 'SHOP BY BUSINESS'),
            ],
            // v4.70 (M3) — the page chrome: utility topbar, delivering line, About/Contact pages.
            // Topbar chips default to three claims true of any wholesale shop; everything
            // shop-specific (where you deliver, who you are, how to reach you) is settings —
            // contact details reuse the SAME business-profile settings every invoice already
            // prints, so there is one address in the system, not two.
            'chrome'   => [
                'topbar'     => self::items($settings['home_topbar'] ?? null, [
                    ['t' => 'Wholesale prices'], ['t' => 'Bulk orders'], ['t' => 'Reliable delivery'],
                ], 4, false),
                'delivering' => $str('home_delivering'),
                'about'      => $str('home_about'),
                'phone'      => $str('public_phone'),
                'email'      => $str('public_email'),
                'address'    => trim(implode(', ', array_filter([$str('address'), $city]))),
            ],
            // v4.68 (M2) — merchandising strips. Every one of these is null until the tenant
            // configures it, and null renders NOTHING. A delivery promise, a volume-discount table
            // and a "500+ businesses served" figure are business claims; a default that invents one
            // would put words in a shop's mouth — the exact thing the owner's own brief forbids
            // ("make the UI component configurable rather than pretending the discount is active").
            'promo'    => [
                'delivery'   => self::items($settings['home_delivery_banner'] ?? null, [], 1, false)[0] ?? null,
                'tiers'      => self::items($settings['bulk_tiers'] ?? null, [], 4, false) ?: null,
                'tiersTitle' => $str('bulk_tiers_title', 'Buy More, Save More'),
                'stats'      => self::items($settings['home_stats'] ?? null, [], 4, false) ?: null,
            ],
        ];
    }

    /**
     * A settings-supplied list of {t, s[, s2]} items, or the fallback.
     *
     * The setting may arrive as a JSON string (how the settings screen stores it) or as an already
     * decoded array. Anything malformed — bad JSON, a bare string, items with no title — falls back
     * whole rather than rendering half a section: a storefront must never fatal, and a hero with
     * one surviving benefit chip reads as broken in a way an unchanged default does not.
     *
     * @param  array<int,array{t:string,s:string,s2:string}>|array<int,array{t:string,s:string}>  $fallback
     * @return array<int,array<string,string>>
     */
    private static function items($raw, array $fallback, int $max, bool $withThird): array
    {
        $list = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($list)) {
            return $fallback;
        }

        $out = [];
        foreach ($list as $it) {
            if (! is_array($it)) {
                continue;
            }
            $t = trim((string) ($it['t'] ?? ''));
            if ($t === '') {
                continue;                        // a chip with no title is nothing to render
            }
            $row = ['t' => $t, 's' => trim((string) ($it['s'] ?? ''))];
            if ($withThird) {
                $row['s2'] = trim((string) ($it['s2'] ?? ''));
            }
            $out[] = $row;
            if (count($out) >= $max) {
                break;
            }
        }

        return $out !== [] ? $out : $fallback;
    }

    /** @return array<int,array{t:string,s:string,s2:string}> */
    private static function defaultBenefits(string $city): array
    {
        return [
            ['t' => 'FREE DELIVERY',    's' => $city !== '' ? 'in ' . $city : 'to your business', 's2' => ''],
            ['t' => 'WHOLESALE PRICES', 's' => 'Best deals for your business',                    's2' => ''],
            ['t' => 'TRUSTED QUALITY',  's' => 'Original products from trusted brands',           's2' => ''],
        ];
    }

    /** @return array<int,array{t:string,s:string}> */
    private static function defaultTrust(): array
    {
        return [
            ['t' => '100% Original Products', 's' => 'Sourced from trusted brands'],
            ['t' => 'Secure Payments',        's' => 'Safe & secure transactions'],
            ['t' => 'Dedicated Support',      's' => "We're here to help"],
            ['t' => 'Bulk Discounts',         's' => 'More you buy, more you save'],
        ];
    }
}
