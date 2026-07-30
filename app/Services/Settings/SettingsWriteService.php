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
    /** Base currency must be one Fx can rate, or consolidation would be meaningless. */
    public const BASE_CHOICES = ReportingCurrency::RATEABLE;   // USD / UGX / SSP

    public const MODE_CHOICES = ['base', 'original'];

    /** Plain text settings written verbatim (trimmed). */
    private const TEXT_KEYS = [
        'address', 'city', 'country', 'public_email', 'public_phone', 'tin', 'logo', 'tagline',
        'invoice_title', 'invoice_footer', 'bank_details', 'trading_as', 'owner_name',
        'cashbook_digest_to', // daily cashbook digest recipient (blank = off)
        // WhatsApp bot configuration (read by the bot/AI engine).
        'bot_greeting', 'bot_fallback_text', 'bot_unreadable_text', 'bot_website_link',
        'ai_persona', 'business_hours', 'delivery_areas', 'faq', 'brand_knowledge', 'languages',
        // Delivery notes shown to customers / staff.
        'delivery_note', 'moq_note',
        // Storefront trust & delivery promises (shown on product pages / homepage).
        'sf_genuine', 'sf_delivery_eta', 'sf_pay_methods', 'sf_warranty', 'sf_returns',
        // VAT is fully tenant-configurable (never hardcoded): label + tax registration no.
        'vat_label', 'vat_number',
        // Catalogue PDF artwork + highlights (image fields hold URLs; /papi/branding-upload fills them).
        'public_website', 'free_delivery_towns', 'delivery_from_city',
        'catalog_cover_image', 'catalog_promo_image', 'catalog_qr_image',
        'catalog_best_sellers', 'catalog_hot_deals', 'catalog_audience',
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
    ];

    /** On/off toggle settings (stored as booleans). */
    private const BOOL_KEYS = [
        'stock_ledger',  // write the real stock ledger (record receipts & sales)
        'stock_v2',      // read on-hand from the ledger on site/bot (needs the ledger seeded first)
        'digest_enabled',   // send the daily digest
        'pay_on_delivery',  // offer pay-on-delivery
        'pay_unify',        // unify payment handling
        'vat_enabled',          // charge VAT / sales tax (never hardcoded)
        'prices_include_vat',   // catalogue prices are VAT-inclusive
        'task_reminders',       // twice-daily WhatsApp task reminders to assignees
        'combo_recommendations', // bot "Goes well with" / "Often bought together" cross-sell suggestions
        'send_product_images',   // bot sends product photos with replies
        // Storefront: per-model show/hide toggles (default on). Read by StorefrontController.
        'show_model_boxer100', 'show_model_boxer125hd', 'show_model_boxer150',
        'show_model_boxer125x', 'show_model_ct125hd', 'show_model_boxer150x',
        'sf_hide_oos',  // storefront: hide out-of-stock parts (sell only what you have)
    ];

    /** Enum settings: [key => allowed values]. Document PDF page setup (read by the PDF renderers). */
    private const ENUM_KEYS = [
        'invoice_page_size'   => ['A4', 'A5', 'Letter'],
        'invoice_orientation' => ['portrait', 'landscape'],
        'receipt_page_size'   => ['A4', 'A5'],
        'receipt_orientation' => ['portrait', 'landscape'],
        // native = base currency (USD) IS the operating currency for selling; converted = local (UGX).
        'currency_basis'      => ['native', 'converted'],
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
        foreach (self::TEXT_KEYS as $k) {
            if (array_key_exists($k, $input)) {
                $tenant->putSetting($k, trim((string) $input[$k]));
                $changed[] = str_replace('_', ' ', $k);
            }
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
