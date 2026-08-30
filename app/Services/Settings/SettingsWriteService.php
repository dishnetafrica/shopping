<?php

namespace App\Services\Settings;

use App\Models\Tenant;
use App\Services\Money\ReportingCurrency;

/**
 * The one place v3 writes tenant settings — business profile, currency & reporting, exchange rates and
 * invoice/tax preferences. Persists through the frozen Tenant (name column + putSetting for the settings
 * JSON) and validates in a single spot. Adds nothing to the trading engine; it only edits the tenant's
 * own configuration, which the PDF renderers, Fx service and bot already read.
 */
final class SettingsWriteService
{
    /**
     * The keys the Settings screen renders as an image upload — derived from the schema, exactly as
     * BrandingUploadController derives what it will accept, so the two can never drift. A blank
     * submitted for one of these is discarded rather than written.
     */
    private static function imageKeys(): array
    {
        try {
            $out = [];
            foreach (\App\Support\SettingsSchema::fields() as $key => $f) {
                if (($f['type'] ?? '') === 'image') {
                    $out[] = $key;
                }
            }
            if ($out) {
                return $out;
            }
        } catch (\Throwable $e) {
            // schema unavailable → the explicit list below still protects the ones that matter most
        }

        return ['logo', 'stamp_image', 'catalog_cover_image', 'catalog_promo_image',
            'catalog_qr_image', 'home_hero_image', 'home_hero_image_mobile'];
    }

    /** Base currency must be one Fx can rate, or consolidation would be meaningless. */
    public const BASE_CHOICES = ReportingCurrency::RATEABLE;   // USD / UGX / SSP

    public const MODE_CHOICES = ['base', 'original'];

    /** Plain text settings written verbatim (trimmed). */
    private const TEXT_KEYS = [
        'tender_watch_url', 'tender_profile', 'tender_company_docs',   // v4.19 TenderBot
        'tender_pricing_team', 'tender_sourcing_market',
                                                                       // v4.23 — who sources prices, and where
        'campaign_exclude',                                            // v4.32 — campaign do-not-message list
        'books.loan_holders',                                          // v4.26 — director-loan lender name(s)
        'address', 'city', 'country', 'public_email', 'public_phone', 'tin', 'logo', 'tagline',
        'invoice_title', 'invoice_footer', 'bank_details', 'trading_as', 'owner_name',
        'cashbook_digest_to', // daily cashbook digest recipient (blank = off)
        // WhatsApp bot configuration (read by the bot/AI engine).
        'bot_greeting', 'bot_fallback_text', 'bot_unreadable_text', 'bot_website_link',
        'ai_persona', 'business_hours', 'delivery_areas', 'faq', 'brand_knowledge', 'languages',
        // Delivery notes shown to customers / staff.
        'delivery_note', 'moq_note',
        // v4.43 — the payment wording printed on every order confirmation ("cash on delivery",
        // "30 days from invoice"). Blank = derived from what the order has already been paid.
        'order_confirm_terms',
        // v4.45 — the letters before a sequential quotation number (QUO → QUO-2026-0001).
        'quote_prefix',
        // v4.46 — the company stamp printed on customer documents (an image field holds its URL).
        'stamp_image',
        // Storefront trust & delivery promises (shown on product pages / homepage).
        'sf_genuine', 'sf_delivery_eta', 'sf_pay_methods', 'sf_warranty', 'sf_returns',
        // VAT is fully tenant-configurable (never hardcoded): label + tax registration no.
        'vat_label', 'vat_number',
        // Catalogue PDF artwork + highlights (image fields hold URLs; /papi/branding-upload fills them).
        'public_website', 'free_delivery_towns', 'known_delivery_towns', 'delivery_from_city',
        'catalog_cover_image', 'catalog_promo_image', 'catalog_qr_image',
        'catalog_best_sellers', 'catalog_hot_deals', 'catalog_audience',
        'catalog_badge', 'catalog_cover_tags',   // v4.7.7 — trust badge + cover value-prop line
        // POS till controls (v3.9).
        'pos_manager_pin',
        // v4.69 — the wholesale storefront home (ADR-017), editable from Settings → Storefront.
        // The two image keys hold URLs written by the existing upload widget; the JSON keys are
        // parsed by WholesaleHome, which falls back whole on anything malformed — a typo in this
        // screen can never break the shop, only leave a section on its defaults.
        'home_hero_image', 'home_hero_image_mobile',
        'home_hero_title', 'home_hero_title2', 'home_hero_sub',
        'home_benefits', 'home_trust', 'home_delivery_banner',
        'bulk_tiers', 'bulk_tiers_title', 'home_stats',
        'home_cta_primary', 'home_cta_secondary',
        'home_hero_title3', 'home_topbar', 'home_delivering', 'home_about',   // v4.70 M3 chrome
        'home_popular_searches',                                             // v4.76 popular chips
        'theme_accent', 'theme_accent_dark',
        // Storefront model photo override (v4.7.6): any image URL; blank = official TVS photo.
        'model_image_tvshlx125',
        'model_image_hero', 'model_image_boxer100', 'model_image_boxer125hd', 'model_image_boxer150',   // v4.15
        'model_image_boxer125x', 'model_image_ct125hd', 'model_image_boxer150x',                        // v4.15
    ];

