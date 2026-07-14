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
    ];

    /** Numeric settings: [key => ['int'|'float', min]]. */
    private const NUM_KEYS = [
        'rate_ugx_per_usd' => ['float', 0.0001],
        'rate_ssp_per_usd' => ['float', 0.0001],
        'invoice_terms_days' => ['int', 0],
        'vat_rate' => ['float', 0.0],
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

        if (! $changed) {
            return ['ok' => false, 'error' => 'nothing_to_change', 'message' => 'No changes were provided.'];
        }

        // putSetting already saves; save once more in case only the name column changed.
        $tenant->save();

        return ['ok' => true, 'message' => 'Settings saved: ' . implode(', ', array_slice($changed, 0, 6)) . (count($changed) > 6 ? '…' : '') . '.'];
    }
}
