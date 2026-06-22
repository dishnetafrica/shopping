<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Services\Logistics\ShipmentService;
use App\Services\Logistics\ShipmentStateMachine as SM;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Shipment Platform v2B — public custody pages for external parties (no login, no accounts).
 *
 *   /t/{token}  transporter        → Confirm receipt, Bus departed   (box count + photo)
 *   /a/{token}  destination agent  → Confirm arrival                 (box count + photo)
 *
 * The shipment token IS the key. Resolve it bypassing the tenant scope (there is no authenticated
 * user), then set TenantContext from the shipment's tenant so every downstream model/service call is
 * correctly scoped. Each action is double-gated: the route's role must be allowed the action AND the
 * state machine must permit it from the current status. Every action writes a custody event carrying
 * actor + timestamp + box count + photo.
 */
class ShipmentTrackController extends Controller
{
    /** Actions each external role may ever perform (defence-in-depth over the state machine). */
    private const ROLE_ACTIONS = [
        'transport'         => ['transport_confirm', 'depart'],
        'destination_agent' => ['arrive'],
    ];

    public function __construct(protected ShipmentService $shipments) {}

    /* ----------------------------------------------------------------- pages */

    public function transporter(string $token)
    {
        return $this->renderPage($this->resolve($token), 'transport', $token);
    }

    public function agent(string $token)
    {
        return $this->renderPage($this->resolve($token), 'destination_agent', $token);
    }

    /* --------------------------------------------------------------- actions */

    public function transporterAction(string $token, Request $r)
    {
        return $this->doAction($this->resolve($token), 'transport', $r);
    }

    public function agentAction(string $token, Request $r)
    {
        return $this->doAction($this->resolve($token), 'destination_agent', $r);
    }

    /* --------------------------------------------------------------- helpers */

    private function resolve(string $token): Shipment
    {
        $token = trim($token);
        $s = Shipment::withoutGlobalScopes()->where('token', $token)->first();
        abort_if(! $s, 404);
        app(TenantContext::class)->set($s->tenant_id);   // no auth user — scope from the shipment
        return $s;
    }

    /** The single action this role may take from the shipment's current status (or null). */
    private function availableAction(Shipment $s, string $role): ?array
    {
        $allowed = self::ROLE_ACTIONS[$role] ?? [];
        $labels  = [
            'transport_confirm' => ['Confirm receipt', 'Count the boxes you received and add a photo.'],
            'depart'            => ['Bus departed', 'Confirm the box count loaded and add a photo of the loaded bus.'],
            'arrive'            => ['Confirm arrival', 'Count the boxes that arrived and add a photo.'],
        ];
        foreach ($allowed as $action) {
            if (SM::canApply($s->status, $action)) {
                return ['action' => $action, 'label' => $labels[$action][0], 'hint' => $labels[$action][1]];
            }
        }
        return null;
    }

    private function doAction(Shipment $s, string $role, Request $r)
    {
        $action = (string) $r->input('action', '');
        $allowed = self::ROLE_ACTIONS[$role] ?? [];

        if (! in_array($action, $allowed, true)) {
            return response()->json(['ok' => false, 'error' => 'This action is not available on this page.'], 422);
        }
        if (! SM::canApply($s->status, $action)) {
            return response()->json(['ok' => false, 'error' => 'This shipment is already past this step.'], 409);
        }

        $box = $r->input('box_count');
        if (! is_numeric($box) || (int) $box < 0) {
            return response()->json(['ok' => false, 'error' => 'Please enter the number of boxes.'], 422);
        }

        $photoUrl = $this->storePhoto($s, (string) $r->input('photo', ''));
        if ($photoUrl === null) {
            return response()->json(['ok' => false, 'error' => 'A photo is required for this step.'], 422);
        }

        $actorName = $role === 'transport'
            ? ($s->transport_company ?: 'Transporter')
            : ($s->destination_agent_name ?: 'Destination agent');

        $res = $this->shipments->recordAction($s, $action, [
            'box_count'  => (int) $box,
            'photo_url'  => $photoUrl,
            'actor_name' => $actorName,
            'note'       => trim((string) $r->input('note', '')) ?: null,
        ]);

        return response()->json($res);
    }

