<?php declare(strict_types=1);

namespace App\Services\Growth;

use App\Models\CustomerPayment;
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
 *
 * ── v4.61 — the value column is money COLLECTED, not money ordered ──────────────────────────────
 *
 * The two questions are not the same, and only one of them is a fact. A customer who ordered
 * USD 7,836 and paid USD 3,000 has given the shop USD 3,000; the rest is a hope with an invoice
 * attached. Ranking "who is worth a call" by what was ordered rewards the customer who takes stock
 * and does not pay, which is precisely backwards — that person is a collections problem, and there
 * is a screen for them.
 *
 * This is the same rule v4.57 applied to quotations: a quote is not won when it becomes an order,
 * it is won when the money arrives. It now applies here too, and to the "Value at risk" total, which
 * previously answered "what might we stop invoicing" rather than "what might we stop banking".
 *
 * CADENCE STILL COMES FROM ORDERS. A customer's rhythm is when they take stock, not when the cash
 * happens to clear — those drift apart on credit terms and would make a reliable weekly buyer look
 * erratic.
 */
final class GrowthSignalsService
{
    /** Cadence is read from recent behaviour — what a customer did in 2024 is not their rhythm now. */
    private const HISTORY_DAYS = 730;

    /** Collected value is trailing-year, so a customer who paid well in 2023 does not outrank one paying now. */
    private const VALUE_DAYS = 365;

    /** An order in any of these states never happened, commercially. */
    private const DEAD = ['cancelled', 'canceled', 'rejected', 'draft', 'void'];

    /**
     * @return array{
     *   rows: array<int,array>, counts: array<string,int>,
     *   unpriced: int, unpaid: int, generated_at: string
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
            ->get(['customer_phone', 'customer_name', 'created_at']);

        $byPhone = [];

        // ── pass one: the rhythm, from orders ──────────────────────────────────────────────────
        foreach ($orders as $o) {
            $digits = preg_replace('/[^0-9]/', '', (string) $o->customer_phone);
            if ($digits === '') {
                continue;                      // a walk-in with no number is not a customer we can chase
            }
            $day = (int) floor($o->created_at->getTimestamp() / 86400);
            if ($day < $fromDay) {
                continue;
            }

            $byPhone[$digits] ??= ['days' => [], 'value' => 0.0, 'name' => '', 'unpriced' => 0, 'ordered' => false];
            $byPhone[$digits]['days'][] = $day;
            if ($byPhone[$digits]['name'] === '' && trim((string) $o->customer_name) !== '') {
                $byPhone[$digits]['name'] = trim((string) $o->customer_name);
            }
            if ($day >= $valFrom) {
                // Remembered only so the screen can say how many customers ordered in the window and
                // have no payment recorded against them at all — see $unpaid below.
                $byPhone[$digits]['ordered'] = true;
            }
        }

        // ── pass two: the money, from receipts ─────────────────────────────────────────────────
        // Every payment path in this system — the Payments screen, the invoice button, the order
        // button, staff cash and the POS — records through the one PaymentService, so this table is
        // the whole of what the shop has actually banked. Both kinds count: a settlement and an
        // advance are the same handover of cash, differing only in what it has been allocated to.
        $payments = CustomerPayment::withoutGlobalScopes()
            ->where('tenant_id', $t->id)
            ->where('created_at', '>=', now()->subDays(self::VALUE_DAYS))
            ->get(['customer_phone', 'amount', 'currency', 'usd_amount', 'created_at']);

        // Mapped to plain arrays and handed to CollectedValue, which does the deciding. Same division
        // of labour as ReorderSignals: everything worth testing lives in the half that needs no
        // database, and this class stays the part that only knows how to fetch.
        $receipts = [];
        foreach ($payments as $p) {
            $receipts[] = [
                'phone'    => preg_replace('/[^0-9]/', '', (string) $p->customer_phone),
                'stamped'  => $p->usd_amount === null ? null : (float) $p->usd_amount,
                'currency' => (string) ($p->currency ?? ''),
                'amount'   => (float) $p->amount,
                'day'      => (int) floor($p->created_at->getTimestamp() / 86400),
            ];
        }
        [$byPhone, $unpriced] = CollectedValue::apply($byPhone, $receipts, $valFrom);
        $unpaid = CollectedValue::unpaid($byPhone);

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
            'unpaid' => $unpaid,
            'generated_at' => now()->toDateTimeString(),
        ];
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

    /**
     * Drop the cache. Called from PaymentService when a receipt is recorded — since v4.61 the value
     * on this screen is money collected, so a payment is what moves it — and from the screen's own
     * Refresh button.
     */
    public static function forget(int $tenantId): void
    {
        try { Cache::forget('growth_signals:' . $tenantId); } catch (\Throwable $e) {}
    }
}
