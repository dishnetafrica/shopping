<?php declare(strict_types=1);

namespace App\Services\Advisor;

/**
 * Solar Project Advisor — deterministic system-sizing engine (the first SizingEngine implementation).
 *
 * Turns a discovery brief (the customer's appliances, backup hours, location sun hours, expansion plans)
 * into engineering quantities a proposal can stand on: connected load, daily energy, peak demand, PV
 * array size, battery bank, and inverter rating — each with an applied safety margin and future-expansion
 * allowance. Every number is produced by a pure, documented formula so it can be re-derived, audited and
 * unit-tested. The AI narrates these numbers; it must never compute or override them.
 *
 * Units: watts (W), watt-hours (Wh), amp-hours (Ah), volt-amps (VA), hours (h).
 *
 * Brief shape:
 *   [
 *     'loads' => [
 *        ['name'=>'LED light', 'watts'=>10, 'qty'=>4, 'hours_per_day'=>5, 'critical'=>true, 'surge_factor'=>1.0],
 *        ...
 *     ],
 *     'backup_hours'   => 8.0,     // autonomy required for the CRITICAL loads
 *     'peak_sun_hours' => 5.0,     // location-derived; falls back to config
 *     'expansion_pct'  => 0.20,    // future expansion allowance; falls back to config
 *   ]
 */
class SolarSizingService implements SizingEngine
{
    /** Engineering defaults (East-Africa sensible). All overridable per call via $config. */
    public const DEFAULTS = [
        'peak_sun_hours'     => 5.0,   // average daily productive sun hours
        'system_efficiency'  => 0.75,  // PV → usable (wiring, dust, temperature, MPPT, controller)
        'battery_efficiency' => 0.90,  // round-trip battery efficiency
        'depth_of_discharge' => 0.80,  // usable fraction of battery capacity (LiFePO4 ~0.8, lead-acid ~0.5)
        'system_voltage'     => 24,    // battery bank nominal voltage (12 / 24 / 48)
        'safety_margin'      => 1.25,  // headroom applied to peak demand for the inverter
        'power_factor'       => 0.80,  // for converting inverter real power (W) to apparent power (VA)
        'expansion_pct'      => 0.20,  // future-expansion allowance on PV and battery
        'panel_watt'         => 550,   // reference panel size, for a suggested panel count
    ];

    public function key(): string
    {
        return 'solar';
    }

    // ── Pure step helpers (no state, individually unit-testable) ─────────────────────────────────

    /** A single load's power draw (W) = watts × quantity. */
    public static function loadWatts(array $load): float
    {
        return max(0.0, (float) ($load['watts'] ?? 0)) * max(0, (int) ($load['qty'] ?? 1));
    }

    /** Total connected load (W) — every appliance running at once. */
    public static function totalConnectedW(array $loads): float
    {
        $sum = 0.0;
        foreach ($loads as $l) {
            $sum += self::loadWatts($l);
        }
        return round($sum, 2);
    }

    /** Connected load of the CRITICAL appliances only (W) — what the battery must carry in an outage. */
    public static function criticalLoadW(array $loads): float
    {
        $sum = 0.0;
        foreach ($loads as $l) {
            if (! empty($l['critical'])) {
                $sum += self::loadWatts($l);
            }
        }
        return round($sum, 2);
    }

    /** Daily energy demand (Wh/day) = Σ (watts × qty × hours_per_day). */
    public static function dailyEnergyWh(array $loads): float
    {
        $sum = 0.0;
        foreach ($loads as $l) {
            $sum += self::loadWatts($l) * max(0.0, (float) ($l['hours_per_day'] ?? 0));
        }
        return round($sum, 2);
    }

    /** Peak (instantaneous) demand (W) = Σ (watts × qty × surge_factor). Surge>1 covers motor start-up. */
    public static function peakDemandW(array $loads): float
    {
        $sum = 0.0;
        foreach ($loads as $l) {
            $surge = max(1.0, (float) ($l['surge_factor'] ?? 1.0));
            $sum += self::loadWatts($l) * $surge;
        }
        return round($sum, 2);
    }

    /**
     * PV array size (W). The array must replace the day's energy AND the conversion losses, within the
     * available sun hours, plus the expansion allowance.
     *   pv = (dailyEnergy / systemEfficiency / peakSunHours) × (1 + expansion)
     */
    public static function pvArrayW(float $dailyWh, float $psh, float $sysEff, float $expansion): float
    {
        $psh    = $psh > 0 ? $psh : self::DEFAULTS['peak_sun_hours'];
        $sysEff = $sysEff > 0 ? $sysEff : self::DEFAULTS['system_efficiency'];
        $pv = ($dailyWh / $sysEff / $psh) * (1 + max(0.0, $expansion));
        return round($pv, 2);
    }

    /**
     * Battery bank gross capacity (Wh). Must deliver the critical loads for the backup window, after
     * de-rating for depth-of-discharge and round-trip efficiency, plus the expansion allowance.
     *   bank = (criticalW × backupHours / (DoD × batteryEff)) × (1 + expansion)
     */
    public static function batteryBankWh(float $criticalW, float $backupHours, float $dod, float $battEff, float $expansion): float
    {
        $dod     = $dod > 0 ? $dod : self::DEFAULTS['depth_of_discharge'];
        $battEff = $battEff > 0 ? $battEff : self::DEFAULTS['battery_efficiency'];
        $need = $criticalW * max(0.0, $backupHours);
        $bank = ($need / ($dod * $battEff)) * (1 + max(0.0, $expansion));
        return round($bank, 2);
    }

