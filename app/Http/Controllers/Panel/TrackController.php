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
        $routeLine = '';

        // If this order travels through a transport leg, show the FULL journey off the unified
        // custody chain (Phase 4) instead of just the order states. The customer sees one timeline:
        // Packed → In transit → Arrived in {city} → Out for delivery → Delivered.
        if ($journey = $this->shipmentJourney($order)) {
            $steps     = $journey['steps'];
            $idx       = $journey['idx'];
            $current   = $journey['current'];
            $routeLine = $journey['route'];
        }

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
            $nm   = (string) ($it['name'] ?? '');
            $mods = [];
            if (! empty($it['modifiers']) && is_array($it['modifiers'])) {
                foreach ($it['modifiers'] as $m) {
                    $mn = trim((string) ($m['name'] ?? ''));
                    if ($mn !== '') $mods[] = $mn;
                }
            }
            if ($mods) {                                          // un-fold any "+ Naan" stored on the name
                $suffix = ' + ' . implode(', ', $mods);
                if (str_ends_with($nm, $suffix)) $nm = substr($nm, 0, -strlen($suffix));
            }
            $cell = e(($it['qty'] ?? 1) . '× ' . $nm);
            if ($mods) {
                $cell .= '<div style="padding-left:14px;font-size:12px;color:#777">↳ ' . e(implode(', ', $mods)) . '</div>';
            }
            $rows .= '<tr><td>' . $cell . '</td></tr>';
        }
        if ($rows === '' && $order->items_text) {
            $rows = '<tr><td>' . e($order->items_text) . '</td></tr>';
        }

        // last-mile delivery (fetched once; powers rider reveal, ETA and proof)
        $delivery = \App\Models\Delivery::withoutGlobalScopes()->where('order_id', $order->id)->latest('id')->first();
        $collected = ($delivery && in_array($delivery->status, ['picked', 'out', 'delivered'], true))
            || in_array((string) $order->status, ['Out for delivery', 'Delivered'], true);
        $deliveredDone = ($delivery && $delivery->status === 'delivered')
            || strcasecmp((string) $order->status, 'Delivered') === 0;

        // ✓ done / ● current / ○ upcoming, on a connected rail
        $timeline = '<div class="tl">';
        foreach ($steps as $i => $s) {
            $cls = $i < $idx ? 'done' : ($i === $idx ? 'cur' : 'todo');
            $ic  = $i < $idx ? '&#10003;' : ($i === $idx ? '&#9679;' : '');
            $timeline .= '<div class="step ' . $cls . '"><span class="ic">' . $ic . '</span><span class="lbl">' . e($s) . '</span></div>';
        }
        $timeline .= '</div>';

        // Route (no transporter detail) + estimated delivery — only when the shop has set an ETA (never fabricated)
        $routeBlock = $routeLine !== '' ? '<div class="route">🚌 ' . e($routeLine) . '</div>' : '';
        $etaBlock = '';
        if (! $deliveredDone) {
            $eta = ($delivery && $delivery->eta_at) ? $delivery->eta_at : $order->eta_at;
            if ($eta) {
                $label = ($idx >= 2) ? 'Expected delivery' : 'Estimated arrival';
                $etaBlock = '<div class="eta">🕒 ' . $label . ': <b>' . e($eta->format('D j M, g:i A')) . '</b></div>';
            }
        }

        // Delivery partner card — revealed only once the rider has collected (and before delivered).
        $riderBlock = '';
        $rider = $order->rider;
        if ($rider && $collected && ! $deliveredDone) {
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

        // Delivery proof — shown once delivered (replaces the rider card).
        $proofBlock = '';
        if ($deliveredDone) {
            $when  = ($delivery && $delivery->delivered_at) ? $delivery->delivered_at : $order->delivered_at;
            $photo = $delivery ? trim((string) $delivery->proof_photo_url) : '';
            if ($photo !== '' && ! str_starts_with($photo, 'http') && ! str_starts_with($photo, '/') && ! str_starts_with($photo, 'data:')) {
                $photo = '/storage/' . $photo;
            }
            $proofBlock = '<div class="proof"><div class="pt">&#10003; Delivered successfully</div>'
                . ($when ? '<div class="pw">' . e($when->format('D j M, g:i A')) . '</div>' : '')
                . ($photo !== '' ? '<img src="' . e($photo) . '" alt="Delivery photo">' : '')
                . '</div>';
        }

        $body = '<div class="card">'
            . '<div class="hdr">' . $header . '</div>'
            . '<h1>Order ' . e($order->order_no ?: ('#' . $order->id)) . '</h1>'
            . '<div class="status">' . e($current) . '</div>'
            . $routeBlock
            . $etaBlock
            . $timeline
            . $proofBlock
            . $riderBlock
            . ($rows ? '<table>' . $rows . '</table>' : '')
            . '<div class="tot">Total: ' . e($cur) . ' ' . number_format((float) $order->total) . '</div>'
            . ($order->location ? '<div class="loc">📍 ' . e($order->location) . '</div>' : '')
            . '</div>';

        return $this->page($body, $brand);
    }

    /**
     * Build a customer-friendly journey from the unified custody chain (Phase 4). Returns null when
     * the order has no transport leg (then the caller keeps the plain order timeline). Deliberately
     * hides internal detail — box counts, exceptions, driver phones — the customer just sees stages.
     */
    private function shipmentJourney(\App\Models\Order $order): ?array
    {
        $ship = \App\Models\Shipment::withoutGlobalScopes()->where('order_id', $order->id)->latest('id')->first();
        if (! $ship) return null;

        $dest  = trim((string) $ship->destination_city);
        $steps = ['Packed', 'In transit', $dest !== '' ? 'Arrived in ' . $dest : 'Arrived', 'Out for delivery', 'Delivered'];

        $idx = 0;
        if (in_array($ship->status, ['sent_to_transporter', 'transport_confirmed', 'in_transit'], true)) $idx = 1;
        elseif ($ship->status === 'arrived') $idx = 2;

        $dlv = \App\Models\Delivery::withoutGlobalScopes()->where('order_id', $order->id)->latest('id')->first();
        if ($dlv) {
            if (in_array($dlv->status, ['picked', 'out'], true)) $idx = max($idx, 3);
            if ($dlv->status === 'delivered')                    $idx = 4;
        }
        if (strcasecmp((string) $order->status, 'Delivered') === 0) $idx = 4;

        $cancelled = $ship->status === 'cancelled';
        $current   = $cancelled ? 'Cancelled' : $steps[$idx];

        $route = '';
        $orig = trim((string) $ship->origin_city);
        if ($orig !== '' || $dest !== '') {
            $route = trim($orig . ($dest !== '' ? ' → ' . $dest : ''), ' →');
        }

        return ['steps' => $steps, 'idx' => $idx, 'current' => $current, 'route' => $route];
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
            . '.route{background:#F4F8F5;border:1px solid #E0EAE3;border-radius:11px;padding:10px 13px;margin:0 0 11px;font-size:14px;font-weight:700}'
            . '.eta{background:#FFF8E9;border:1px solid #F2E2B8;border-radius:11px;padding:10px 13px;margin:0 0 11px;font-size:13.5px}.eta b{font-weight:800}'
            . '.tl{position:relative;margin:14px 0 4px}'
            . '.tl .step{display:flex;align-items:flex-start;gap:11px;position:relative;padding-bottom:15px}'
            . '.tl .step:last-child{padding-bottom:0}'
            . '.tl .step .ic{width:22px;height:22px;border-radius:50%;flex:none;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;z-index:1;line-height:1}'
            . '.tl .step.done .ic{background:#15803D;color:#fff}'
            . '.tl .step.cur .ic{background:#15803D;color:#fff;box-shadow:0 0 0 4px rgba(21,128,61,.18)}'
            . '.tl .step.todo .ic{background:#fff;border:2px solid #cdd6ce}'
            . '.tl .step .lbl{font-size:14.5px;padding-top:2px}'
            . '.tl .step.done .lbl,.tl .step.cur .lbl{font-weight:700}.tl .step.todo .lbl{color:#9aa7a0}'
            . '.tl .step:not(:last-child)::before{content:"";position:absolute;left:10px;top:22px;height:calc(100% - 22px);width:2px;background:#dce5de}'
            . '.tl .step.done:not(:last-child)::before{background:#15803D}'
            . '.proof{background:#EAF6EE;border:1px solid #CDEBD6;border-radius:13px;padding:15px;margin:14px 0;text-align:center}'
            . '.proof .pt{font-weight:800;color:#15803D;font-size:16px}'
            . '.proof img{max-width:100%;border-radius:11px;margin-top:10px}'
            . '.proof .pw{font-size:12px;color:#6E7D72;margin-top:5px}'
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
            . '@media(max-width:480px){body{padding:10px}.card{padding:18px;margin:10px auto;border-radius:14px}h1{font-size:19px}.callbtn{padding:11px 14px}}'
            . '</style></head><body>' . $body . '</body></html>';

        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
