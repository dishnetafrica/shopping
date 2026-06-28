<?php
namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\Tenant;
use App\Support\OrderSla;
use App\Support\TenantContext;
use App\Support\Vertical;
use Illuminate\Http\Request;

/**
 * Login-free, token-credentialed read-only list of orders needing attention — the page the twice-daily
 * digest links to (/papi/pending?t=<id>&k=<token>). Owners tap from WhatsApp, see the whole pipeline
 * with ages and overdue flags, then update statuses in the panel. Read-only: no mutations here.
 */
class PendingController extends Controller
{
    public function show(Request $request)
    {
        $tid = (int) $request->query('t', 0);
        $key = (string) $request->query('k', '');
        $t   = $tid ? Tenant::find($tid) : null;
        if (! $t || $key === '' || ! hash_equals((string) $t->setting('pending_token', ''), $key)) {
            abort(404);
        }
        app(TenantContext::class)->set($t->id);

        $vertical  = Vertical::of($t);
        $overrides = (array) $t->setting('order_sla', []);
        $curDefault = (string) $t->setting('currency', 'UGX');

        $open = Order::withoutGlobalScopes()
            ->where('tenant_id', $t->id)
            ->whereIn('status', OrderSla::ACTIVE)
            ->orderBy('status_changed_at')
            ->limit(500)->get()
            ->reject(fn ($o) => OrderSla::isDispatch((string) $o->status)
                && Shipment::withoutGlobalScopes()->where('order_id', $o->id)->exists());

        $rows = '';
        foreach ($open as $o) {
            $since   = $o->status_changed_at ?: $o->created_at;
            $overdue = $since && $since->lt(now()->subMinutes(OrderSla::staleMinutes($vertical, (string) $o->status, $overrides)));
            $age     = $since ? $since->diffForHumans(now(), ['parts' => 1, 'short' => true]) : '—';
            $cur     = (string) ($o->currency ?: $curDefault);
            $amt     = $cur === 'USD' ? number_format((float) $o->total, 2) : number_format((float) $o->total);
            $who     = e((string) ($o->customer_name ?: $o->customer_phone ?: '—'));
            $badge   = $overdue ? '<span class="b od">overdue</span>' : '<span class="b ok">open</span>';
            $rows .= '<tr class="' . ($overdue ? 'od' : '') . '"><td><b>' . e((string) $o->order_no) . '</b><div class="c">' . $who . '</div></td>'
                   . '<td>' . e((string) $o->status) . ' ' . $badge . '</td>'
                   . '<td class="r">' . e($age) . '</td>'
                   . '<td class="r">' . e($cur) . ' ' . $amt . '</td></tr>';
        }
        if ($rows === '') $rows = '<tr><td colspan="4" class="empty">No open orders right now. 🎉</td></tr>';

        $shop  = e((string) ($t->name ?: 'Your shop'));
        $count = $open->count();
        $intro = OrderSla::isRealtime($vertical) ? 'Orders needing attention' : 'Open orders to review';

        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
            . '<title>' . $shop . ' — pending orders</title><style>'
            . ':root{--g:#15803D;--ink:#1d2b22;--mut:#6E7D72;--bg:#F4F7F4;--line:#E2EAE3;--od:#B91C1C}'
            . '*{box-sizing:border-box}body{margin:0;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:var(--bg);color:var(--ink)}'
            . '.wrap{max-width:680px;margin:0 auto;padding:18px}'
            . '.hd{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:4px}'
            . '.hd h1{font-size:18px;margin:0}.hd .n{font-weight:800;color:var(--g)}'
            . '.sub{color:var(--mut);font-size:13px;margin-bottom:14px}'
            . 'table{width:100%;border-collapse:collapse;background:#fff;border:1px solid var(--line);border-radius:12px;overflow:hidden}'
            . 'td{padding:11px 12px;border-bottom:1px solid var(--line);font-size:14px;vertical-align:top}'
            . 'tr:last-child td{border-bottom:0}.r{text-align:right;white-space:nowrap}.c{color:var(--mut);font-size:12px;margin-top:2px}'
            . 'tr.od td{background:#FEF2F2}'
            . '.b{font-size:11px;font-weight:700;padding:1px 7px;border-radius:999px;margin-left:4px}'
            . '.b.ok{background:#EAF6EE;color:var(--g)}.b.od{background:#FEE2E2;color:var(--od)}'
            . '.empty{text-align:center;color:var(--mut);padding:26px}'
            . '.ft{color:var(--mut);font-size:12px;margin-top:14px;text-align:center}'
            . '</style></head><body><div class="wrap">'
            . '<div class="hd"><h1>' . $intro . '</h1><span class="n">' . $count . '</span></div>'
            . '<div class="sub">' . $shop . ' · updates as of ' . e(now()->format('D H:i')) . '</div>'
            . '<table><tbody>' . $rows . '</tbody></table>'
            . '<div class="ft">Update these orders in your panel. This page is read-only.</div>'
            . '</div></body></html>';

        return response($html)->header('Content-Type', 'text/html; charset=utf-8');
    }
}