    /** Battery bank capacity expressed in amp-hours at the system voltage: Ah = Wh / V. */
    public static function batteryBankAh(float $bankWh, float $systemVoltage): float
    {
        $v = $systemVoltage > 0 ? $systemVoltage : self::DEFAULTS['system_voltage'];
        return round($bankWh / $v, 2);
    }

    /** Inverter real-power rating (W) = peak demand × safety margin. */
    public static function inverterW(float $peakW, float $safety): float
    {
        return round($peakW * max(1.0, $safety), 2);
    }

    /** Inverter apparent-power rating (VA) = inverter W / power factor. */
    public static function inverterVA(float $inverterW, float $powerFactor): float
    {
        $pf = $powerFactor > 0 ? $powerFactor : self::DEFAULTS['power_factor'];
        return round($inverterW / $pf, 2);
    }

    // ── Orchestrator ────────────────────────────────────────────────────────────────────────────

    public function size(array $brief, array $config = []): array
    {
        $cfg = array_merge(self::DEFAULTS, array_filter($config, fn ($v) => $v !== null && $v !== ''));

        // Brief may override two location/project assumptions directly.
        $psh       = (float) ($brief['peak_sun_hours'] ?? $cfg['peak_sun_hours']);
        $expansion = (float) ($brief['expansion_pct']  ?? $cfg['expansion_pct']);
        $backup    = (float) ($brief['backup_hours']   ?? 0);
        $loads     = is_array($brief['loads'] ?? null) ? $brief['loads'] : [];

        $connectedW = self::totalConnectedW($loads);
        $criticalW  = self::criticalLoadW($loads);
        $dailyWh    = self::dailyEnergyWh($loads);
        $peakW      = self::peakDemandW($loads);

        $pvW        = self::pvArrayW($dailyWh, $psh, (float) $cfg['system_efficiency'], $expansion);
        $panelCount = ((float) $cfg['panel_watt'] > 0) ? (int) ceil($pvW / (float) $cfg['panel_watt']) : null;

        $bankWh = self::batteryBankWh($criticalW, $backup, (float) $cfg['depth_of_discharge'], (float) $cfg['battery_efficiency'], $expansion);
        $bankAh = self::batteryBankAh($bankWh, (float) $cfg['system_voltage']);

        $invW  = self::inverterW($peakW, (float) $cfg['safety_margin']);
        $invVA = self::inverterVA($invW, (float) $cfg['power_factor']);

        return [
            'engine'   => $this->key(),
            'inputs'   => [
                'load_count'     => count($loads),
                'backup_hours'   => $backup,
                'peak_sun_hours' => $psh,
                'expansion_pct'  => $expansion,
            ],
            'assumptions' => [
                'system_efficiency'  => (float) $cfg['system_efficiency'],
                'battery_efficiency' => (float) $cfg['battery_efficiency'],
                'depth_of_discharge' => (float) $cfg['depth_of_discharge'],
                'system_voltage'     => (float) $cfg['system_voltage'],
                'safety_margin'      => (float) $cfg['safety_margin'],
                'power_factor'       => (float) $cfg['power_factor'],
                'panel_watt'         => (float) $cfg['panel_watt'],
            ],
            'result' => [
                'connected_load_w'   => $connectedW,
                'critical_load_w'    => $criticalW,
                'daily_energy_wh'    => $dailyWh,
                'daily_energy_kwh'   => round($dailyWh / 1000, 3),
                'peak_demand_w'      => $peakW,
                'pv_array_w'         => $pvW,
                'panel_count'        => $panelCount,
                'battery_bank_wh'    => $bankWh,
                'battery_bank_ah'    => $bankAh,
                'inverter_w'         => $invW,
                'inverter_va'        => $invVA,
            ],
            // Ordered, human-readable working for the AI narrative and the proposal's "Assumptions" /
            // "Estimated system performance" sections. Keep formulas as text — never re-computed by AI.
            'steps' => [
                ['label' => 'Connected load',   'formula' => 'Σ (watts × qty)',                                   'value' => $connectedW, 'unit' => 'W'],
                ['label' => 'Critical load',    'formula' => 'Σ (watts × qty) for critical appliances',           'value' => $criticalW,  'unit' => 'W'],
                ['label' => 'Daily energy',     'formula' => 'Σ (watts × qty × hours/day)',                       'value' => $dailyWh,    'unit' => 'Wh/day'],
                ['label' => 'Peak demand',      'formula' => 'Σ (watts × qty × surge)',                           'value' => $peakW,      'unit' => 'W'],
                ['label' => 'PV array',         'formula' => 'daily ÷ efficiency ÷ sun-hours × (1 + expansion)',  'value' => $pvW,        'unit' => 'W'],
                ['label' => 'Battery bank',     'formula' => 'critical × backup ÷ (DoD × eff) × (1 + expansion)', 'value' => $bankWh,     'unit' => 'Wh'],
                ['label' => 'Battery bank',     'formula' => 'bank Wh ÷ system voltage',                          'value' => $bankAh,     'unit' => 'Ah'],
                ['label' => 'Inverter',         'formula' => 'peak × safety margin',                              'value' => $invW,       'unit' => 'W'],
                ['label' => 'Inverter',         'formula' => 'inverter W ÷ power factor',                          'value' => $invVA,      'unit' => 'VA'],
            ],
        ];
    }
}
