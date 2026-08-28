<?php

namespace App\Services\Sales;

use App\Models\Tenant;
use App\Services\Money\Fx;

/**
 * Base-currency costing for a sale — the ONE place that reconciles a sale's revenue and cost to the
 * base/report currency, so the order screen (SalesOrderCapability) and the double-entry ledger
 * (OrderLedgerService) can never disagree.
 *
 * Why this exists: products.cost_price is persisted in the LOCAL trading currency (UGX). The purchase
 * writer (OrderCostController::$toUgx) converts every purchase cost to UGX before it lands on the
 * product — its own comment says "convert a unit cost in the purchase currency to base UGX for the
 * product's cost_price". But a sale's revenue is in the order's sell_currency (e.g. USD), and the base
 * currency may be a third one (USD). Comparing a UGX cost against a USD revenue as if both were the
 * same unit is the "UGX 2,000,000 read as USD 2,000,000 → −242,324% margin" bug.
 *
 * Phase-1 fix: convert both sides to the base currency at the order's date, using the existing Fx.
 * (Margin % is currency-invariant, so the % is exact regardless of base.) A UGX-base tenant converts
 * UGX→UGX = a no-op, so this is correct for them too. Phase-2 will tag cost_price with its own
 * currency + historical rate for a true historical cost basis; until then UGX is the persisted cost
 * currency for every tenant on this purchase flow.
 */
final class OrderCosting
{
    /** Currency that products.cost_price is stored in (see OrderCostController::$toUgx). */
    public const COST_CURRENCY = 'UGX';

    /**
     * Reconcile a sale's cost and revenue to the base currency and compute margin. Pure given $toBase.
     *
     * @param  float    $cogsCost  COGS in $costCur (cost_price × qty; UGX)
     * @param  string   $costCur   currency of $cogsCost (COST_CURRENCY)
     * @param  float    $revenue   revenue in $sellCur (the order total, net of VAT if applicable)
     * @param  string   $sellCur   the order's own currency
     * @param  callable $toBase    fn(float $amount, string $fromCurrency): float → amount in base currency
     * @return array{cogs_base:float, revenue_base:float, profit:float, pct:?float}
     */
    public static function reconcile(float $cogsCost, string $costCur, float $revenue, string $sellCur, callable $toBase): array
    {
        $cogsBase = round((float) $toBase($cogsCost, $costCur), 2);
        $revBase  = round((float) $toBase($revenue, $sellCur), 2);
        $profit   = round($revBase - $cogsBase, 2);
        $pct      = $revBase > 0.0001 ? round($profit / $revBase * 100, 1) : null;

        return ['cogs_base' => $cogsBase, 'revenue_base' => $revBase, 'profit' => $profit, 'pct' => $pct];
    }

    /** Build the $toBase converter from the tenant's Fx for a given base currency + date. */
    public static function converter(Tenant $tenant, string $baseCur, ?string $date = null): callable
    {
        $fx = Fx::forTenant($tenant);

        return fn (float $amount, string $from): float => $fx->convert($amount, $from, $baseCur, $date);
    }
}
