<?php

namespace App\Support;

/**
 * Technology storefront — the whole theme + content surface, as data.
 *
 * Why this class exists
 * ---------------------
 * The classic storefront themes itself through `StorefrontController::resolveTheme()`
 * (two presets, an accent pair, four strings, two booleans) and then patches literal
 * markup with `str_replace('<div class="lg">SH</div>', ...)`. That does not scale: every
 * new themeable element means another brittle literal, and a tenant cannot restructure
 * its own homepage at all.
 *
 * The technology vertical instead resolves ONE nested config array from tenant settings
 * and hands it to `technology.html` as a single JSON blob (the same three-token pattern
 * `brand.html` already uses: __SEO_TITLE__, __SEO_META__, __TECH_CONFIG__). Layout,
 * colours, type, navigation, section order and every block of copy are data.
 *
 * Design rules this file obeys
 * ----------------------------
 *  - PURE. No DB, no facades, no framework — `build()` takes an array of settings plus a
 *    few resolved primitives, so `qa/technology_storefront_test.php` can exercise it in
 *    the sandbox without booting Laravel.
 *  - TENANT-NEUTRAL. Nothing here names a tenant, a domain, a logo, a colour, a phone
 *    number, a category or a brand. Defaults are generic technology-retail copy that any
 *    IT reseller could ship; every one of them is overridable by a tenant setting.
 *  - ADDITIVE. Nothing in this class is reachable unless a tenant is explicitly on the
 *    technology storefront, so no existing tenant's render can change.
 *
 * Settings are read with dot-notation via the caller's `$settings` array, e.g.
 * `tech.hero.title`, `tech.sections`, `theme_accent`. Anything absent falls back to the
 * defaults below.
 */
final class TechTheme
{
    /** Sections the homepage knows how to render, in their default order. */
    public const SECTIONS = [
        'hero',          // headline + search + primary CTAs
        'trust_bar',     // short reassurance strip
        'categories',    // shop by category tiles
        'featured',      // curated / most popular rail
        'software',      // licences & subscriptions rail
        'hardware',      // computers & devices rail
        'networking',    // networking & infrastructure rail
        'brands',        // trusted brand strip
        'solutions',     // business solution cards
        'services',      // service cards
        'new_arrivals',  // newest products rail
        'why_us',        // value propositions
        'quote_cta',     // B2B / bulk / RFQ call to action
        'faq',           // frequently asked questions
        'contact_cta',   // WhatsApp / phone / email
    ];

    /** Colour tokens the storefront exposes as CSS custom properties. */
    public const COLOR_KEYS = [
        'accent', 'accentDark', 'ink', 'muted', 'ground', 'surface', 'line', 'onAccent',
    ];

