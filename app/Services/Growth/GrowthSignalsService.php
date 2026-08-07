<?php declare(strict_types=1);

namespace App\Services\Growth;

use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;

/**
 * v4.55 — the reorder signals, assembled from orders this shop already has.
 *
 * Milestone 8 of the Sales Intelligence plan, built first because it needs no migration, no
 * provider, no map and no external source. Every number below comes out of the order book.
 *
 * The division of labour is deliberate: ReorderSignals is pure arithmetic and knows nothing about
 * this system; this class knows about orders, currencies and tenants, and does no arithmetic worth
 * testing. Everything hard to get right lives in the half that can be tested without a database.
 */
final class GrowthSignalsService
{
    /** Cadence is read from recent behaviour — what a customer did in 2024 is not their rhythm now. */
    private const HISTORY_DAYS = 730;

    /** Value at risk is trailing-year spend, so a customer who was big in 2023 does not outrank one who is big now. */
    private const VALUE_DAYS = 365;

    /** An order in any of these states never happened, commercially. */
    private const DEAD = ['cancelled', 'canceled', 'rejected', 'draft', 'void'];

    /**
     * @return array{
     *   rows: array<int,array>, counts: array<string,int>,
     *   unpriced: int, generated_at: string
     * }
     */
    public function forTenant(Tenant $t, bool $fresh = false): array
    {
        $key = 'growth_signals:' . $t->id;
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, 900, fn () => $this->build($t));
    }

    private function build(Tenant $t): array
    {
        $today   = (int) floor(now()->getTimestamp() / 86400);
        $fromDay = $today - self::HISTORY_DAYS;
        $valFrom = $today - self::VALUE_DAYS;

        // withoutGlobalScopes + an explicit tenant_id: this runs from a console command as well as a
        // request, and in console the tenant scope resolves to NULL and silently returns nothing.
        $orders = Order::withoutGlobalScopes()
            ->where('tenant_id', $t->id)
            ->whereNotIn('status', self::DEAD)
            ->where('created_at', '>=', now()->subDays(self::HISTORY_DAYS))
            ->get(['customer_phone', 'customer_name', 'total', 'usd_amount', 'sell_currency', 'created_at']);

        $byPhone = [];
        $unpriced = 0;

        foreach ($orders as $o) {
            $digits = preg_replace('/[^0-9]/', '', (string) $o->customer_phone);
            if ($digits === '') {
                continue;                      // a walk-in with no number is not a customer we can chase
            }
            $day = (int) floor($o->created_at->getTimestamp() / 86400);
            if ($day < $fromDay) {
                continue;
            }

            $byPhone[$digits] ??= ['days' => [], 'value' => 0.0, 'name' => '', 'unpriced' => 0];
            $byPhone[$digits]['days'][] = $day;
            if ($byPhone[$digits]['name'] === '' && trim((string) $o->customer_name) !== '') {
                $byPhone[$digits]['name'] = trim((string) $o->customer_name);
            }

            // Value only counts what can be stated in ONE currency. An order whose USD equivalent was
            // never stamped is still a real order for cadence — the date happened — but adding its
            // native total to a USD figure would quietly inflate every ranking on the page.
            if ($day >= $valFrom) {
                $usd = $this->usd($o);
                if ($usd === null) {
                    $unpriced++;
                    $byPhone[$digits]['unpriced']++;
                } else {
                    $byPhone[$digits]['value'] += $usd;
                }
            }
        }

        $names = $this->names($t, array_keys($byPhone));

        $rows = [];
        foreach ($byPhone as $digits => $c) {
            $signal = ReorderSignals::forCustomer($c['days'], $c['value'], $today);
            $rows[] = $signal + [
                'phone'    => $digits,
                'name'     => $names[$digits]['name'] ?? ($c['name'] ?: $digits),
                'id'       => $names[$digits]['id'] ?? null,
                'unpriced' => $c['unpriced'],
                'priority' => ReorderSignals::priority($signal),
            ];
        }

        usort($rows, fn ($a, $b) => [$b['priority'], $b['value']] <=> [$a['priority'], $a['value']]);

        $counts = ['due' => 0, 'overdue' => 0, 'lapsed' => 0, 'healthy' => 0, 'too_new' => 0];
        foreach ($rows as $r) {
            $counts[$r['state']] = ($counts[$r['state']] ?? 0) + 1;
        }

        return [
            'rows' => $rows,
            'counts' => $counts,
            'unpriced' => $unpriced,
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    /** The order's value in base currency, or null when it cannot be stated in one. */
    private function usd(Order $o): ?float
    {
        $stamped = $o->usd_amount !== null ? (float) $o->usd_amount : 0.0;
        if ($stamped > 0) {
            return $stamped;
        }
        // Not stamped: only safe when the order was already in base currency.
        $cur = strtoupper(trim((string) ($o->sell_currency ?? '')));
        if ($cur === '' || $cur === 'USD') {
            return (float) $o->total;
        }

        return null;
    }

    /**
     * Profile name and id per phone, so a row links to the customer record. One query, not one each.
     *
     * @param  array<int,string>  $digits
     * @return array<string,array{name:string,id:int}>
     */
    private function names(Tenant $t, array $digits): array
    {
        if (! $digits) {
            return [];
        }
        $out = [];
        CustomerProfile::withoutGlobalScopes()
            ->where('tenant_id', $t->id)
            ->get(['id', 'name', 'phone'])
            ->each(function ($c) use (&$out) {
                $d = preg_replace('/[^0-9]/', '', (string) $c->phone);
                if ($d !== '' && ! isset($out[$d])) {
                    $out[$d] = ['name' => trim((string) $c->name) ?: $d, 'id' => (int) $c->id];
                }
            });

        return $out;
    }

    /** Drop the cache — called after an order lands, so the list is never a quarter-hour stale. */
    public static function forget(int $tenantId): void
    {
        try { Cache::forget('growth_signals:' . $tenantId); } catch (\Throwable $e) {}
    }
}