    /** Numeric settings: [key => ['int'|'float', min]]. */
    private const NUM_KEYS = [
        'rate_ugx_per_usd' => ['float', 0.0001],
        'rate_ssp_per_usd' => ['float', 0.0001],
        'invoice_terms_days' => ['int', 0],
        'vat_rate' => ['float', 0.0],
        // Notifications + delivery numeric settings.
        'digest_hour' => ['int', 0],
        'low_stock_threshold' => ['int', 0],
        'reorder_days' => ['int', 0],
        'min_order' => ['float', 0.0],
        'ai_catalogue_limit' => ['int', 1],
        'pos_discount_limit' => ['float', 0.0],   // % of sale a cashier may discount without the manager PIN
        'stamp_size' => ['int', 0],               // v4.46 — printed stamp size in px (clamped 60–260 at read)
        'bot_coalesce_seconds' => ['int', 0],     // burst window before the bot answers (0 = off, capped 10 at read)
        // Dealer programme 4a (v4.9): tier discount defaults + the margin floor PriceResolver enforces.
        'dealer_pct' => ['float', 0.0],
        'wholesale_pct' => ['float', 0.0],
        'dealer_min_margin_pct' => ['float', 0.0],
        'loose_markup_pct' => ['float', 0.0],   // per-piece (loose) selling markup over the carton unit price
        // v4.22 TenderBot — margin added to a supplier cost to reach the bid price.
        'tender_margin_pct' => ['float', 0.0],
        'research_daily_cap' => ['int', 0],                  // v4.29 — per-shop ceiling on AI research calls
        'marketing_daily_limit' => ['int', 0],               // WhatsApp marketing daily cap (never auto-raised)
        'marketing_min_interval_days' => ['int', 0],         // per-customer cross-campaign frequency floor
        // v4.26 — director loan: rate is a FRACTION per month (0.03 = 3%); stock is a USD value.
        'books.loan_rate' => ['float', 0.0],
        'books.stock_usd' => ['float', 0.0],
    ];