    /**
     * Build the full storefront config.
     *
     * @param  array  $s     tenant settings (the raw `tenants.settings` array)
     * @param  array  $ctx   resolved primitives the caller owns:
     *                       name, base, currency, waNumber, logo, favicon, initials,
     *                       catalogueUrl, orderUrl, quoteUrl, shopUrl, panelUrl, url
     */
    public static function build(array $s, array $ctx): array
    {
        $get = static function (string $key, $default = null) use ($s) {
            $cur = $s;
            foreach (explode('.', $key) as $seg) {
                if (! is_array($cur) || ! array_key_exists($seg, $cur)) {
                    return $default;
                }
                $cur = $cur[$seg];
            }

            return ($cur === '' || $cur === null) ? $default : $cur;
        };

        $name = (string) ($ctx['name'] ?? '');

        // ---- colours ---------------------------------------------------------------
        // A cool technical palette by default; every token is individually overridable
        // so a tenant can go warm, mono, or match a brand guide without code.
        $colors = [
            'accent'     => (string) $get('theme_accent',      '#1F6FEB'),
            'accentDark' => (string) $get('theme_accent_dark', '#1652B8'),
            'ink'        => (string) $get('tech.colors.ink',    '#0F1720'),
            'muted'      => (string) $get('tech.colors.muted',  '#5A6B7C'),
            'ground'     => (string) $get('tech.colors.ground', '#FFFFFF'),
            'surface'    => (string) $get('tech.colors.surface', '#F4F7FA'),
            'line'       => (string) $get('tech.colors.line',   '#DFE6ED'),
            'onAccent'   => (string) $get('tech.colors.on_accent', '#FFFFFF'),
        ];

        // ---- typography ------------------------------------------------------------
        $fonts = [
            'display' => (string) $get('tech.fonts.display', ''),
            'body'    => (string) $get('tech.fonts.body', ''),
            'stack'   => (string) $get('tech.fonts.stack',
                '-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif'),
        ];

        // ---- layout knobs ----------------------------------------------------------
        $layout = [
            'headerStyle'  => self::oneOf($get('tech.header_style'), ['standard', 'compact', 'bold'], 'standard'),
            'cardStyle'    => self::oneOf($get('tech.card_style'), ['bordered', 'elevated', 'flat'], 'bordered'),
            'radius'       => max(0, min(28, (int) $get('tech.radius', 10))),
            'density'      => self::oneOf($get('tech.density'), ['comfortable', 'compact'], 'comfortable'),
            'showMegaMenu' => (bool) $get('tech.mega_menu', true),
            // Product-card corner label: 'kind' = Hardware/Licence (default, unchanged for
            // existing tenants), 'category' = the product's own category, 'off' = no label.
            'cardTag'      => self::oneOf($get('tech.card_tag'), ['kind', 'category', 'off'], 'kind'),
        ];

        // ---- section order ---------------------------------------------------------
        // A section entry is EITHER a built-in name (string) OR an inline block
        // (object) — {type: rail|banner|newsletter|gallery, …}. That single ordered
        // list is what lets a tenant reproduce an arbitrary landing page: hero,
        // banner, rail, banner, rail, … in whatever order their old site used.
        $sections = self::sectionList($get('tech.sections', null));

        // ---- hero ------------------------------------------------------------------
        $hero = [
            'eyebrow'  => (string) $get('tech.hero.eyebrow', ''),
            'title'    => (string) $get('tech.hero.title', $name !== '' ? $name : 'Technology, licensed and delivered'),
            'text'     => (string) $get('tech.hero.text', ''),
            'image'    => (string) $get('tech.hero.image', ''),
            'ctaLabel' => (string) $get('tech.hero.cta_label', 'Browse the catalogue'),
            'ctaHref'  => (string) $get('tech.hero.cta_href', ''),
            'altLabel' => (string) $get('tech.hero.alt_label', 'Request a quote'),
            'stats'    => self::rows($get('tech.hero.stats', []), ['k', 'l']),
            'slides'   => self::rows($get('tech.hero.slides', []), ['title', 'text', 'image', 'href', 'cta']),
        ];

        // ---- repeatable content blocks --------------------------------------------
        $trust     = self::rows($get('tech.trust', self::defaultTrust()), ['icon', 'title', 'text']);
        $why       = self::rows($get('tech.why_us', self::defaultWhy()), ['icon', 'title', 'text']);
        $solutions = self::rows($get('tech.solutions', []), ['title', 'text', 'icon', 'href', 'cta']);
        $services  = self::rows($get('tech.services',  []), ['title', 'text', 'icon', 'href', 'cta']);
        $faq       = self::rows($get('tech.faq', []), ['q', 'a']);
        $nav       = self::navTree($get('tech.nav', []));
        $footer    = self::rows($get('tech.footer_columns', []), ['title', 'links']);
        $social    = self::rows($get('tech.social', []), ['label', 'href', 'icon']);
        $pages     = self::pages($get('tech.pages', []));

        // Category / brand rails are DATA-DRIVEN by default: an empty list means
        // "derive from the live catalogue", so a tenant that configures nothing still
        // gets a full homepage the moment products are imported.
        $catFeatured   = self::stringList($get('tech.featured_categories', []));
        $brandFeatured = self::stringList($get('tech.featured_brands', []));

        // Rails map a homepage section to a catalogue filter. Tenant-overridable so the
        // "software / hardware / networking" split can be renamed or re-pointed.
        $rails = [
            'software'   => self::rail($get('tech.rails.software', []),   'Software & Security', 'Licences, subscriptions and certificates'),
            'hardware'   => self::rail($get('tech.rails.hardware', []),   'Computers & Hardware', 'Workstations, laptops and components'),
            'networking' => self::rail($get('tech.rails.networking', []), 'Networking & Infrastructure', 'Switches, firewalls, access points and voice'),
        ];

        // ---- commerce behaviour ----------------------------------------------------
        $commerce = [
            'showStock'     => (bool) $get('storefront_show_stock', false),
            'allowCart'     => (bool) $get('tech.allow_cart', true),
            'allowBuyNow'   => (bool) $get('tech.allow_buy_now', true),
            'allowQuote'    => (bool) $get('tech.allow_quote', true),
            'allowWishlist' => (bool) $get('tech.allow_wishlist', true),
            'quoteAbove'    => (float) $get('tech.quote_above', 0),   // 0 = quote button always available
            'waMessage'     => (string) $get('tech.wa_message', 'Hello, I would like to ask about'),
        ];

        return [
            'name'        => $name,
            'initials'    => (string) ($ctx['initials'] ?? ''),
            'base'        => (string) ($ctx['base'] ?? ''),
            'logo'        => (string) ($ctx['logo'] ?? ''),
            'favicon'     => (string) ($ctx['favicon'] ?? ''),
            'currency'    => (string) ($ctx['currency'] ?? ''),
            'waNumber'    => (string) ($ctx['waNumber'] ?? ''),
            'catalogueUrl' => (string) ($ctx['catalogueUrl'] ?? ''),
            'orderUrl'    => (string) ($ctx['orderUrl'] ?? ''),
            'quoteUrl'    => (string) ($ctx['quoteUrl'] ?? ''),
            'shopUrl'     => (string) ($ctx['shopUrl'] ?? ''),
            'url'         => (string) ($ctx['url'] ?? ''),
            'colors'      => $colors,
            'fonts'       => $fonts,
            'layout'      => $layout,
            'sections'    => $sections,
            'hero'        => $hero,
            'trust'       => $trust,
            'whyUs'       => $why,
            'solutions'   => $solutions,
            'services'    => $services,
            'faq'         => $faq,
            'nav'         => $nav,
            'footer'      => $footer,
            'social'      => $social,
            'pages'       => $pages,
            'rails'       => $rails,
            'featuredCategories' => $catFeatured,
            'featuredBrands'     => $brandFeatured,
            'commerce'    => $commerce,
            'contact'     => [
                'phones'  => self::stringList($get('tech.contact.phones', [])),
                'emails'  => self::stringList($get('tech.contact.emails', [])),
                'address' => (string) $get('tech.contact.address', (string) $get('address', '')),
                'hours'   => (string) $get('tech.contact.hours', ''),
            ],
            'copy'        => [
                'searchHint'   => (string) $get('tech.copy.search_hint', 'Search products, brands or part numbers'),
                'quoteCtaTitle' => (string) $get('tech.copy.quote_cta_title', 'Buying for a business?'),
                'quoteCtaText' => (string) $get('tech.copy.quote_cta_text',
                    'Send us your requirement and we will come back with a written quotation.'),
                'footerNote'   => (string) $get('tech.copy.footer_note', ''),
            ],
        ];
    }

