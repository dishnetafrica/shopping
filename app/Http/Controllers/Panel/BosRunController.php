<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Guided Collect Run. GET /papi/bos-run — today's collection round as an ordered list of stops, each
 * carrying the exact payload the offline-safe collect uses (order_no, invoice_no, currency, amount).
 * Ordered overdue → due → upcoming, then by amount. Pure read; the customer settles via /papi/payments.
 */
class BosRunController extends Controller
{
    public function show(Request $request)
    {
        $t = $request->user()?->tenant;
        if (! $t) return response()->json(['ok' => false], 401);
        $tid = (int) $t->id;

        try {
            $today = now();

            $invs = Invoice::where('tenant_id', $tid)
                ->whereIn('status', ['issued', 'part_paid'])->where('balance', '>', 0.5)
                ->get(['invoice_no', 'order_id', 'customer_name', 'customer_phone', 'currency', 'balance', 'due_at']);

            // One stop per customer; the "primary" (most-overdue) invoice drives the collect payload.
            $byCust = [];
            foreach ($invs as $i) {
                $key = (string) ($i->customer_phone ?: $i->customer_name);
                if ($key === '') continue;
                if (! isset($byCust[$key])) {
                    $byCust[$key] = ['name' => (string) $i->customer_name, 'phone' => (string) $i->customer_phone,
                        'amount' => 0.0, 'due' => null, 'inv' => (string) $i->invoice_no,
                        'order_id' => $i->order_id, 'currency' => (string) ($i->currency ?: 'USD')];
                }
                $byCust[$key]['amount'] += (float) $i->balance;
                $due = $i->due_at ? (string) $i->due_at : null;
                if ($due !== null && ($byCust[$key]['due'] === null || $due < $byCust[$key]['due'])) {
                    $byCust[$key]['due'] = $due;
                    $byCust[$key]['inv'] = (string) $i->invoice_no;
                    $byCust[$key]['order_id'] = $i->order_id;
                    $byCust[$key]['currency'] = (string) ($i->currency ?: 'USD');
                }
            }
            if (empty($byCust)) {
                return response()->json(['ok' => true, 'stops' => [], 'total' => 0, 'count' => 0, 'minutes' => 0, 'currency' => 'USD']);
            }

            // Resolve order_no + customer id/city in bulk.
            $orderIds = array_values(array_filter(array_map(fn ($c) => $c['order_id'], $byCust)));
            $orders = $orderIds ? Order::where('tenant_id', $tid)->whereIn('id', $orderIds)->pluck('order_no', 'id') : collect();
            $phones = array_values(array_filter(array_map(fn ($c) => preg_replace('/\D/', '', (string) $c['phone']), $byCust)));
            $profiles = $phones ? CustomerProfile::where('tenant_id', $tid)->whereIn('phone', $phones)
                ->get(['id', 'phone', 'city'])->keyBy('phone') : collect();

            $stops = []; $total = 0.0;
            foreach ($byCust as $c) {
                $amount = round($c['amount'], 2);
                $total += $amount;
                $digits = preg_replace('/\D/', '', (string) $c['phone']);
                $prof = $profiles->get($digits);
                [$status, $dueLabel, $rank] = $this->dueMeta($c['due'], $today);
                $stops[] = [
                    'customer_id' => (int) ($prof->id ?? 0),
                    'name'        => $c['name'] !== '' ? $c['name'] : 'Customer',
                    'phone'       => (string) $c['phone'],
                    'city'        => (string) ($prof->city ?? ''),
                    'amount'      => $amount,
                    'currency'    => $c['currency'] ?: 'USD',
                    'invoice_no'  => (string) $c['inv'],
                    'order_no'    => (string) ($orders[$c['order_id']] ?? ''),
                    'status'      => $status,       // overdue | due | ok
                    'due_label'   => $dueLabel,
                    '_rank'       => $rank,
                ];
            }

            // Overdue first (rank 0), then due today (1), then upcoming (2); within a tier, biggest amount first.
            usort($stops, function ($a, $b) {
                if ($a['_rank'] !== $b['_rank']) return $a['_rank'] <=> $b['_rank'];
                return $b['amount'] <=> $a['amount'];
            });
            foreach ($stops as &$s) unset($s['_rank']);

            return response()->json([
                'ok'       => true,
                'stops'    => $stops,
                'count'    => count($stops),
                'total'    => round($total, 2),
                'minutes'  => count($stops) * 10,   // rough estimate; route timing is a future enhancement
                'currency' => 'USD',
            ]);
        } catch (\Throwable $e) {
            \Log::warning('bos-run failed: ' . $e->getMessage());
            return response()->json(['ok' => false, 'error' => 'unavailable'], 200);
        }
    }

    /** @return array{0:string,1:string,2:int} [status, label, rank] */
    private function dueMeta(?string $due, Carbon $today): array
    {
        if (! $due) return ['ok', '', 2];
        try { $d = Carbon::parse($due)->startOfDay(); }
        catch (\Throwable $e) { return ['ok', '', 2]; }
        $days = $today->copy()->startOfDay()->diffInDays($d, false);
        if ($days < 0)   return ['overdue', abs($days) . ' day' . (abs($days) === 1 ? '' : 's') . ' overdue', 0];
        if ($days === 0) return ['due', 'due today', 1];
        return ['ok', 'due in ' . $days . ' day' . ($days === 1 ? '' : 's'), 2];
    }
}
