<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Modern Customers list. GET /papi/bos-customers — every customer with live outstanding, overdue flag,
 * and last-order date, ranked overdue → owes → settled. Pure read; three grouped queries (no N+1).
 */
class BosCustomersController extends Controller
{
    public function show(Request $request)
    {
        $t = $request->user()?->tenant;
        if (! $t) return response()->json(['ok' => false], 401);
        $tid = (int) $t->id;

        try {
            $today = now()->toDateString();

            // Outstanding balance + earliest due date, per customer phone (one query).
            $out = Invoice::where('tenant_id', $tid)->where('status', '!=', 'void')
                ->where('balance', '>', 0.005)
                ->selectRaw('customer_phone, SUM(balance) as bal, MIN(due_at) as first_due')
                ->groupBy('customer_phone')->get()->keyBy('customer_phone');

            // Last order date, per customer phone (one query).
            $last = Order::where('tenant_id', $tid)
                ->selectRaw('customer_phone, MAX(created_at) as last_at')
                ->groupBy('customer_phone')->get()->keyBy('customer_phone');

            $profiles = \App\Services\Crm\BusinessStage::scope(CustomerProfile::where('tenant_id', $tid))
                ->get(['id', 'name', 'phone', 'city']);

            $rows = []; $owes = 0; $overdue = 0;
            foreach ($profiles as $p) {
                $ph = (string) $p->phone;
                $o  = $out->get($ph);
                $bal = $o ? (float) $o->bal : 0.0;
                $od = (bool) ($o && $o->first_due && (string) $o->first_due < $today);
                $status = $bal > 0.005 ? ($od ? 'overdue' : 'owes') : 'ok';
                if ($bal > 0.005) $owes++;
                if ($od) $overdue++;
                $lo = $last->get($ph);
                $rows[] = [
                    'id'          => (int) $p->id,
                    'name'        => (string) ($p->name ?: 'Customer'),
                    'city'        => (string) ($p->city ?? ''),
                    'phone'       => $ph,
                    'outstanding' => round($bal, 2),
                    'status'      => $status,
                    'last_order'  => ($lo && $lo->last_at) ? Carbon::parse($lo->last_at)->format('d M') : null,
                ];
            }

            // Rank: overdue first, then owing, then settled; within a tier by amount desc, then name.
            usort($rows, function ($a, $b) {
                $rank = ['overdue' => 0, 'owes' => 1, 'ok' => 2];
                if ($rank[$a['status']] !== $rank[$b['status']]) return $rank[$a['status']] <=> $rank[$b['status']];
                if ($a['outstanding'] != $b['outstanding']) return $b['outstanding'] <=> $a['outstanding'];
                return strcasecmp($a['name'], $b['name']);
            });

            return response()->json([
                'ok'     => true,
                'counts' => ['all' => count($rows), 'owes' => $owes, 'overdue' => $overdue],
                'customers' => $rows,
            ]);
        } catch (\Throwable $e) {
            \Log::warning('bos-customers failed: ' . $e->getMessage());
            return response()->json(['ok' => false, 'error' => 'unavailable'], 200);
        }
    }
}