    /** SEO title + description for the technology storefront (never a hardcoded vertical string). */
    public static function seo(array $s, string $name): array
    {
        $get = static function (string $k, $d = null) use ($s) {
            $cur = $s;
            foreach (explode('.', $k) as $seg) {
                if (! is_array($cur) || ! array_key_exists($seg, $cur)) return $d;
                $cur = $cur[$seg];
            }
            return ($cur === '' || $cur === null) ? $d : $cur;
        };

        $title = (string) $get('meta_title', '');
        if ($title === '') {
            $tag = (string) $get('tagline', '');
            $title = $tag !== '' ? ($name . ' — ' . $tag) : $name;
        }

        $desc = (string) $get('meta_description', '');
        if ($desc === '') {
            $desc = trim($name . ' — software licences, cybersecurity, networking and IT hardware. '
                . 'Business pricing, quotations on request.');
        }

        return ['title' => $title, 'description' => $desc];
    }

    // ── helpers ────────────────────────────────────────────────────────────────────

    /** Return $v when it is one of $allowed, else $default. Never touches an undefined key. */
    private static function oneOf($v, array $allowed, string $default): string
    {
        $v = is_string($v) ? trim($v) : '';

        return in_array($v, $allowed, true) ? $v : $default;
    }

    /** Block types that may appear inline in `tech.sections` as objects. */
    public const BLOCK_TYPES = ['rail', 'banner', 'newsletter', 'gallery', 'html_free'];