    /** Store a base64/data-URI photo to the public disk; returns its URL, or null if absent/invalid. */
    private function storePhoto(Shipment $s, string $data): ?string
    {
        if ($data === '') return null;
        if (str_contains($data, ',')) $data = substr($data, strpos($data, ',') + 1);
        $bin = base64_decode($data, true);
        if ($bin === false || strlen($bin) < 32) return null;
        $file = 'shipments/' . (int) $s->tenant_id . '/' . $s->id . '_' . uniqid('e_', true) . '.jpg';
        Storage::disk('public')->put($file, $bin);
        return Storage::url($file);
    }

    /* ----------------------------------------------------------------- view */

    private function renderPage(Shipment $s, string $role, string $token)
    {
        $shop = $s->tenant ? (string) $s->tenant->name : 'Shipment';
        $roleLabel = $role === 'transport' ? 'Transporter' : 'Destination agent';
        $route = trim(e((string) $s->origin_city) . ' → ' . e((string) $s->destination_city), ' →');
        $expected = $s->boxes_received ?? $s->boxes_sent;

        $statusBadge = '<span class="badge">' . e(ucwords(str_replace('_', ' ', $s->status))) . '</span>';

        // custody timeline (read-only)
        $tl = '';
        foreach ($s->events()->get() as $e) {
            $label = ucwords(str_replace('_', ' ', (string) $e->event));
            $when  = optional($e->occurred_at)->format('d M, H:i');
            $box   = $e->box_count !== null ? ' · ' . (int) $e->box_count . ' boxes' : '';
            $img   = $e->photo_url ? '<img src="' . e($e->photo_url) . '" class="thumb">' : '';
            $tl .= '<div class="ev"><div class="dot"></div><div><b>' . e($label) . '</b>' . e($box)
                 . '<div class="muted">' . e((string) $e->actor) . ' · ' . e((string) $when) . '</div>' . $img . '</div></div>';
        }
        if ($tl === '') $tl = '<div class="muted">No custody events yet.</div>';

        // action card or a read-only waiting message
        $av = $this->availableAction($s, $role);
        if ($av) {
            $card = '<div class="action">'
                . '<div class="alabel">' . e($av['label']) . '</div>'
                . '<div class="muted" style="margin:2px 0 12px">' . e($av['hint']) . '</div>'
                . '<label class="fl">Box count</label>'
                . '<input id="box" type="number" min="0" inputmode="numeric" value="' . ($expected !== null ? (int) $expected : '') . '">'
                . '<label class="fl">Photo</label>'
                . '<input id="photo" type="file" accept="image/*" capture="environment">'
                . '<div id="pv"></div>'
                . '<button id="go" class="btn big" data-action="' . e($av['action']) . '">' . e($av['label']) . '</button>'
                . '<div id="msg" class="muted" style="margin-top:8px"></div>'
                . '</div>';
        } else {
            $msg = SM::isTerminal($s->status)
                ? 'This shipment is ' . e($s->status) . '. Nothing more to do here. Thank you!'
                : 'Nothing to confirm right now. Current status: ' . e(ucwords(str_replace('_', ' ', $s->status))) . '.';
            $card = '<div class="action"><div class="muted">' . $msg . '</div></div>';
        }

        $body = '<div class="card">'
            . '<div class="hdr">📦 ' . e($shop) . ' · ' . e($roleLabel) . '</div>'
            . '<h1>' . e($s->shipment_number) . ' ' . $statusBadge . '</h1>'
            . ($route ? '<div class="muted">' . $route . '</div>' : '')
            . ($expected !== null ? '<div class="exp">Expected boxes: <b>' . (int) $expected . '</b></div>' : '')
            . $card
            . '<div class="tl"><div class="tlh">Custody chain</div>' . $tl . '</div>'
            . '</div>';

        return $this->page($body, $role, $token);
    }