    /** On/off toggle settings (stored as booleans). */
    private const BOOL_KEYS = [
        'stock_ledger',  // write the real stock ledger (record receipts & sales)
        'stock_v2',      // read on-hand from the ledger on site/bot (needs the ledger seeded first)
        'loose_enabled', // allow ordering carton items by the single piece (storefront + bot)
        'digest_enabled',   // send the daily digest
        'pay_on_delivery',  // offer pay-on-delivery
        'pay_unify',        // unify payment handling
        'vat_enabled',          // charge VAT / sales tax (never hardcoded)
        'prices_include_vat',   // catalogue prices are VAT-inclusive
        'task_reminders',       // twice-daily WhatsApp task reminders to assignees
        // v4.25 — WhatsApp the CUSTOMER their invoice, and a receipt with the running balance.
        'notify_customer_invoice', 'notify_customer_receipt',
        // v4.43 — the itemised order confirmation, and whether it carries a tap-to-confirm link.
        'notify_order_confirmation', 'order_confirm_link',
        // v4.46 — which customer documents carry the company stamp (all no-ops until one is uploaded).
        'stamp_on_invoice', 'stamp_on_receipt', 'stamp_on_quotation',
        'stamp_on_delivery_note', 'stamp_on_credit_note',
        'combo_recommendations', // bot "Goes well with" / "Often bought together" cross-sell suggestions
        'send_product_images',   // bot sends product photos with replies
        'web_chat',              // show the chat bubble on the storefront
        'web_chat_contact',      // ask website visitors for a name/number before chatting
        // Storefront: per-model show/hide toggles (default on). Read by StorefrontController.
        'show_model_boxer100', 'show_model_boxer125hd', 'show_model_boxer150',
        'show_model_boxer125x', 'show_model_ct125hd', 'show_model_boxer150x',
        'show_model_tvshlx125',   // v4.7.5: TVS HLX 125 on the customer storefront
        'sf_hide_oos',  // storefront: hide out-of-stock parts (sell only what you have)
        'sf_diagrams',  // v4.10.5 — show exploded diagrams (default off; parts-first catalogues)
        'tender_enabled', 'tender_notify', 'tender_tasks',   // v4.19/v4.20 TenderBot (bool)
        'tender_ocr',                                        // v4.22 — read scanned tender documents
        'research_enabled', 'research_descriptions',         // v4.29 — AI product research
        'campaign_exclude_staff',                            // v4.32 — never message my own team
        'custom_domain_live',  // owner confirms the custom domain serves CloudBSS → use it in sent links (v4.6.1)
    ];

    /** Enum settings: [key => allowed values]. Document PDF page setup (read by the PDF renderers). */
    private const ENUM_KEYS = [
        'web_chat_mode'       => ['auto', 'off'],   // website chat: bot answers (auto) or collect-only (off)
        'invoice_page_size'   => ['A4', 'A5', 'Letter'],
        'invoice_orientation' => ['portrait', 'landscape'],
        'receipt_page_size'   => ['A4', 'A5'],
        'receipt_orientation' => ['portrait', 'landscape'],
        // v4.45 — legacy = ORD-Q + date + 4 random chars; sequential = QUO-2026-0001 counting up.
        // Numbers already issued are never rewritten, whichever way this is set.
        'quote_number_mode'   => ['legacy', 'sequential'],
        'quote_number_reset'  => ['year', 'plain'],
        // native = base currency (USD) IS the operating currency for selling; converted = local (UGX).
        'currency_basis'      => ['native', 'converted'],
        // MOQ handling in bot quotes/orders (read by OrderCalculator + AiBrain; v4.6 exposes it here —
        // previously only the one-time onboarding wizard could set it).
        'moq_mode'            => ['off', 'roundup'],
        // v4.30 — the shop's own business type. Previously only a master admin could set this
        // through the Filament admin, so a shop had no way to say what it sells and therefore
        // no way to stop being shown another trade's settings. Read by Vertical::of().
        'vertical'            => \App\Support\Vertical::ALL,
        // v4.69 — which homepage the storefront composes. WholesaleHome::enabled() treats anything
        // that is not exactly 'wholesale' as classic, so 'classic' here switches the new home off
        // without deleting any of the copy/image settings alongside it.
        'storefront_home'     => ['classic', 'wholesale'],
    ];

