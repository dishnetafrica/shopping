<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;

/**
 * Public order-tracking page the customer opens from the WhatsApp link
 * (/papi/track?o=<id>&t=<token>). No login: the order id + secret token are
 * the credential, so we bypass the tenant scope and match on both.
 */
class TrackController extends Controller
{
    public function show(Request $request)
    {
        $id    = (int) $request->query('o', 0);
        $token = (string) $request->query('t', '');

        $order = $id && $token
            ? Order::withoutGlobalScopes()->where('id', $id)->where('track_token', $token)->first()
            : null;

        $brand = '#0B3D22';
        if (! $order) {
            $body = '<div class="card"><h1>Order not found</h1><p>This tracking link looks invalid or has expired.</p></div>';
            return $this->page($body, $brand);
        }

        $steps   = ['New', 'Confirmed', 'Packed', 'Out for delivery', 'Delivered'];
        $current = $order->status ?: 'New';
        $idx     = array_search($current, $steps, true);
        if ($idx === false) $idx = 0;

        $rows = '';
        foreach (($order->items_json ?? []) as $it) {
            $rows .= '<tr><td>' . e($it['qty'] ?? 1) . '× ' . e($it['name'] ?? '') . '</td></tr>';
        }
        if ($rows === '' && $order->items_text) {
            $rows = '<tr><td>' . e($order->items_text) . '</td></tr>';
        }

        $timeline = '';
        foreach ($steps as $i => $s) {
            $done = $i <= $idx;
            $dot  = $done ? '#15803D' : '#cbd5cb';
            $timeline .= '<div class="step"><span class="dot" style="background:' . $dot . '"></span>'
                . '<span style="' . ($done ? 'font-weight:700' : 'color:#9aa7a0') . '">' . e($s) . '</span></div>';
        }

        $body = '<div class="card">'
            . '<div class="hdr">🛒 Family Shopper</div>'
            . '<h1>Order ' . e($order->order_no ?: ('#' . $order->id)) . '</h1>'
            . '<div class="status">' . e($current) . '</div>'
            . '<div class="timeline">' . $timeline . '</div>'
            . ($rows ? '<table>' . $rows . '</table>' : '')
            . '<div class="tot">Total: UGX ' . number_format((float) $order->total) . '</div>'
            . ($order->location ? '<div class="loc">📍 ' . e($order->location) . '</div>' : '')
            . '</div>';

        return $this->page($body, $brand);
    }

    protected function page(string $body, string $brand)
    {
        $html = '<!doctype html><html><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Track your order</title><style>'
            . 'body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#EEF2EE;color:#16231C;padding:18px}'
            . '.card{max-width:460px;margin:24px auto;background:#fff;border-radius:16px;padding:22px;box-shadow:0 1px 3px rgba(0,0,0,.06)}'
            . '.hdr{color:' . $brand . ';font-weight:800;font-size:14px;margin-bottom:6px}'
            . 'h1{font-size:20px;margin:0 0 4px}'
            . '.status{display:inline-block;background:#e6f4ea;color:#15803D;font-weight:700;padding:5px 12px;border-radius:20px;font-size:13px;margin:8px 0 14px}'
            . '.timeline{display:flex;flex-direction:column;gap:9px;margin:12px 0}'
            . '.step{display:flex;align-items:center;gap:10px;font-size:14px}'
            . '.dot{width:11px;height:11px;border-radius:50%;display:inline-block}'
            . 'table{width:100%;border-collapse:collapse;margin:14px 0;font-size:14px}'
            . 'td{padding:7px 0;border-bottom:1px solid #eef2ee}'
            . '.tot{font-weight:800;margin-top:8px}.loc{color:#6E7D72;font-size:13px;margin-top:6px}'
            . '</style></head><body>' . $body . '</body></html>';

        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
