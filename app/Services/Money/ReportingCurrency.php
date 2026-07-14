<?php

namespace App\Services\Money;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Support\Money;
use App\Models\Tenant;

/**
 * The single currency-aware reporting engine (Capability layer).
 *
 * Every financial screen in v3 asks this one object "how should I show money?" so the whole
 * platform is consistent. It knows three things:
 *
 *   1. The tenant's BASE currency          — Tenant setting `currency` (UGX/USD/SSP).
 *   2. The report currency MODE            — Tenant setting `report_currency_mode`:
 *        - 'base'     → consolidate every transaction into the base currency (default).
 *        - 'original' → preserve each transaction's own native currency, no consolidation.
 *   3. A per-request OVERRIDE               — `?cur=` on the request (base|original|USD|UGX|SSP),
 *        set onto CapabilityContext::$displayOverride by the WorkspaceController. The override
 *        wins over the tenant mode for that one request so a user can peek a report in USD.
 *
 * Exchange-rate rule (never re-price history with today's rate):
 *   - Prefer the USD amount STORED on the transaction (its own historical rate to USD).
 *   - Else use Fx effective-dated by the transaction's own date.
 *   - Conversions are restricted to the rateable set USD/UGX/SSP; anything else is shown native.
 *
 * Presentation-only: it reads settings and calls Fx, but writes nothing and touches no business
 * models, so it is safe for the frozen engine.
 */
final class ReportingCurrency
{
    /** Currencies Fx can actually rate. Anything outside this set is shown in its own currency. */
    public const RATEABLE = ['USD', 'UGX', 'SSP'];

    private function __construct(
        private readonly Fx $fx,
        private readonly string $base,       // tenant base currency (rateable, upper)
        private readonly bool $original,     // true => preserve native currencies
        private readonly string $display,    // the single currency used when consolidating
    ) {}

    public static function forTenant(?Tenant $tenant, ?string $override = null): self
    {
        $base = self::rateableOr(
            strtoupper((string) (($tenant && method_exists($tenant, 'setting') ? $tenant->setting('currency') : null) ?: 'UGX')),
            'UGX'
        );
        $mode = strtolower((string) (($tenant && method_exists($tenant, 'setting') ? $tenant->setting('report_currency_mode') : null) ?: 'base'));

        return self::resolve($tenant, $base, $mode, $override);
    }

    public static function forContext(CapabilityContext $ctx): self
    {
        $override = property_exists($ctx, 'displayOverride') ? $ctx->displayOverride : null;

        return self::forTenant($ctx->tenant, $override);
    }

    private static function resolve(?Tenant $tenant, string $base, string $mode, ?string $override): self
    {
        $original = ($mode === 'original');
        $display  = $base;

        $ov = strtolower(trim((string) $override));
        if ($ov !== '') {
            if ($ov === 'original') {
                $original = true;
                $display  = $base;
            } elseif ($ov === 'base') {
                $original = false;
                $display  = $base;
            } else {
                $up = strtoupper($ov);
                if (in_array($up, self::RATEABLE, true)) {
                    $original = false;
                    $display  = $up;
                }
            }
        }

        return new self(Fx::forTenant($tenant), $base, $original, $display);
    }

    private static function rateableOr(string $cur, string $fallback): string
    {
        return in_array($cur, self::RATEABLE, true) ? $cur : $fallback;
    }

    // ---- introspection -------------------------------------------------------------------------

    public function isOriginal(): bool { return $this->original; }

    public function baseCurrency(): string { return $this->base; }

    /** The currency shown when consolidating. In original mode there is no single display currency. */
    public function displayCurrency(): ?string { return $this->original ? null : $this->display; }

    /** KPI tiles must always be one currency even in original mode — fall back to base there. */
    public function consolidationCurrency(): string { return $this->original ? $this->base : $this->display; }

    /** A short human label for the mode, for the report header. */
    public function modeLabel(): string
    {
        return $this->original ? 'Original currency' : ('Consolidated · ' . $this->display);
    }

    // ---- per-row display -----------------------------------------------------------------------

    /**
     * How a single money value should appear in a report row.
     * Returns ['amount'=>float, 'currency'=>string, 'text'=>string].
     *
     * In original mode the value is shown in its own currency untouched. Otherwise it is converted
     * to the display currency using the stored USD (preferred) or Fx effective-dated by $date.
     */
    public function line(float $amount, ?string $currency, ?float $usd = null, ?string $date = null): array
    {
        $cur = strtoupper((string) ($currency ?: $this->base));

        if ($this->original) {
            return ['amount' => $amount, 'currency' => $cur, 'text' => Money::fmt($amount, $cur)];
        }

        $target = $this->display;
        $out    = $this->to($amount, $cur, $target, $usd, $date);

        return ['amount' => $out, 'currency' => $target, 'text' => Money::fmt($out, $target)];
    }

    /** Just the formatted string for a row (convenience over line()). */
    public function text(float $amount, ?string $currency, ?float $usd = null, ?string $date = null): string
    {
        return $this->line($amount, $currency, $usd, $date)['text'];
    }

    // ---- consolidation (KPI tiles / totals) ----------------------------------------------------

    /**
     * Sum a set of rows into a single consolidation currency. Each row:
     *   ['amount'=>float, 'currency'=>string, 'usd'=>?float, 'date'=>?string]
     * Returns ['amount'=>float, 'currency'=>string, 'text'=>string]. Always one currency, so KPI
     * tiles never mix symbols — even when the report body is shown in original currencies.
     */
    public function consolidate(array $rows): array
    {
        $target = $this->consolidationCurrency();
        $sum    = 0.0;
        foreach ($rows as $r) {
            $sum += $this->to(
                (float) ($r['amount'] ?? 0),
                strtoupper((string) ($r['currency'] ?? $target)),
                $target,
                isset($r['usd']) ? (float) $r['usd'] : null,
                $r['date'] ?? null,
            );
        }

        return ['amount' => $sum, 'currency' => $target, 'text' => Money::fmt($sum, $target)];
    }

    /** Formatted consolidated total for a set of rows (convenience over consolidate()). */
    public function totalText(array $rows): string
    {
        return $this->consolidate($rows)['text'];
    }

    /** Format an already-consolidation-currency figure (e.g. a scalar KPI computed elsewhere). */
    public function fmtConsolidated(float $amount): string
    {
        return Money::fmt($amount, $this->consolidationCurrency());
    }

    // ---- the one conversion primitive ----------------------------------------------------------

    /**
     * Convert one native amount to $target, honouring the "never re-price history" rule:
     *   - same currency            → as-is
     *   - stored USD present       → pivot through it (transaction's own historical USD)
     *   - both currencies rateable → Fx effective-dated by $date
     *   - otherwise                → return native (cannot safely rate, e.g. EUR with no stored USD)
     */
    private function to(float $amount, string $from, string $target, ?float $usd, ?string $date): float
    {
        $from   = strtoupper($from);
        $target = strtoupper($target);
        if ($from === $target) {
            return $amount;
        }

        // Prefer the amount's own stored USD (its historical rate). Pivot USD -> target via Fx.
        if ($usd !== null && $usd > 0.0) {
            if ($target === 'USD') {
                return round($usd, 2);
            }
            if (in_array($target, self::RATEABLE, true)) {
                return $this->fx->fromUsd($usd, $target, $date);
            }
        }

        if (in_array($from, self::RATEABLE, true) && in_array($target, self::RATEABLE, true)) {
            return $this->fx->convert($amount, $from, $target, $date);
        }

        // Unrateable currency with no stored USD — never corrupt it; show it as it is.
        return $amount;
    }
}
