<?php declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Services\Money\Fx;
use App\Support\Table\ListBuilder;
use App\Support\Table\ListQuery;
use Illuminate\Http\Request;

/**
 * Server list endpoints for the registers retrofitted onto the shared DataTable (Roadmap E8.4).
 * Each returns the ONE list contract (E8.1: rows/total/pages/aggregates) built by ListBuilder, so
 * the JS DataTable (E8.3) renders them identically. These are NEW endpoints (the legacy /papi/invoices
 * and /papi/payments are untouched), so adding them changes no existing screen; the screens consume
 * them only when table_v2 is on.
 */
class RegisterTableController extends Controller
{
    private function allowed(Request $r): bool
    {
        $u = $r->user();
        if (! $u || ! $u->tenant_id) return false;
        return $u->isOwnerLike() || in_array((string) $u->role, ['manager', 'accountant'], true);
    }

    /** GET /papi/invoices/table */
    public function invoices(Request $r)
    {
        if (! $this->allowed($r)) return response()->json(['ok' => false, 'error' => 'not_allowed'], 403);
        $t = $r->user()->tenant;
        $fx = Fx::forTenant($t);

        $lq = ListQuery::fromRequest($r);
        $result = ListBuilder::apply(
            Invoice::where('tenant_id', $t->id),
            $lq,
            [
                'search'       => ['invoice_no', 'customer_name'],
                'filters'      => ['status' => 'status'],
                'sortable'     => ['invoice_no' => 'invoice_no', 'total' => 'total', 'balance' => 'balance',
                                   'status' => 'status', 'issued_at' => 'issued_at'],
                'default_sort' => ['column' => 'issued_at', 'dir' => 'desc'],
                'map'          => function (Invoice $inv) use ($fx) {
                    $cur = (string) ($inv->currency ?: 'USD');
                    return [
                        'invoice_no'  => (string) $inv->invoice_no,
                        'customer'    => (string) ($inv->customer_name ?? ''),
                        'currency'    => $cur,
                        'total'       => round((float) $inv->total, 2),
                        'total_usd'   => $fx->toUsd((float) $inv->total, $cur),
                        'balance_usd' => $fx->toUsd((float) $inv->balance, $cur),
                        'status'      => (string) $inv->status,
                        'issued_at'   => optional($inv->issued_at)->format('Y-m-d'),
                    ];
                },
            ]
        );

        return response()->json(['ok' => true] + $result->toArray());
    }

    /** GET /papi/payments/table */
    public function payments(Request $r)
    {
        if (! $this->allowed($r)) return response()->json(['ok' => false, 'error' => 'not_allowed'], 403);
        $t = $r->user()->tenant;

        $lq = ListQuery::fromRequest($r);
        $result = ListBuilder::apply(
            CustomerPayment::where('tenant_id', $t->id),
            $lq,
            [
                'search'       => ['receipt_no', 'customer_name', 'reference'],
                'filters'      => ['kind' => 'kind', 'method' => 'method'],
                'sortable'     => ['receipt_no' => 'receipt_no', 'amount' => 'amount',
                                   'usd_amount' => 'usd_amount', 'created_at' => 'created_at'],
                'default_sort' => ['column' => 'created_at', 'dir' => 'desc'],
                // usd_amount is already USD across rows, so summing it is meaningful
                'aggregates'   => ['total_usd' => ['fn' => 'sum', 'column' => 'usd_amount']],
                'map'          => function (CustomerPayment $p) {
                    return [
                        'receipt_no' => (string) $p->receipt_no,
                        'customer'   => (string) ($p->customer_name ?? ''),
                        'currency'   => (string) ($p->currency ?? ''),
                        'amount'     => round((float) $p->amount, 2),
                        'usd_amount' => round((float) $p->usd_amount, 2),
                        'method'     => (string) ($p->method ?? ''),
                        'kind'       => (string) ($p->kind ?? ''),
                        'order_no'   => (string) ($p->order_no ?? ''),
                        'invoice_no' => (string) ($p->invoice_no ?? ''),
                        'created_at' => optional($p->created_at)->format('Y-m-d H:i'),
                    ];
                },
            ]
        );

        return response()->json(['ok' => true] + $result->toArray());
    }
}