    private function page(string $body, string $role, string $token): \Illuminate\Http\Response
    {
        $post = '/' . ($role === 'transport' ? 't' : 'a') . '/' . e($token) . '/action';
        $html = '<!doctype html><html><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Shipment</title><style>'
            . 'body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#EEF2EE;color:#16231C;padding:16px}'
            . '.card{max-width:460px;margin:14px auto;background:#fff;border-radius:16px;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,.06)}'
            . '.hdr{color:#0B3D22;font-weight:800;font-size:14px;margin-bottom:6px}'
            . 'h1{font-size:20px;margin:0 0 4px;display:flex;align-items:center;gap:8px}.muted{color:#6E7D72;font-size:13px}'
            . '.badge{font-size:12px;font-weight:800;background:#0B3D22;color:#fff;border-radius:11px;padding:3px 10px}'
            . '.exp{background:#F1F8F3;border:1px solid #CDEBD6;border-radius:10px;padding:10px;font-size:14px;margin:10px 0}'
            . '.action{border-top:1px solid #eef2ee;margin-top:14px;padding-top:14px}.alabel{font-weight:800;font-size:16px}'
            . '.fl{display:block;font-size:12px;font-weight:700;color:#46554B;margin:10px 0 4px}'
            . 'input{width:100%;box-sizing:border-box;border:1.5px solid #CDEBD6;border-radius:10px;padding:11px;font-size:15px;font-family:inherit}'
            . 'input[type=file]{padding:9px}'
            . '.btn{border:0;border-radius:11px;padding:13px 16px;font-size:15px;font-weight:800;cursor:pointer}'
            . '.btn.big{background:#15803D;color:#fff;width:100%;margin-top:14px}.btn.big:disabled{opacity:.5}'
            . '#pv img{max-width:100%;border-radius:10px;margin-top:8px}'
            . '.tl{border-top:1px solid #eef2ee;margin-top:16px;padding-top:12px}.tlh{font-weight:800;font-size:13px;color:#46554B;margin-bottom:8px}'
            . '.ev{display:flex;gap:10px;padding:7px 0}.dot{width:9px;height:9px;border-radius:50%;background:#15803D;margin-top:5px;flex:none}'
            . '.thumb{max-width:120px;border-radius:8px;margin-top:6px;display:block}'
            . '</style></head><body>' . $body
            . '<script>(function(){'
            . 'var box=document.getElementById("box"),ph=document.getElementById("photo"),go=document.getElementById("go"),msg=document.getElementById("msg"),pv=document.getElementById("pv"),data="";'
            . 'if(!go)return;'
            . 'ph.onchange=function(){var f=ph.files&&ph.files[0];if(!f)return;var rd=new FileReader();rd.onload=function(){data=rd.result;pv.innerHTML="<img src=\\""+data+"\\">";};rd.readAsDataURL(f);};'
            . 'go.onclick=function(){'
            . 'if(box.value===""||Number(box.value)<0){msg.textContent="Enter the number of boxes.";return;}'
            . 'if(!data){msg.textContent="Add a photo first.";return;}'
            . 'go.disabled=true;msg.textContent="Submitting…";'
            . 'fetch("' . $post . '",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({action:go.dataset.action,box_count:box.value,photo:data})})'
            . '.then(function(r){return r.json();}).then(function(d){'
            . 'if(d&&d.ok){msg.textContent="✓ Saved"+((d.exceptions&&d.exceptions.length)?" — note: box count differs from before":"")+". Refreshing…";setTimeout(function(){location.reload();},900);}'
            . 'else{go.disabled=false;msg.textContent=(d&&d.error)?d.error:"Could not save.";}'
            . '}).catch(function(){go.disabled=false;msg.textContent="Network error.";});};'
            . '})();</script></body></html>';

        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