    /**
     * Normalise the ordered section list. Strings must name a built-in section;
     * objects must carry a known `type`. Anything else is dropped rather than
     * fataling, so a malformed setting degrades to a shorter page, never a 500.
     */
    private static function sectionList($raw): array
    {
        if (! is_array($raw) || $raw === []) {
            return self::SECTIONS;
        }

        $out = [];
        foreach ($raw as $entry) {
            if (is_string($entry)) {
                $name = trim($entry);
                if (in_array($name, self::SECTIONS, true)) {
                    $out[] = $name;
                }
                continue;
            }
            if (is_array($entry)) {
                $block = self::block($entry);
                if ($block !== null) {
                    $out[] = $block;
                }
            }
        }

        return $out === [] ? self::SECTIONS : $out;
    }

    /** Normalise one inline block. Returns null for an unknown/empty block. */
    private static function block(array $b): ?array
    {
        $type = strtolower(trim((string) ($b['type'] ?? '')));
        if (! in_array($type, self::BLOCK_TYPES, true)) {
            return null;
        }

        if ($type === 'rail') {
            $r = self::rail($b, (string) ($b['title'] ?? ''), (string) ($b['text'] ?? ''));
            if ($r['title'] === '' && $r['categories'] === [] && $r['keywords'] === []) {
                return null;
            }
            $r['type'] = 'rail';
            $r['alt']  = (bool) ($b['alt'] ?? false);
            $r['sort'] = self::oneOf($b['sort'] ?? null, ['new', 'popular', 'price_asc', 'price_desc'], '');
            $r['brands'] = self::stringList($b['brands'] ?? []);
            return $r;
        }

        if ($type === 'banner') {
            $out = [
                'type'    => 'banner',
                'eyebrow' => (string) ($b['eyebrow'] ?? ''),
                'title'   => (string) ($b['title'] ?? ''),
                'text'    => (string) ($b['text'] ?? ''),
                'image'   => (string) ($b['image'] ?? ''),
                'cta'     => (string) ($b['cta'] ?? ''),
                'href'    => (string) ($b['href'] ?? ''),
                'align'   => self::oneOf($b['align'] ?? null, ['left', 'right', 'center'], 'left'),
                'tone'    => self::oneOf($b['tone'] ?? null, ['light', 'dark', 'accent'], 'light'),
            ];
            return ($out['title'] === '' && $out['image'] === '') ? null : $out;
        }

        if ($type === 'newsletter') {
            return [
                'type'  => 'newsletter',
                'title' => (string) ($b['title'] ?? ''),
                'text'  => (string) ($b['text'] ?? ''),
                'cta'   => (string) ($b['cta'] ?? 'Subscribe'),
            ];
        }

        if ($type === 'gallery') {
            $raw = $b['images'] ?? [];
            if (! is_array($raw)) {          // a bare string must NOT become a 1-image gallery
                return null;
            }
            $imgs = [];
            foreach ($raw as $i) {
                if (is_string($i) && trim($i) !== '') {
                    $imgs[] = trim($i);
                } elseif (is_array($i) && trim((string) ($i['src'] ?? '')) !== '') {
                    $imgs[] = ['src' => (string) $i['src'], 'href' => (string) ($i['href'] ?? ''),
                               'caption' => (string) ($i['caption'] ?? '')];
                }
            }
            if ($imgs === []) {
                return null;
            }
            return ['type' => 'gallery', 'title' => (string) ($b['title'] ?? ''),
                    'text' => (string) ($b['text'] ?? ''), 'images' => $imgs];
        }

        return null;
    }


