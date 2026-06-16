<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * Public rider page reached via the per-delivery rider_token (no login).
 * The rider opens /r/{token} on their phone and taps "Share my location";
 * the browser then posts GPS pings that power the customer's live tracking.
 */
class RiderTrackController extends Controller
{
    private function delivery(string $token): Delivery
    {
        $token = strtolower(trim($token));
        $d = Delivery::withoutGlobalScopes()->where('rider_token', $token)->first();
        abort_if(! $d, 404);
        app(TenantContext::class)->set($d->tenant_id);
        return $d;
    }

    public function show(string $token)
    {
        $d     = $this->delivery($token);
        $order = $d->order; // BelongsTo
        $rider = $d->rider;

        $shop   = $order && $order->tenant ? (string) $order->tenant->name : 'Shop';
        $orderNo = $order ? e($order->order_no ?: ('#' . $order->id)) : '';
        $addr   = $order ? e((string) $order->location) : '';
        $cphone = $order ? preg_replace('/[^0-9+]/', '', (string) $order->customer_phone) : '';
        $cname  = $order ? e((string) $order->customer_name) : '';

        // Build a navigate link from any coords in the address.
        $nav = '';
        if ($order && preg_match('/(-?\d{1,3}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)/', (string) $order->location, $m)) {
            $nav = 'https://www.google.com/maps/dir/?api=1&destination=' . $m[1] . ',' . $m[2];
        }

        $rows = '';
        foreach (($order->items_json ?? []) as $it) {
            $rows .= '<tr><td>' . e($it['qty'] ?? 1) . '× ' . e($it['name'] ?? '') . '</td></tr>';
        }

        $cod = (int) ($d->cod_amount ?? 0);
        $cur = $order && $order->tenant ? (string) $order->tenant->setting('currency', 'UGX') : 'UGX';

        $callC = $cphone !== '' ? '<a class="btn ghost" href="tel:' . e($cphone) . '">📞 Call customer</a>' : '';
        $navB  = $nav !== '' ? '<a class="btn ghost" href="' . e($nav) . '" target="_blank" rel="noopener">🧭 Navigate</a>' : '';

        $body = '<div class="card">'
            . '<div class="hdr">🛵 ' . e($shop) . ' · Delivery</div>'
            . '<h1>Order ' . $orderNo . '</h1>'
            . ($cname ? '<div class="muted">For ' . $cname . '</div>' : '')
            . ($addr ? '<div class="addr">📍 ' . $addr . '</div>' : '')
            . ($rows ? '<table>' . $rows . '</table>' : '')
            . ($cod > 0 ? '<div class="cod">Collect cash: <b>' . e($cur) . ' ' . number_format($cod) . '</b></div>' : '')
            . '<div class="row">' . $callC . $navB . '</div>'
            . '<div class="share">'
            . '<div class="slabel">Live location</div>'
            . '<div class="muted" style="margin:2px 0 10px">Turn this on so the customer can see you on the way. Keep this page open until you arrive.</div>'
            . '<button id="shareBtn" class="btn big">▶ Start sharing my location</button>'
            . '<div id="shareState" class="muted" style="margin-top:8px"></div>'
            . '</div>'
            . '</div>';

        return $this->page($body, $token);
    }

    public function loc(string $token, Request $r)
    {
        $d   = $this->delivery($token);
        $lat = $r->input('lat');
        $lng = $r->input('lng');
        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return response()->json(['ok' => false], 422);
        }
        $d->rider_lat    = (float) $lat;
        $d->rider_lng    = (float) $lng;
        $d->rider_loc_at = now();
        $d->save();
        return response()->json(['ok' => true]);
    }

    private function page(string $body, string $token)
    {
        $post = '/r/' . e($token) . '/loc';
        $html = '<!doctype html><html><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Delivery</title><style>'
            . 'body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#EEF2EE;color:#16231C;padding:16px}'
            . '.card{max-width:460px;margin:14px auto;background:#fff;border-radius:16px;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,.06)}'
            . '.hdr{color:#0B3D22;font-weight:800;font-size:14px;margin-bottom:6px}'
            . 'h1{font-size:20px;margin:0 0 4px}.muted{color:#6E7D72;font-size:13px}'
            . '.addr{background:#F1F8F3;border:1px solid #CDEBD6;border-radius:10px;padding:10px;font-size:14px;margin:10px 0}'
            . 'table{width:100%;border-collapse:collapse;margin:10px 0;font-size:14px}td{padding:6px 0;border-bottom:1px solid #eef2ee}'
            . '.cod{background:#FFF6E5;border:1px solid #F2D79A;border-radius:10px;padding:10px;font-size:14px;margin:8px 0}'
            . '.row{display:flex;gap:9px;flex-wrap:wrap;margin:12px 0}'
            . '.btn{border:0;border-radius:11px;padding:11px 16px;font-size:14px;font-weight:800;text-decoration:none;cursor:pointer;display:inline-block}'
            . '.btn.ghost{background:#fff;color:#0A6E1A;border:1.5px solid #CDEBD6}'
            . '.btn.big{background:#15803D;color:#fff;width:100%}.btn.big.on{background:#B4232A}'
            . '.share{border-top:1px solid #eef2ee;margin-top:14px;padding-top:14px}.slabel{font-weight:800;font-size:15px}'
            . '</style></head><body>' . $body
            . '<script>(function(){'
            . 'var on=false,wid=null,btn=document.getElementById("shareBtn"),st=document.getElementById("shareState"),last=0;'
            . 'function send(p){var la=p.coords.latitude,ln=p.coords.longitude;'
            . 'fetch("' . $post . '",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({lat:la,lng:ln})})'
            . '.then(function(r){return r.json();}).then(function(d){if(d&&d.ok){last=Date.now();st.textContent="✓ Sharing live · last sent just now";}}).catch(function(){});}'
            . 'function tick(){if(navigator.geolocation)navigator.geolocation.getCurrentPosition(send,function(e){st.textContent="Location error: "+(e.message||"denied");},{enableHighAccuracy:true,maximumAge:10000,timeout:15000});}'
            . 'btn.onclick=function(){if(!navigator.geolocation){st.textContent="This phone does not support location sharing.";return;}'
            . 'if(!on){on=true;btn.textContent="■ Stop sharing";btn.classList.add("on");st.textContent="Starting…";tick();wid=setInterval(tick,25000);}'
            . 'else{on=false;btn.textContent="▶ Start sharing my location";btn.classList.remove("on");if(wid)clearInterval(wid);st.textContent="Stopped.";}};'
            . 'setInterval(function(){if(on&&last){var s=Math.round((Date.now()-last)/1000);st.textContent="✓ Sharing live · last sent "+s+"s ago";}},5000);'
            . '})();</script>'
            . '</body></html>';
        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
