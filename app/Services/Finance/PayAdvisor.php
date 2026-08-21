<?php

namespace App\Services\Finance;

use App\Models\Supplier;
use App\Models\Tenant;
use App\Services\Money\Fx;
use Illuminate\Support\Carbon;

/**
 * Supplier Pay Advisor — ADVISORY ONLY. It reads payables (PayablesCalculator, the single source of
 * truth) and says who to pay and who to hold. It NEVER moves money: paying stays the human's action
 * through the existing "Pay supplier" flow. No ledger/cashbook writes.
 *
 * Rules (owner-set; defaults shown):
 *   - A bill is "overdue" once it is older than the SOONER of a flat threshold (15 days) or the
 *     supplier's own credit term (Net N) — nudge before the term lapses (relationship-first).
 *   - Recommendation = clear the overdue amount (pay on time). The buffer (keep 50% pending) is a
 *     WARNING, not a block: if paying the overdue would drop pending below the buffer, the verdict is
 *     "tension" and both numbers are shown so the human decides. Relationship beats hoarding cash.
 *
 * Amounts are in each supplier's own invoicing currency (from PayablesCalculator's native slices).
 */
final class PayAdvisor
{
    public const FLAT_DAYS = 15;
    public const BUFFER_PCT = 0.5;   // fraction of the balance to keep pending

    /** Pure decision — no I/O. Amounts in one currency. */
    public static function decide(float $balance, float $overdue, float $bufferPct = self::BUFFER_PCT): array
    {
        $balance = round(max(0.0, $balance), 2);
        $overdue = round(max(0.0, min($overdue, $balance)), 2);
        $recommend = $overdue;                        // relationship-first: clear what's late
        $pendingAfter = round($balance - $recommend, 2);
        $keepFloor = round($bufferPct * $balance, 2);

        if ($balance <= 0.005) {
            $state = 'clear';
        } elseif ($overdue <= 0.005) {
            $state = 'hold';
        } elseif ($pendingAfter + 0.005 >= $keepFloor) {
            $state = 'pay';
        } else {
            $state = 'tension';
        }

        return [
            'state' => $state,
            'balance' => $balance,
            'overdue' => $overdue,
            'recommend' => $recommend,
            'pending_after' => $pendingAfter,
            'pending_pct' => $balance > 0 ? (int) round(100.0 * $pendingAfter / $balance) : 0,
            'keep_floor' => $keepFloor,
        ];
    }

    /** Threshold in days for a supplier: the SOONER of the flat rule and the supplier's own term. */
    public static function thresholdDays(?int $termDays, int $flatDays = self::FLAT_DAYS): int
    {
        $flat = max(0, $flatDays);
        $term = ($termDays !== null && $termDays > 0) ? $termDays : 0;
        return ($term > 0) ? min($flat, $term) : $flat;
    }

    /** Age of a bill in whole days as of $asOf. */
    public static function ageDays($billAt, Carbon $asOf): int
    {
        if (! $billAt) { return 0; }
        $bill = Carbon::parse($billAt)->startOfDay()->timestamp;
        $now = $asOf->copy()->startOfDay()->timestamp;
        return (int) floor(($now - $bill) / 86400);
    }

    /** One supplier's advisory, or null if nothing is owed. */
    public function forSupplier(Tenant $t, Supplier $s, ?Carbon $asOf = null, int $flatDays = self::FLAT_DAYS, float $bufferPct = self::BUFFER_PCT): ?array
    {
        $slices = PayablesCalculator::unpaidSlicesBySupplier($t->id)[$s->id] ?? [];
        return $this->buildFromSlices($s, $slices, $asOf ?: Carbon::now(), $flatDays, $bufferPct, Fx::forTenant($t));
    }

    /** Every supplier with something owed, most overdue first. */
    public function forTenant(Tenant $t, ?Carbon $asOf = null, int $flatDays = self::FLAT_DAYS, float $bufferPct = self::BUFFER_PCT): array
    {
        $asOf = $asOf ?: Carbon::now();
        $bySup = PayablesCalculator::unpaidSlicesBySupplier($t->id);
        if (! $bySup) { return []; }
        $fx = Fx::forTenant($t);
        $suppliers = Supplier::where('tenant_id', $t->id)->whereIn('id', array_keys($bySup))->get()->keyBy('id');
        $out = [];
        foreach ($bySup as $sid => $slices) {
            $s = $suppliers->get($sid);
            if (! $s) { continue; }
            $a = $this->buildFromSlices($s, $slices, $asOf, $flatDays, $bufferPct, $fx);
            if ($a) { $out[] = $a; }
        }
        usort($out, fn ($x, $y) => ($y['oldest_days'] <=> $x['oldest_days']) ?: ($y['overdue'] <=> $x['overdue']));
        return $out;
    }

