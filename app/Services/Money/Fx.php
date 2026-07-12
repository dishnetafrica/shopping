<?php

namespace App\Services\Money;

use App\Models\Tenant;

/**
 * The single exchange-rate service (Roadmap Epic 3 / E3.1, Constitution R8).
 *
 * USD is the base currency. Rates are "native units per 1 USD" (e.g. UGX 3655, SSP 5180) and are
 * EFFECTIVE-DATED: a tenant's `fx_rates` setting is a list of
 *     [ ['from' => 'YYYY-MM-DD', 'ugx_per_usd' => 3655, 'ssp_per_usd' => 5180], … ]
 * and convert() uses the entry in force on the given date. This means an invoice issued last month
 * keeps converting at last month's rate — issued documents never silently re-convert.
 *
 * Robust fallback so this is correct even BEFORE E3.2 backfills fx_rates:
 *   fx_rates setting → the tenant's legacy single-rate settings (rate_ugx_per_usd / rate_ssp_per_usd)
 *   → documented defaults. Nothing consumes this service yet (E3.3/E3.4 migrate the readers).
 */
class Fx
{
    public const BASE = 'USD';

    /** Native units per 1 USD — last-resort defaults (match the accounting/invoice path). */
    private const DEFAULTS = ['UGX' => 3655.0, 'SSP' => 5180.0];

    /** @var array<int,array{from:?string,ugx:float,ssp:float}> ascending by `from` */
    private array $schedule;

    private function __construct(array $schedule)
    {
        $this->schedule = $schedule;
    }

    public static function forTenant(?Tenant $t): self
    {
        return new self(self::loadSchedule($t));
    }

    /**
     * The canonical current rate for a tenant's settings, for the E3.2 backfill. Uses the
     * accounting/server family (rate_*_per_usd) — the money of record — falling back to defaults.
     * The client family (usdUgx/usdSsp) is deliberately NOT used here: it is the display setting the
     * audit found diverging, and it is corrected to this canonical rate in E3.3 under reconciliation.
     * Returns ['ugx_per_usd' => float, 'ssp_per_usd' => float].
     */
    public static function canonicalRates(array $settings): array
    {
        $ugx = (float) ($settings['rate_ugx_per_usd'] ?? 0);
        $ssp = (float) ($settings['rate_ssp_per_usd'] ?? 0);
        return [
            'ugx_per_usd' => $ugx > 0 ? $ugx : self::DEFAULTS['UGX'],
            'ssp_per_usd' => $ssp > 0 ? $ssp : self::DEFAULTS['SSP'],
        ];
    }

    /** Convert between USD / UGX / SSP using the rate effective on $date (default: today). */
    public function convert(float $amount, string $from, string $to, ?string $date = null): float
    {
        $from = strtoupper(trim($from));
        $to   = strtoupper(trim($to));
        if ($from === $to) {
            return round($amount, self::decimals($to));
        }
        return $this->fromUsd($this->toUsd($amount, $from, $date), $to, $date);
    }

    /** Amount in $cur → USD, at the rate effective on $date. */
    public function toUsd(float $amount, string $cur, ?string $date = null): float
    {
        $cur = strtoupper(trim($cur));
        if ($cur === self::BASE) {
            return round($amount, 2);
        }
        $rate = $this->ratePerUsd($cur, $date);
        return $rate > 0 ? round($amount / $rate, 2) : round($amount, 2);
    }

    /** USD amount → $cur, at the rate effective on $date. */
    public function fromUsd(float $usd, string $cur, ?string $date = null): float
    {
        $cur = strtoupper(trim($cur));
        if ($cur === self::BASE) {
            return round($usd, 2);
        }
        return round($usd * $this->ratePerUsd($cur, $date), self::decimals($cur));
    }

    /** Native units per 1 USD for $cur, effective on $date. USD => 1. Unknown currency => 1 (no-op). */
    public function ratePerUsd(string $cur, ?string $date = null): float
    {
        $cur = strtoupper(trim($cur));
        if ($cur === self::BASE) {
            return 1.0;
        }
        $key = $cur === 'UGX' ? 'ugx' : ($cur === 'SSP' ? 'ssp' : null);
        if ($key === null) {
            return 1.0; // currency we don't rate => treat as USD-equivalent (never corrupt an amount)
        }
        $row = $this->effectiveRow($date);
        $v   = (float) ($row[$key] ?? (self::DEFAULTS[$cur] ?? 1.0));
        return $v > 0 ? $v : (self::DEFAULTS[$cur] ?? 1.0);
    }

    /** The schedule row in force on $date (latest `from` <= date; earliest if date precedes all). */
    private function effectiveRow(?string $date): array
    {
        $date   = $date ?: date('Y-m-d');
        $chosen = null;
        foreach ($this->schedule as $row) {          // ascending by `from`
            $from = $row['from'] ?? null;
            if ($from === null || $from <= $date) {
                $chosen = $row;
            } else {
                break;
            }
        }
        return $chosen ?? ($this->schedule[0] ?? [
            'from' => null, 'ugx' => self::DEFAULTS['UGX'], 'ssp' => self::DEFAULTS['SSP'],
        ]);
    }

    private static function decimals(string $cur): int
    {
        return strtoupper($cur) === 'USD' ? 2 : 0;
    }

    /** fx_rates → legacy single-rate settings → defaults. Always returns >=1 ascending-sorted row. */
    private static function loadSchedule(?Tenant $t): array
    {
        $rows = [];
        $raw  = $t ? $t->setting('fx_rates', null) : null;
        if (is_array($raw)) {
            foreach ($raw as $r) {
                if (! is_array($r)) {
                    continue;
                }
                $rows[] = [
                    'from' => isset($r['from']) ? (string) $r['from'] : null,
                    'ugx'  => (float) ($r['ugx_per_usd'] ?? $r['ugx'] ?? self::DEFAULTS['UGX']),
                    'ssp'  => (float) ($r['ssp_per_usd'] ?? $r['ssp'] ?? self::DEFAULTS['SSP']),
                ];
            }
        }

        if (! $rows) {
            $ugx = $t ? (float) $t->setting('rate_ugx_per_usd', self::DEFAULTS['UGX']) : self::DEFAULTS['UGX'];
            $ssp = $t ? (float) $t->setting('rate_ssp_per_usd', self::DEFAULTS['SSP']) : self::DEFAULTS['SSP'];
            $rows[] = [
                'from' => null,
                'ugx'  => $ugx > 0 ? $ugx : self::DEFAULTS['UGX'],
                'ssp'  => $ssp > 0 ? $ssp : self::DEFAULTS['SSP'],
            ];
        }

        usort($rows, fn ($a, $b) => strcmp((string) ($a['from'] ?? ''), (string) ($b['from'] ?? '')));

        return $rows;
    }
}