    /** Normalise a list of associative rows, keeping only the named keys, as strings. */
    private static function rows($raw, array $keys): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $clean = [];
            foreach ($keys as $k) {
                $v = $row[$k] ?? '';
                // one nested list is allowed (footer column links)
                $clean[$k] = is_array($v) ? self::linkList($v) : (string) $v;
            }
            // drop entirely empty rows
            $has = false;
            foreach ($clean as $v) {
                if ((is_string($v) && $v !== '') || (is_array($v) && $v !== [])) { $has = true; break; }
            }
            if ($has) {
                $out[] = $clean;
            }
        }

        return $out;
    }

    private static function linkList($raw): array
    {
        $out = [];
        foreach ((array) $raw as $l) {
            if (is_array($l)) {
                $label = (string) ($l['label'] ?? '');
                $href  = (string) ($l['href'] ?? '');
                if ($label !== '') {
                    $out[] = ['label' => $label, 'href' => $href];
                }
            } elseif (is_string($l) && $l !== '') {
                $out[] = ['label' => $l, 'href' => ''];
            }
        }

        return $out;
    }

    private static function stringList($raw): array
    {
        if (is_string($raw)) {
            $raw = array_map('trim', explode(',', $raw));
        }
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($x) => is_string($x) ? trim($x) : '', $raw
        ), static fn ($x) => $x !== ''));
    }

    /** Two-level navigation: [{label, href, children:[{label, href}]}] */
    private static function navTree($raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }
            $label = trim((string) ($item['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $out[] = [
                'label'    => $label,
                'href'     => (string) ($item['href'] ?? ''),
                'children' => self::linkList($item['children'] ?? []),
            ];
        }

        return $out;
    }

    /** Static content pages (policies, about) rendered inside the storefront. */
    private static function pages($raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $slug => $page) {
            if (! is_array($page)) {
                continue;
            }
            $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) $slug));
            if ($slug === '') {
                continue;
            }
            $blocks = [];
            foreach ((array) ($page['blocks'] ?? []) as $b) {
                if (! is_array($b)) {
                    continue;
                }
                $t = (string) ($b['text'] ?? '');
                if ($t === '') {
                    continue;
                }
                $blocks[] = ['type' => (($b['type'] ?? 'p') === 'h' ? 'h' : 'p'), 'text' => $t];
            }
            if ($blocks !== []) {
                $out[$slug] = ['title' => (string) ($page['title'] ?? $slug), 'blocks' => $blocks];
            }
        }

        return $out;
    }

    /** One homepage product rail: which categories feed it, and how it is labelled. */
    private static function rail($raw, string $defTitle, string $defText): array
    {
        $raw = is_array($raw) ? $raw : [];

        return [
            'title'      => (string) ($raw['title'] ?? $defTitle),
            'text'       => (string) ($raw['text'] ?? $defText),
            'categories' => self::stringList($raw['categories'] ?? []),
            'keywords'   => self::stringList($raw['keywords'] ?? []),
            'limit'      => max(1, min(24, (int) ($raw['limit'] ?? 8))),
        ];
    }

    private static function defaultTrust(): array
    {
        return [
            ['icon' => 'shield', 'title' => 'Genuine licensing',  'text' => 'Supplied through authorised channels'],
            ['icon' => 'bolt',   'title' => 'Fast delivery',       'text' => 'Digital keys issued after payment'],
            ['icon' => 'quote',  'title' => 'Business quotations', 'text' => 'Written quotes for teams and tenders'],
            ['icon' => 'chat',   'title' => 'Local support',       'text' => 'Talk to a specialist before you buy'],
        ];
    }

    private static function defaultWhy(): array
    {
        return [
            ['icon' => 'shield', 'title' => 'Licensing done properly',
             'text' => 'Every licence is supplied with the documentation your auditors will ask for.'],
            ['icon' => 'grid',   'title' => 'Software and hardware together',
             'text' => 'One supplier for endpoint security, productivity, networking and the devices they run on.'],
            ['icon' => 'quote',  'title' => 'Built for procurement',
             'text' => 'Formal quotations, purchase orders and staged delivery for business buyers.'],
            ['icon' => 'chat',   'title' => 'Advice, not just a checkout',
             'text' => 'Tell us the requirement and we will size it before you commit.'],
        ];
    }
}
