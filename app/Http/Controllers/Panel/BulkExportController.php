<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\CustomerPayment;
use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\Product;
use App\Models\Quotation;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * One export engine for every list (UCRM-style bulk, 2026-07-18).
 * GET /papi/export/{entity}?ids=1,2,3&format=csv|pdf
 * CSV: UTF-8 BOM (Arabic-safe in Excel). PDF: landscape A4 with totals.
 * Adding a new exportable list = one more match arm here + two lines in its capability.
 */
class BulkExportController extends Controller
{
    public function export(Request $r, string $entity)
    {
        $t = $r->user()->tenant;
        if (! $t) abort(401);

        $spec = $this->spec($entity);
        if (! $spec) abort(404, 'Unknown export entity');

        $ids = array_filter(array_map('intval', explode(',', (string) $r->query('ids', ''))));
        $q = $spec['query']($t->id);
        if ($ids) $q->whereIn('id', $ids);
        $rows = $q->limit(2000)->get();
        if ($rows->isEmpty()) abort(404, 'Nothing selected');

        $cols = $spec['cols'];
        $title = $spec['title'];
        $file = $title.'-'.now()->format('Ymd-Hi');

        if ($r->query('format') === 'pdf') {
            if (! class_exists(\Dompdf\Dompdf::class)) abort(503, 'PDF engine unavailable');
            $th = '';
            foreach ($cols as $c) $th .= '<th'.(! empty($c['num']) ? ' class="r"' : '').'>'.e($c['h']).'</th>';
            $body = '';
            foreach ($rows as $row) {
                $body .= '<tr>';
                foreach ($cols as $c) {
                    $v = $c['v']($row);
                    $body .= '<td'.(! empty($c['num']) ? ' class="r"' : '').'>'
                        .(! empty($c['num']) ? number_format((float) $v, 2) : e((string) $v)).'</td>';
                }
                $body .= '</tr>';
            }
            $tf = '<tr><td colspan="'.count($cols).'">'.count($rows).' record(s)';
            foreach ($cols as $c) {
                if (! empty($c['sum'])) {
                    $tf .= ' · '.e($c['h']).': '.number_format((float) $rows->sum(fn ($x) => (float) $c['v']($x)), 2);
                }
            }
            $tf .= '</td></tr>';
            $html = '<html><head><style>body{font-family:DejaVu Sans,sans-serif;font-size:10px;color:#111}'
                .'h1{font-size:16px;margin:0 0 2px}.sub{color:#666;margin-bottom:12px}'
                .'table{width:100%;border-collapse:collapse}th{background:#f1f5f9;text-align:left}'
                .'th,td{border:1px solid #e2e8f0;padding:5px 7px}.r{text-align:right}tfoot td{font-weight:bold;background:#f8fafc}</style></head><body>'
                .'<h1>'.e($t->name).' — '.e($title).'</h1><div class="sub">exported '.now()->format('d M Y H:i').'</div>'
                .'<table><thead><tr>'.$th.'</tr></thead><tbody>'.$body.'</tbody><tfoot>'.$tf.'</tfoot></table></body></html>';
            $pdf = new \Dompdf\Dompdf();
            $pdf->loadHtml($html);
            $pdf->setPaper('A4', 'landscape');
            $pdf->render();

            return response($pdf->output(), 200, ['Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$file.'.pdf"']);
        }

        $csvq = fn ($v) => '"'.str_replace('"', '""', (string) $v).'"';
        $out = "\xEF\xBB\xBF".implode(',', array_map(fn ($c) => $csvq($c['h']), $cols))."\n";
        foreach ($rows as $row) {
            $out .= implode(',', array_map(function ($c) use ($row, $csvq) {
                $v = $c['v']($row);

                return ! empty($c['num']) ? (float) $v : $csvq($v);
            }, $cols))."\n";
        }

        return response($out, 200, ['Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$file.'.csv"']);
    }

    private function spec(string $entity): ?array
    {
        $d = fn ($v, $f = 'Y-m-d') => $v ? Carbon::parse($v)->format($f) : '';

        return match ($entity) {
            'quotations' => ['title' => 'Quotations',
                'query' => fn ($tid) => Quotation::where('tenant_id', $tid)->orderByDesc('created_at'),
                'cols' => [
                    ['h' => 'Quote #', 'v' => fn ($x) => $x->quote_no],
                    ['h' => 'Customer', 'v' => fn ($x) => $x->customer_name],
                    ['h' => 'Phone', 'v' => fn ($x) => $x->customer_phone],
                    ['h' => 'Currency', 'v' => fn ($x) => $x->currency],
                    ['h' => 'Total', 'v' => fn ($x) => $x->total, 'num' => 1, 'sum' => 1],
                    ['h' => 'Status', 'v' => fn ($x) => $x->status],
                    ['h' => 'Converted to order', 'v' => fn ($x) => $x->order_id ? 'yes' : ''],
                    ['h' => 'Valid until', 'v' => fn ($x) => $d($x->valid_until)],
                    ['h' => 'Created', 'v' => fn ($x) => $d($x->created_at)],
                ]],
            'customers' => ['title' => 'Customers',
                // v4.60 — the sheet is titled Customers, so it contains customers. Discovered
                // businesses get their own export when they get their own screen.
                'query' => fn ($tid) => \App\Services\Crm\BusinessStage::scope(
                    CustomerProfile::withoutGlobalScopes()->where('tenant_id', $tid)
                )->orderBy('name'),
                'cols' => [
                    ['h' => 'Name', 'v' => fn ($x) => $x->name],
                    ['h' => 'Phone', 'v' => fn ($x) => $x->phone],
                    ['h' => 'Segment', 'v' => fn ($x) => $x->segment],
                    ['h' => 'City', 'v' => fn ($x) => $x->city],
                    ['h' => 'Stage', 'v' => fn ($x) => $x->stage],
                    ['h' => 'Health', 'v' => fn ($x) => $x->health],
                    ['h' => 'Flags', 'v' => fn ($x) => implode(' ', (array) ($x->health_flags ?? []))],
                    ['h' => 'Assigned rep', 'v' => fn ($x) => $x->assigned_rep],
                    ['h' => 'Last contacted', 'v' => fn ($x) => $d($x->last_contacted_at)],
                ]],
            'orders' => ['title' => 'Orders',
                'query' => fn ($tid) => Order::withoutGlobalScopes()->where('tenant_id', $tid)->orderByDesc('created_at'),
                'cols' => [
                    ['h' => 'Order #', 'v' => fn ($x) => $x->order_no],
                    ['h' => 'Customer', 'v' => fn ($x) => $x->customer_name],
                    ['h' => 'Phone', 'v' => fn ($x) => $x->customer_phone],
                    ['h' => 'Currency', 'v' => fn ($x) => $x->sell_currency],
                    ['h' => 'Total', 'v' => fn ($x) => $x->total, 'num' => 1, 'sum' => 1],
                    ['h' => 'Paid', 'v' => fn ($x) => $x->amount_paid, 'num' => 1, 'sum' => 1],
                    ['h' => 'Status', 'v' => fn ($x) => $x->status],
                    ['h' => 'Channel', 'v' => fn ($x) => $x->channel],
                    ['h' => 'Created', 'v' => fn ($x) => $d($x->created_at, 'Y-m-d H:i')],
                ]],
            'payments' => ['title' => 'Payments',
                'query' => fn ($tid) => CustomerPayment::where('tenant_id', $tid)->orderByDesc('created_at'),
                'cols' => [
                    ['h' => 'Receipt #', 'v' => fn ($x) => $x->receipt_no],
                    ['h' => 'Date', 'v' => fn ($x) => $d($x->created_at, 'Y-m-d H:i')],
                    ['h' => 'Customer', 'v' => fn ($x) => $x->customer_name],
                    ['h' => 'Phone', 'v' => fn ($x) => $x->customer_phone],
                    ['h' => 'Order / Invoice', 'v' => fn ($x) => trim(($x->order_no ?: '').' '.($x->invoice_no ?: ''))],
                    ['h' => 'Method', 'v' => fn ($x) => $x->method],
                    ['h' => 'Currency', 'v' => fn ($x) => $x->currency],
                    ['h' => 'Amount', 'v' => fn ($x) => $x->amount, 'num' => 1, 'sum' => 1],
                    ['h' => 'USD amount', 'v' => fn ($x) => $x->usd_amount, 'num' => 1, 'sum' => 1],
                    ['h' => 'Reference', 'v' => fn ($x) => $x->reference],
                ]],
            'products' => ['title' => 'Products',
                'query' => fn ($tid) => Product::withoutGlobalScopes()->where('tenant_id', $tid)->orderBy('category')->orderBy('name'),
                'cols' => [
                    ['h' => 'Name', 'v' => fn ($x) => $x->name],
                    ['h' => 'SKU', 'v' => fn ($x) => $x->sku],
                    ['h' => 'Category', 'v' => fn ($x) => $x->category],
                    ['h' => 'Unit', 'v' => fn ($x) => $x->unit_label],
                    ['h' => 'MOQ', 'v' => fn ($x) => $x->moq],
                    ['h' => 'Price', 'v' => fn ($x) => $x->price, 'num' => 1],
                    ['h' => 'Cost', 'v' => fn ($x) => $x->cost_price, 'num' => 1],
                    ['h' => 'Active', 'v' => fn ($x) => $x->active ? 'yes' : 'no'],
                ]],
            default => null,
        };
    }
}