    /**
     * Apply any subset of the settings. Absent keys are left untouched, so a form that edits one section
     * never disturbs another. Returns the applied summary.
     *
     * @return array{ok:bool, error?:string, message:string}
     */
    public function apply(Tenant $tenant, array $input): array
    {
        $changed = [];

        // Business name lives on the tenant row, not the settings JSON.
        if (array_key_exists('business_name', $input)) {
            $name = trim((string) $input['business_name']);
            if ($name !== '') {
                $tenant->name = $name;
                $changed[] = 'business name';
            }
        }

        // Base currency (rateable only).
        $base = strtoupper(trim((string) ($input['base_currency'] ?? '')));
        if ($base !== '') {
            if (! in_array($base, self::BASE_CHOICES, true)) {
                return ['ok' => false, 'error' => 'bad_currency', 'message' => 'Base currency must be one of USD, UGX or SSP.'];
            }
            $tenant->putSetting('currency', $base);
            $changed[] = 'base currency → ' . $base;
        }

        // Report mode.
        $mode = strtolower(trim((string) ($input['report_currency_mode'] ?? $input['mode'] ?? '')));
        if ($mode !== '') {
            if (! in_array($mode, self::MODE_CHOICES, true)) {
                return ['ok' => false, 'error' => 'bad_mode', 'message' => 'Report mode must be "base" or "original".'];
            }
            $tenant->putSetting('report_currency_mode', $mode);
            $changed[] = 'report mode → ' . ($mode === 'base' ? 'Consolidated' : 'Original');
        }

        // Free-text settings.
        //
        // IMAGE fields are the exception, and it is not a small one. They hold a URL the upload
        // widget wrote; the owner never types them. A form that submits them blank therefore erases
        // pictures nobody asked to remove — which is exactly how tenant 17 lost its logo, stamp,
        // hero, hero-mobile and both catalogue images in a single Save, while every file sat safe on
        // disk the whole time. NUM_KEYS and ENUM_KEYS below already skip blanks; image keys now do
        // too. Ordinary text (tagline, invoice_footer, bank_details) stays clearable, because there
        // a blank IS the intent.
        //
        // Consequence to know: removing an image can no longer be done by clearing the field. Upload
        // a replacement, or clear it with putSetting. That is the deliberate trade — a picture you
        // have to replace on purpose beats five pictures that vanish by accident.
        $imageKeys = self::imageKeys();
        foreach (self::TEXT_KEYS as $k) {
            if (! array_key_exists($k, $input)) {
                continue;
            }
            $val = trim((string) $input[$k]);
            if ($val === '' && in_array($k, $imageKeys, true)) {
                continue;
            }
            $tenant->putSetting($k, $val);
            $changed[] = str_replace('_', ' ', $k);
        }

        // Numeric settings (validated, clamped to a sane minimum).
        foreach (self::NUM_KEYS as $k => [$type, $min]) {
            if (array_key_exists($k, $input) && trim((string) $input[$k]) !== '') {
                $raw = (float) preg_replace('/[^0-9.]/', '', (string) $input[$k]);
                if ($raw < $min) {
                    return ['ok' => false, 'error' => 'bad_number', 'message' => str_replace('_', ' ', $k) . ' must be at least ' . $min . '.'];
                }
                $tenant->putSetting($k, $type === 'int' ? (int) round($raw) : $raw);
                $changed[] = str_replace('_', ' ', $k);
            }
        }

        // Boolean toggles (accept on/off/yes/no/1/0/true/false).
        foreach (self::BOOL_KEYS as $k) {
            if (array_key_exists($k, $input)) {
                $v = $input[$k];
                $on = $v === true || $v === 1 || in_array(strtolower(trim((string) $v)), ['1', 'true', 'on', 'yes', 'enabled'], true);
                $tenant->putSetting($k, $on);
                $changed[] = str_replace('_', ' ', $k) . ' → ' . ($on ? 'on' : 'off');
            }
        }

        // Enum settings (validated against their allowed values).
        foreach (self::ENUM_KEYS as $k => $opts) {
            if (array_key_exists($k, $input) && trim((string) $input[$k]) !== '') {
                $v = trim((string) $input[$k]);
                if (! in_array($v, $opts, true)) {
                    return ['ok' => false, 'error' => 'bad_option', 'message' => str_replace('_', ' ', $k) . ' must be one of ' . implode(', ', $opts) . '.'];
                }
                $tenant->putSetting($k, $v);
                $changed[] = str_replace('_', ' ', $k) . ' → ' . $v;
            }
        }

        if (! $changed) {
            return ['ok' => false, 'error' => 'nothing_to_change', 'message' => 'No changes were provided.'];
        }

        // putSetting already saves; save once more in case only the name column changed.
        $tenant->save();

        return ['ok' => true, 'message' => 'Settings saved: ' . implode(', ', array_slice($changed, 0, 6)) . (count($changed) > 6 ? '…' : '') . '.'];
    }
}
