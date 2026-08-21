<?php

namespace App\Services\Finance;

use App\Models\OrderCost;
use App\Services\Money\CashService;
use Illuminate\Support\Facades\DB;

/**
 * Cash on hand as the v3 Cashbook screen shows it — per currency AND per holder.
 *
 * This is the reader behind "Cash by person" (CashbookCapability). It counts every LIVE cash entry
 * (pay_status != credit, status NOT IN void/rejected), sums native_amount, and signs each row by the
 * frozen CashService::IN_STAGES rule (funding/cash_in/customer_payment add; everything else reduces).
 * USD value uses the dated usd_amount recorded on the row, falling back to native ÷ rate for legacy rows.
 *
 * It exists so the Cashbook screen, the cash-book exports, and the Pay Advisor all read cash the SAME
 * way — CashService (approved-only, paid_native) answers a narrower question and disagrees with the
 * screen, which is why the advisor must use THIS reader.
 */
final class CashOnHand
{
    /** Pure fold of grouped rows → per-currency position + per-holder balances. No DB. */
    public static function fold(iterable $rows): array
    {
        $pos = ['USD' => ['native' => 0.0, 'usd' => 0.0], 'UGX' => ['native' => 0.0, 'usd' => 0.0], 'SSP' => ['native' => 0.0, 'usd' => 0.0]];
        $holders = [];
        foreach ($rows as $r) {
            $sign = in_array((string) ($r['stage'] ?? ''), CashService::IN_STAGES, true) ? 1 : -1;
            $c = (string) ($r['currency'] ?? '');
            $native = $sign * (float) ($r['native'] ?? 0);
            $usd = $sign * (float) ($r['usd'] ?? 0);
            if (isset($pos[$c])) { $pos[$c]['native'] += $native; $pos[$c]['usd'] += $usd; }
            $h = trim((string) ($r['holder'] ?? '')) ?: 'Unassigned';
            if (! isset($holders[$h])) { $holders[$h] = ['USD' => 0.0, 'UGX' => 0.0, 'SSP' => 0.0, 'usd' => 0.0]; }
            if (isset($holders[$h][$c])) { $holders[$h][$c] += $native; }
            $holders[$h]['usd'] += $usd;
        }
        $combined = round($pos['USD']['usd'] + $pos['UGX']['usd'] + $pos['SSP']['usd'], 2);
        return ['pos' => $pos, 'holders' => $holders, 'combined_usd' => $combined];
    }

    /**
     * Full position for a tenant. Owner view = all holders; pass a user id/name to scope to one person's
     * own cash (staff view). Returns ['pos'=>per-currency, 'holders'=>per-holder, 'combined_usd'=>float].
     */
    public static function position(int $tid, ?int $userId = null, ?string $userName = null): array
    {
        $q = OrderCost::where('tenant_id', $tid)
            ->where(fn ($w) => $w->where('pay_status', '!=', 'credit')->orWhereNull('pay_status'))
            ->whereNotIn('status', ['void', 'rejected']);
        if (($userId ?? 0) > 0 || ($userName ?? '') !== '') {
            $q->where(function ($w) use ($userId, $userName) {
                if (($userId ?? 0) > 0) { $w->orWhere('holder_user_id', $userId); }
                if (($userName ?? '') !== '') { $w->orWhere('holder', $userName); }
            });
        }
        $rows = $q->select('currency', 'stage', 'holder',
                DB::raw('SUM(native_amount) as native'),
                DB::raw('SUM(COALESCE(NULLIF(usd_amount,0), native_amount / NULLIF(rate_to_usd,0), 0)) as usd'))
            ->groupBy('currency', 'stage', 'holder')->get()
            ->map(fn ($r) => ['currency' => (string) $r->currency, 'stage' => (string) $r->stage, 'holder' => (string) $r->holder, 'native' => (float) $r->native, 'usd' => (float) $r->usd]);

        return self::fold($rows);
    }

    /** One holder's native cash balances {USD,UGX,SSP} — matches the "Cash by person" row exactly. */
    public static function holderNative(int $tid, string $holder): array
    {
        $h = self::position($tid)['holders'][$holder] ?? [];
        return ['USD' => (float) ($h['USD'] ?? 0), 'UGX' => (float) ($h['UGX'] ?? 0), 'SSP' => (float) ($h['SSP'] ?? 0)];
    }
}
