<?php

namespace Tests\Unit;

use App\Models\Tenant;
use App\Services\Money\Fx;
use Tests\TestCase;

/**
 * Roadmap E3.1 — the single FX service. Pure logic, no DB.
 */
class FxTest extends TestCase
{
    private function tenant(array $settings = []): Tenant
    {
        $t = new Tenant();
        $t->settings = $settings;
        return $t;
    }

    public function test_same_currency_is_identity(): void
    {
        $fx = Fx::forTenant($this->tenant());
        $this->assertSame(100.0, $fx->convert(100, 'USD', 'USD'));
        $this->assertSame(5000.0, $fx->convert(5000, 'UGX', 'UGX'));
    }

    public function test_default_rates_when_unconfigured(): void
    {
        $fx = Fx::forTenant($this->tenant());
        $this->assertSame(365500.0, $fx->convert(100, 'USD', 'UGX')); // 100 * 3655
        $this->assertSame(100.0, $fx->convert(365500, 'UGX', 'USD')); // 365500 / 3655
        $this->assertSame(518000.0, $fx->convert(100, 'USD', 'SSP')); // 100 * 5180
    }

    public function test_legacy_single_rate_settings_are_used_when_no_fx_rates(): void
    {
        // Correct even BEFORE the E3.2 backfill — reads the tenant's existing per-usd settings.
        $fx = Fx::forTenant($this->tenant(['rate_ugx_per_usd' => 3800, 'rate_ssp_per_usd' => 5000]));
        $this->assertSame(380000.0, $fx->convert(100, 'USD', 'UGX'));
        $this->assertSame(500000.0, $fx->convert(100, 'USD', 'SSP'));
    }

    public function test_fx_rates_override_legacy(): void
    {
        $fx = Fx::forTenant($this->tenant([
            'rate_ugx_per_usd' => 3800,                                  // should be ignored…
            'fx_rates' => [['from' => '2026-01-01', 'ugx_per_usd' => 3655, 'ssp_per_usd' => 5180]],
        ]));
        $this->assertSame(365500.0, $fx->convert(100, 'USD', 'UGX', '2026-05-01'));
    }

    public function test_effective_dated_lookup(): void
    {
        $fx = Fx::forTenant($this->tenant([
            'fx_rates' => [
                ['from' => '2026-01-01', 'ugx_per_usd' => 3600],
                ['from' => '2026-06-01', 'ugx_per_usd' => 3700],
            ],
        ]));
        $this->assertSame(360000.0, $fx->convert(100, 'USD', 'UGX', '2026-03-01')); // before June => 3600
        $this->assertSame(370000.0, $fx->convert(100, 'USD', 'UGX', '2026-07-01')); // on/after June => 3700
        $this->assertSame(360000.0, $fx->convert(100, 'USD', 'UGX', '2025-12-01')); // before all => earliest
    }

    public function test_cross_rate_ugx_to_ssp(): void
    {
        $fx = Fx::forTenant($this->tenant([
            'fx_rates' => [['from' => null, 'ugx_per_usd' => 4000, 'ssp_per_usd' => 5000]],
        ]));
        // 40,000 UGX = 10 USD = 50,000 SSP
        $this->assertSame(50000.0, $fx->convert(40000, 'UGX', 'SSP'));
    }

    public function test_unknown_currency_is_not_corrupted(): void
    {
        $fx = Fx::forTenant($this->tenant());
        // Unknown currency treated as USD-equivalent (rate 1) rather than zeroed out.
        $this->assertSame(100.0, $fx->convert(100, 'USD', 'EUR'));
    }

    /** E3.2 — canonicalRates seeds from the accounting family, defaults otherwise. */
    public function test_canonical_rates_from_accounting_family(): void
    {
        $this->assertSame(
            ['ugx_per_usd' => 3655.0, 'ssp_per_usd' => 5180.0],
            Fx::canonicalRates([])
        );
        $this->assertSame(
            ['ugx_per_usd' => 3700.0, 'ssp_per_usd' => 5000.0],
            Fx::canonicalRates(['rate_ugx_per_usd' => 3700, 'rate_ssp_per_usd' => 5000])
        );
        // client family (usdUgx) is intentionally ignored — corrected to accounting rate in E3.3
        $this->assertSame(
            ['ugx_per_usd' => 3655.0, 'ssp_per_usd' => 5180.0],
            Fx::canonicalRates(['usdUgx' => 3750, 'usdSsp' => 7000])
        );
        // non-positive rate falls back to default
        $this->assertSame(3655.0, Fx::canonicalRates(['rate_ugx_per_usd' => 0])['ugx_per_usd']);
    }

    /** E3.4 — Fx::ratePerUsd reproduces the legacy inline invoice rate for the real currencies. */
    public function test_rate_per_usd_matches_legacy_invoice_formula(): void
    {
        $legacy = fn (Tenant $t, string $c): float => $c === 'USD'
            ? 1.0
            : (float) $t->setting('rate_' . strtolower($c) . '_per_usd', $c === 'UGX' ? 3655 : 5180);

        $cases = [
            [],
            ['rate_ugx_per_usd' => 3800, 'rate_ssp_per_usd' => 5000],
            ['fx_rates' => [['from' => null, 'ugx_per_usd' => 3655, 'ssp_per_usd' => 5180]]],
            ['rate_ugx_per_usd' => 3900, 'rate_ssp_per_usd' => 5300,
             'fx_rates' => [['from' => null, 'ugx_per_usd' => 3900, 'ssp_per_usd' => 5300]]],
        ];
        foreach ($cases as $s) {
            $t  = $this->tenant($s);
            $fx = Fx::forTenant($t);
            foreach (['USD', 'UGX', 'SSP'] as $c) {
                $this->assertSame($legacy($t, $c), $fx->ratePerUsd($c), "rate mismatch for $c / " . json_encode($s));
            }
        }
    }

    /** E3.2 — seeding fx_rates from canonicalRates leaves Fx output unchanged vs the fallback. */
    public function test_seeded_fx_rates_match_current_fallback(): void
    {
        $settings = ['rate_ugx_per_usd' => 3655, 'rate_ssp_per_usd' => 5180];
        $before = Fx::forTenant($this->tenant($settings))->convert(100, 'USD', 'UGX');

        $seeded = $settings;
        $seeded['fx_rates'] = [array_merge(['from' => null], Fx::canonicalRates($settings))];
        $after = Fx::forTenant($this->tenant($seeded))->convert(100, 'USD', 'UGX');

        $this->assertSame($before, $after, 'backfill must not change the accounting conversion');
    }
}
