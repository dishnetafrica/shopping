<?php declare(strict_types=1);

namespace App\Services\Money;

use App\Models\OrderCost;
use App\Models\Tenant;

/**
 * The ONE cash-balance rule (Roadmap E5.1, Constitution R2/R5 — one ledger, one rule).
 *
 * "Cash on hand" was computed by three separate surfaces — Cash Position, Staff Cash and My Cash —
 * each re-deriving the same signed sum over the `order_costs` cash ledger with subtly different
 * filters, so they could drift. This service is the single source of that number: a signed sum of
 * native cash movements over the SAME ledger the Trade Cashbook uses, filterable by holder/currency.
 * Cash Position / Staff Cash / My Cash become *filters* over this one rule (wired in E5.2), not
 * separate implementations.
 *
 * The rule (identical to TradeCashbookController::data()'s headline balance):
 *   base set   = order_costs for the tenant, status = approved, that actually moved cash
 *                (pay_status != 'credit' — credit purchases are payables, no cash moved),
 *   sign       = +native for cash-IN stages {funding, cash_in, customer_payment}, −native otherwise,
 *   amount     = paid_native (the cash actually moved), summed per currency.
 *
 * Read-only: this class never writes. E5.1 introduces it as a new surface with no callers yet, so it
 * cannot change any existing behaviour; E5.2 points the controllers at it.
 */
class CashService
{
    /** Stages that ADD to a holder's cash. Everything else reduces it. */
    public const IN_STAGES = ['funding', 'cash_in', 'customer_payment'];

    /** The three transactional currencies the cashbook tracks. */
    public const CURRENCIES = ['USD', 'UGX', 'SSP'];

    public function __construct(private Tenant $tenant) {}

    public static function forTenant(Tenant $t): self
    {
        return new self($t);
    }

    /**
     * The pure signed-native rule for one movement — DB-free so it can be unit-tested and reused.
     * +paid_native for a cash-in stage, −paid_native for anything else.
     */
    public static function signedNative(string $stage, float $paidNative): float
    {
        return (in_array($stage, self::IN_STAGES, true) ? 1.0 : -1.0) * $paidNative;
    }

    /**
     * Aggregate already-fetched rows into per-currency native balances. Pure (no DB) so the exact
     * summation is testable and shared by balances(). Each row is ['currency'=>, 'stage'=>, 'paid_native'=>].
     *
     * @param  array<int,array{currency:string,stage:string,paid_native:float}>  $rows
     * @return array{USD:float,UGX:float,SSP:float}
     */
    public static function sumNativeByCurrency(array $rows): array
    {
        $bal = ['USD' => 0.0, 'UGX' => 0.0, 'SSP' => 0.0];
        foreach ($rows as $r) {
            $cur = strtoupper((string) ($r['currency'] ?? ''));
            if (! array_key_exists($cur, $bal)) continue;
            $bal[$cur] += self::signedNative((string) ($r['stage'] ?? ''), (float) ($r['paid_native'] ?? 0));
        }
        return array_map(fn ($v) => round($v, 2), $bal);
    }

    /**
     * Per-currency native cash balances for the given filters.
     *
     * @param  array{currency?:string,holder?:string,holder_user_id?:int,status?:string}  $filters
     * @return array{USD:float,UGX:float,SSP:float}
     */
    public function balances(array $filters = []): array
    {
        $rows = $this->baseQuery($filters)
            ->get(['currency', 'stage', 'paid_native'])
            ->map(fn ($r) => [
                'currency'    => (string) $r->currency,
                'stage'       => (string) $r->stage,
                'paid_native' => (float) $r->paid_native,
            ])
            ->all();

        return self::sumNativeByCurrency($rows);
    }

    /**
     * A single native balance. With a `currency` filter it returns that currency's balance; without
     * one it defaults to USD. (Cross-currency USD-equivalent totals are intentionally out of scope —
     * mixing currencies into one number is a reporting concern, not a cash-on-hand fact.)
     *
     * @param  array{currency?:string,holder?:string,holder_user_id?:int,status?:string}  $filters
     */
    public function balance(array $filters = []): float
    {
        $cur = strtoupper((string) ($filters['currency'] ?? 'USD'));
        $b = $this->balances($filters);
        return $b[$cur] ?? 0.0;
    }

    /**
     * The canonical filtered query over the cash ledger — the ONE place the base set is defined.
     * status defaults to 'approved' (the Trade Cashbook headline rule); credit rows are always
     * excluded because they moved no cash.
     *
     * @param  array{currency?:string,holder?:string,holder_user_id?:int,status?:string}  $filters
     */
    private function baseQuery(array $filters)
    {
        $q = OrderCost::where('tenant_id', $this->tenant->id)
            ->where('status', (string) ($filters['status'] ?? 'approved'))
            ->where(fn ($w) => $w->where('pay_status', '!=', 'credit')->orWhereNull('pay_status'));

        $cur = strtoupper((string) ($filters['currency'] ?? ''));
        if ($cur !== '' && $cur !== 'ALL') {
            $q->where('currency', $cur);
        }

        $holder = trim((string) ($filters['holder'] ?? ''));
        $uid    = (int) ($filters['holder_user_id'] ?? 0);
        if ($uid > 0 || $holder !== '') {
            $q->where(function ($w) use ($uid, $holder) {
                if ($uid > 0)      $w->orWhere('holder_user_id', $uid);
                if ($holder !== '') $w->orWhere('holder', $holder);
            });
        }

        return $q;
    }
}
