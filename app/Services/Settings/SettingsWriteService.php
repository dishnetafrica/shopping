<?php

namespace App\Services\Settings;

use App\Models\Tenant;
use App\Services\Money\ReportingCurrency;

/**
 * The one place v3 writes the Currency & Reporting tenant settings. Keeps validation in a single
 * spot and persists through the frozen Tenant::putSetting() (which saves the settings JSON). Adds
 * nothing to the trading engine — only the tenant's own presentation settings.
 */
final class SettingsWriteService
{
    /** Base currency must be one Fx can rate, or consolidation would be meaningless. */
    public const BASE_CHOICES = ReportingCurrency::RATEABLE;   // USD / UGX / SSP

    public const MODE_CHOICES = ['base', 'original'];

    /**
     * Apply any subset of {base_currency, report_currency_mode}. Ignores blank/unknown values so a
     * form that only changes one field leaves the other untouched. Returns the applied settings.
     *
     * @return array{ok:bool, error?:string, message:string, base?:string, mode?:string}
     */
    public function apply(Tenant $tenant, array $input): array
    {
        $changed = [];

        $base = strtoupper(trim((string) ($input['base_currency'] ?? '')));
        if ($base !== '') {
            if (! in_array($base, self::BASE_CHOICES, true)) {
                return ['ok' => false, 'error' => 'bad_currency', 'message' => 'Base currency must be one of USD, UGX or SSP.'];
            }
            $tenant->putSetting('currency', $base);
            $changed[] = 'base currency → ' . $base;
        }

        $mode = strtolower(trim((string) ($input['report_currency_mode'] ?? $input['mode'] ?? '')));
        if ($mode !== '') {
            if (! in_array($mode, self::MODE_CHOICES, true)) {
                return ['ok' => false, 'error' => 'bad_mode', 'message' => 'Report mode must be "base" or "original".'];
            }
            $tenant->putSetting('report_currency_mode', $mode);
            $changed[] = 'report mode → ' . ($mode === 'base' ? 'Consolidated' : 'Original currency');
        }

        if (! $changed) {
            return ['ok' => false, 'error' => 'nothing_to_change', 'message' => 'No changes were provided.'];
        }

        return [
            'ok' => true,
            'message' => 'Settings saved: ' . implode(', ', $changed) . '.',
            'base' => strtoupper((string) ($tenant->setting('currency') ?: 'UGX')),
            'mode' => strtolower((string) ($tenant->setting('report_currency_mode') ?: 'base')),
        ];
    }
}