    private function buildFromSlices(Supplier $s, array $slices, Carbon $asOf, int $flatDays, float $bufferPct, ?Fx $fx = null): ?array
    {
        if (! $slices) { return null; }
        $term = (int) ($s->pay_term_days ?: 0);
        $thr = self::thresholdDays($term ?: null, $flatDays);

        $balance = 0.0; $overdue = 0.0; $oldest = 0; $curTally = [];
        foreach ($slices as $sl) {
            $native = (float) ($sl['native'] ?? 0);
            $balance += $native;
            $ccy = strtoupper((string) ($sl['currency'] ?? 'USD'));
            $curTally[$ccy] = ($curTally[$ccy] ?? 0) + 1;
            $age = self::ageDays($sl['at'] ?? null, $asOf);
            if ($age > $oldest) { $oldest = $age; }
            if ($age >= $thr) { $overdue += $native; }
        }
        arsort($curTally);
        $cur = (string) (array_key_first($curTally) ?: 'USD');
        if (round($balance, 2) <= 0.005) { return null; }

        // Current market rate for the USD-equivalent bracket ("≈ $X"). Uses the tenant's live Fx rate
        // (the one the owner maintains), not each bill's historical rate — this is "pay in USD today".
        $rate = ($cur === 'USD') ? 1.0 : ($fx ? (float) $fx->ratePerUsd($cur) : 0.0);

        $d = self::decide($balance, $overdue, $bufferPct);
        return array_merge($d, [
            'supplier_id' => (int) $s->id,
            'supplier' => (string) ($s->business_name ?: $s->name),
            'currency' => $cur,
            'rate' => $rate,
            'balance_usd' => $rate > 0 ? round($d['balance'] / $rate, 2) : null,
            'overdue_usd' => $rate > 0 ? round($d['overdue'] / $rate, 2) : null,
            'recommend_usd' => $rate > 0 ? round($d['recommend'] / $rate, 2) : null,
            'term_days' => $term,
            'threshold_days' => $thr,
            'oldest_days' => (int) $oldest,
        ]);
    }

    /** " (≈ $718)" — a USD-equivalent bracket at the current rate; empty for USD or an unknown rate. */
    public static function usdBracket(float $native, string $cur, float $rate): string
    {
        if (strtoupper($cur) === 'USD' || $rate <= 0) { return ''; }
        return ' (≈ $' . number_format(round($native / $rate), 0) . ')';
    }

    /** Money in a supplier's currency: "$1,234.00" for USD, "UGX 1,650,000" (whole) otherwise.
     *  Native currencies here (UGX/SSP) have no sub-units in practice, so they round to whole — a
     *  partially-paid supplier's proportional remainder must not surface stray "UGX …365.14" cents. */
    public static function money(float $v, string $cur): string
    {
        $cur = strtoupper($cur ?: 'USD');
        if ($cur === 'USD') { return '$' . number_format($v, 2); }
        return $cur . ' ' . number_format(round($v), 0);
    }

    /** A short one-line verdict for the panel / next-best-action. */
    public static function line(array $a): string
    {
        $cur = (string) $a['currency'];
        $rate = (float) ($a['rate'] ?? 0);
        $pay = self::money($a['overdue'], $cur) . self::usdBracket((float) $a['overdue'], $cur, $rate);
        if ($a['state'] === 'pay') {
            return 'Pay ' . $pay . ' — ' . $a['oldest_days'] . ' days overdue';
        }
        if ($a['state'] === 'tension') {
            return 'Pay ' . $pay . ' (' . $a['oldest_days'] . ' days overdue) — this drops you to '
                . $a['pending_pct'] . '% pending, below your buffer';
        }
        if ($a['state'] === 'hold') {
            return 'Hold — nothing past ' . $a['threshold_days'] . ' days (owe ' . self::money($a['balance'], $cur) . ')';
        }
        return 'Nothing owed';
    }

    /** WhatsApp digest for the owner — only suppliers that need action. Empty string = nothing to send. */
    public function digestText(Tenant $t, array $advisories): string
    {
        $act = array_values(array_filter($advisories, fn ($a) => in_array($a['state'], ['pay', 'tension'], true) && $a['overdue'] > 0.005));
        if (! $act) { return ''; }

        $lines = [];
        foreach ($act as $a) {
            $cur = (string) $a['currency'];
            $rate = (float) ($a['rate'] ?? 0);
            $flag = $a['state'] === 'tension' ? '  ⚠ below 50% buffer' : '';
            $lines[] = '• ' . $a['supplier'] . ': pay ' . self::money($a['overdue'], $cur) . self::usdBracket((float) $a['overdue'], $cur, $rate)
                . ' (' . $a['oldest_days'] . 'd overdue, owe ' . self::money($a['balance'], $cur) . ')' . $flag;
        }

        $head = ($t->name ?: 'ShopBot') . ' — supplier payments due (' . count($act) . ')';
        $foot = "Pay in the app · advisory only — nothing has been paid.";
        return $head . "\n\n" . implode("\n", $lines) . "\n\n" . $foot;
    }
}
