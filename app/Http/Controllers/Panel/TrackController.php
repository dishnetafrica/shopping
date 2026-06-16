<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Tenant;
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

        // Brand the page with the order's OWN shop, not the default tenant.
        $tenant   = Tenant::find($order->tenant_id);
        $shopName = $tenant?->name ?: 'Shop';
        $cur      = $tenant ? (string) $tenant->setting('currency', 'UGX') : 'UGX';
        $logoRaw  = $tenant ? (string) $tenant->setting('logo', '') : '';
        $logoUrl  = ($logoRaw !== '' && (str_starts_with($logoRaw, 'http') || str_starts_with($logoRaw, '/'))) ? $logoRaw : '';
        $header   = $logoUrl !== ''
            ? '<img class="logo" src="' . e($logoUrl) . '" alt=""><span>' . e($shopName) . '</span>'
            : '🛒 ' . e($shopName);

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

        // Delivery partner card — reassures the customer who is bringing the order.
        $riderBlock = '';
        $rider = $order->rider;
        if ($rider) {
            $rp = trim((string) ($rider->photo ?? ''));
            if ($rp !== '' && ! str_starts_with($rp, 'http') && ! str_starts_with($rp, '/') && ! str_starts_with($rp, 'data:')) {
                $rp = '/storage/' . $rp;
            }
            $avatar = $rp !== ''
                ? '<img class="ravatar" src="' . e($rp) . '" alt="">'
                : '<div class="ravatar rph">' . e(mb_strtoupper(mb_substr((string) $rider->name, 0, 1))) . '</div>';
            $riderPhone = preg_replace('/[^0-9+]/', '', (string) ($rider->phone ?? ''));
            $callBtn = $riderPhone !== ''
                ? '<a class="callbtn" href="tel:' . e($riderPhone) . '">📞 Call</a>'
                : '';
            $riderBlock = '<div class="rider">' . $avatar
                . '<div style="flex:1"><div class="rlabel">Your delivery partner</div>'
                . '<div class="rname">' . e((string) $rider->name) . '</div></div>'
                . $callBtn . '</div>';

            // Live location: show distance/last-seen if the rider has pinged recently.
            $delivery = \App\Models\Delivery::withoutGlobalScopes()
                ->where('order_id', $order->id)->latest('id')->first();
            if ($delivery && $delivery->rider_lat && $delivery->rider_loc_at
                && $delivery->rider_loc_at->gt(now()->subMinutes(20))) {
                $rlat = (float) $delivery->rider_lat;
                $rlng = (float) $delivery->rider_lng;
                $ago  = $delivery->rider_loc_at->diffForHumans(null, true);
                $mapUrl = 'https://www.google.com/maps?q=' . $rlat . ',' . $rlng;
                $distTxt = '';
                if (preg_match('/(-?\d{1,3}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)/', (string) $order->location, $cm)) {
                    $km = $this->haversineKm((float) $cm[1], (float) $cm[2], $rlat, $rlng);
                    $distTxt = $km < 1
                        ? 'about ' . max(50, (int) round($km * 1000 / 50) * 50) . ' m away'
                        : 'about ' . number_format($km, 1) . ' km away';
                }
                $riderBlock .= '<div class="live"><span class="pulse"></span>'
                    . '<span>Rider is ' . ($distTxt !== '' ? e($distTxt) : 'on the way')
                    . ' · updated ' . e($ago) . ' ago</span>'
                    . '<a href="' . e($mapUrl) . '" target="_blank" rel="noopener">See on map</a></div>';
            }
        }

        $body = '<div class="card">'
            . '<div class="hdr">' . $header . '</div>'
            . '<h1>Order ' . e($order->order_no ?: ('#' . $order->id)) . '</h1>'
            . '<div class="status">' . e($current) . '</div>'
            . '<div class="timeline">' . $timeline . '</div>'
            . $riderBlock
            . ($rows ? '<table>' . $rows . '</table>' : '')
            . '<div class="tot">Total: ' . e($cur) . ' ' . number_format((float) $order->total) . '</div>'
            . ($order->location ? '<div class="loc">📍 ' . e($order->location) . '</div>' : '')
            . '</div>';

        return $this->page($body, $brand);
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    protected function page(string $body, string $brand)
    {
        $html = '<!doctype html><html><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Track your order</title><style>'
            . 'body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#EEF2EE;color:#16231C;padding:18px}'
            . '.card{max-width:460px;margin:24px auto;background:#fff;border-radius:16px;padding:22px;box-shadow:0 1px 3px rgba(0,0,0,.06)}'
            . '.hdr{color:' . $brand . ';font-weight:800;font-size:14px;margin-bottom:6px;display:flex;align-items:center;gap:8px}'
            . '.hdr .logo{width:26px;height:26px;border-radius:6px;object-fit:cover}'
            . 'h1{font-size:20px;margin:0 0 4px}'
            . '.status{display:inline-block;background:#e6f4ea;color:#15803D;font-weight:700;padding:5px 12px;border-radius:20px;font-size:13px;margin:8px 0 14px}'
            . '.timeline{display:flex;flex-direction:column;gap:9px;margin:12px 0}'
            . '.step{display:flex;align-items:center;gap:10px;font-size:14px}'
            . '.dot{width:11px;height:11px;border-radius:50%;display:inline-block}'
            . 'table{width:100%;border-collapse:collapse;margin:14px 0;font-size:14px}'
            . 'td{padding:7px 0;border-bottom:1px solid #eef2ee}'
            . '.tot{font-weight:800;margin-top:8px}.loc{color:#6E7D72;font-size:13px;margin-top:6px}'
            . '.rider{display:flex;align-items:center;gap:12px;background:#F1F8F3;border:1px solid #CDEBD6;border-radius:13px;padding:11px 13px;margin:14px 0}'
            . '.ravatar{width:46px;height:46px;border-radius:50%;object-fit:cover;flex:none;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.12)}'
            . '.ravatar.rph{display:flex;align-items:center;justify-content:center;background:#15803D;color:#fff;font-weight:800;font-size:20px}'
            . '.rlabel{font-size:11px;color:#6E7D72;font-weight:700;text-transform:uppercase;letter-spacing:.04em}'
            . '.rname{font-size:16px;font-weight:800;margin-top:1px}'
            . '.callbtn{flex:none;background:#15803D;color:#fff;font-weight:800;text-decoration:none;border-radius:10px;padding:9px 16px;font-size:14px}'
            . '.live{display:flex;align-items:center;gap:8px;background:#EAF6EE;border:1px solid #CDEBD6;border-radius:11px;padding:9px 12px;margin:-6px 0 14px;font-size:13px;font-weight:600;color:#15803D}'
            . '.live a{margin-left:auto;color:#0A6E1A;font-weight:800;text-decoration:none;white-space:nowrap}'
            . '.pulse{width:9px;height:9px;border-radius:50%;background:#15803D;flex:none;box-shadow:0 0 0 0 rgba(21,128,61,.5);animation:pl 1.6s infinite}'
            . '@keyframes pl{0%{box-shadow:0 0 0 0 rgba(21,128,61,.5)}70%{box-shadow:0 0 0 9px rgba(21,128,61,0)}100%{box-shadow:0 0 0 0 rgba(21,128,61,0)}}'
            . '</style></head><body>' . $body . '</body></html>';

        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
