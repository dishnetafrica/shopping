<?php declare(strict_types=1);

namespace App\Support;

/**
 * Per-tenant VAT configuration (Constitution v1.0 · P0 Accounting · §4a Phase 2).
 *
 * VAT is NEVER hardcoded — every part of it is a tenant setting, so Uganda 18%, Kenya 16%, a zero-rated
 * shop, or VAT-inclusive vs -exclusive pricing are all just configuration. This is the single reader +
 * the split/label helpers the invoice/quote rendering and the LedgerService VAT postings will use, so
 * the number is computed one way everywhere (Principle Zero — one VAT calc, not several).
 *
 * Settings (all per-tenant, with safe defaults; legacy keys honoured for back-compat):
 *   vat_enabled          bool    — master on/off (default: rate > 0)
 *   vat_rate             number  — percentage, e.g. 18 (falls back to legacy 'quote_vat_pct')
 *   prices_include_vat   bool    — are catalogue prices VAT-inclusive? (existing key, reused)
 *   vat_label            string  — 'VAT' | 'GST' | 'Sales Tax' … (default 'VAT')
 *   vat_number           string  — the tenant's tax registration no. for invoice display (default '')
 *
 * Additive and dormant: nothing calls this yet. Wired into invoices + LedgerService in §4a Phase 2.
 */
final class VatConfig
{
    public static function rate($tenant): float
    {
        if (! $tenant) return 0.0;
        $r = $tenant->setting('vat_rate', $tenant->setting('quote_vat_pct', 0)); // back-compat with the legacy key
        return max(0.0, (float) $r);
    }

    public static function enabled($tenant): bool
    {
        if (! $tenant) return false;
        return (bool) $tenant->setting('vat_enabled', self::rate($tenant) > 0);
    }

    public static function inclusive($tenant): bool
    {
        return $tenant ? (bool) $tenant->setting('prices_include_vat', false) : false;
    }

    public static function label($tenant): string
    {
        $l = $tenant ? trim((string) $tenant->setting('vat_label', 'VAT')) : 'VAT';
        return $l !== '' ? $l : 'VAT';
    }

    public static function number($tenant): string
    {
        return $tenant ? trim((string) $tenant->setting('vat_number', '')) : '';
    }

    /**
     * Split an amount into net / tax / gross using the tenant's rate + inclusive flag.
     * If VAT is disabled or the rate is 0, tax is 0 and net = gross = amount. All values rounded to 2dp.
     *
     * @return array{net:float,tax:float,gross:float,rate:float,inclusive:bool}
     */
    public static function split(float $amount, $tenant): array
    {
        $rate = self::enabled($tenant) ? self::rate($tenant) : 0.0;
        if ($rate <= 0.0) {
            $a = round($amount, 2);
            return ['net' => $a, 'tax' => 0.0, 'gross' => $a, 'rate' => 0.0, 'inclusive' => false];
        }
        $inclusive = self::inclusive($tenant);
        if ($inclusive) {
            $net   = round($amount / (1 + $rate / 100), 2);
            $gross = round($amount, 2);
            $tax   = round($gross - $net, 2);
        } else {
            $net   = round($amount, 2);
            $tax   = round($net * $rate / 100, 2);
            $gross = round($net + $tax, 2);
        }
        return ['net' => $net, 'tax' => $tax, 'gross' => $gross, 'rate' => $rate, 'inclusive' => $inclusive];
    }
}
