<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShipmentBox;
use App\Models\ShipmentOrder;
use App\Services\Logistics\ShipmentService;
use App\Services\Logistics\ShipmentStateMachine as SM;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    /* ---------------------------------------------------------- box scanning (v6) */

    public function transporterScan(string $token, Request $r)  { return $this->doScan($this->resolve($token), 'transport', $r); }
    public function agentScan(string $token, Request $r)        { return $this->doScan($this->resolve($token), 'destination_agent', $r); }
    public function transporterScanConfirm(string $token, Request $r) { return $this->doScanConfirm($this->resolve($token), 'transport', $r); }
    public function agentScanConfirm(string $token, Request $r)       { return $this->doScanConfirm($this->resolve($token), 'destination_agent', $r); }

    /**
     * v4.7 — the agent ticks a customer's order off the manifest (delivered / returned) from the
     * no-login page. Mirrors the panel's consignment-line-status exactly: the ShipmentOrder line is
     * stamped AND the underlying Order flips (Delivered → invoice + stock + customer WhatsApp ride
     * the normal observer chain). Only after the shipment has arrived — receive first, then hand out.
     */
    public function agentLine(string $token, Request $r)
    {
        $s = $this->resolve($token);
        if ($s->status !== SM::ARRIVED && ! $s->arrived_at) {
            return response()->json(['ok' => false, 'error' => 'Confirm arrival first, then tick customers off.'], 409);
        }

        $line = ShipmentOrder::where('shipment_id', $s->id)->find((int) $r->input('line_id'));
        if (! $line) {
            return response()->json(['ok' => false, 'error' => 'Line not found on this consignment.'], 404);
        }
        $to = strtolower((string) $r->input('status', ''));
        if (! in_array($to, [ShipmentOrder::DELIVERED, ShipmentOrder::RETURNED, ShipmentOrder::ON_BOARD], true)) {
            return response()->json(['ok' => false, 'error' => 'bad_status'], 422);
        }
        $actor = (string) ($s->destination_agent_name ?: 'Destination agent');

        DB::transaction(function () use ($line, $to, $actor, $s) {
            $line->status = $to;
            $line->delivered_at = $to === ShipmentOrder::DELIVERED ? now() : null;
            $line->delivered_by = $to === ShipmentOrder::DELIVERED ? $actor : null;
            $line->save();

            $order = Order::where('tenant_id', $s->tenant_id)->find($line->order_id);
            if ($order) {
                if ($to === ShipmentOrder::DELIVERED && $order->status !== 'Delivered') {
                    $order->status = 'Delivered'; $order->save();   // OrderObserver → invoice + customer WhatsApp
                } elseif ($to === ShipmentOrder::RETURNED && $order->status !== 'Returned') {
                    $order->status = 'Returned'; $order->save();
                }
            }
        });

        return response()->json(['ok' => true, 'status' => $line->status]);
    }

    /** The scannable custody stage for this role at the shipment's current status (or null). */
    private function scanStage(Shipment $s, string $role): ?string
    {
        if ($role === 'transport' && $s->status === 'sent_to_transporter') return 'received_by_transport';
        if ($role === 'destination_agent' && $s->status === 'in_transit')  return 'arrived';
        return null;
    }

    private function boxes(): \App\Services\Logistics\BoxCustodyService
    {
        return app(\App\Services\Logistics\BoxCustodyService::class);
    }

    private function doScan(Shipment $s, string $role, Request $r)
    {
        $stage = $this->scanStage($s, $role);
        if (! $stage) return response()->json(['ok' => false, 'error' => 'Not ready to scan at this step.'], 409);
        $this->boxes()->syncBoxes($s);
        $actorName = $role === 'transport' ? ($s->transport_company ?: 'Transporter') : ($s->destination_agent_name ?: 'Destination agent');
        $res = $this->boxes()->scan($s, (string) $r->input('code', ''), $stage, $role, $actorName);
        if (! empty($res['ok'])) $res = array_merge($res, $this->boxes()->summary($s, $stage));
        return response()->json($res);
    }

    private function doScanConfirm(Shipment $s, string $role, Request $r)
    {
        $stage = $this->scanStage($s, $role);
        if (! $stage) return response()->json(['ok' => false, 'error' => 'Nothing to confirm at this step.'], 409);
        $actorName = $role === 'transport' ? ($s->transport_company ?: 'Transporter') : ($s->destination_agent_name ?: 'Destination agent');
        $summary = $this->boxes()->summary($s, $stage);
        $photoUrl = $this->storePhoto($s, (string) $r->input('photo', ''));
        $res = $this->boxes()->finalize($s, $stage, $role, $actorName, $photoUrl ? ['photo_url' => $photoUrl] : []);
        return response()->json(array_merge($res, $summary));
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
        $scanStage = $this->scanStage($s, $role);
        $boxTotal = $this->boxes()->total($s);

        if ($av && $scanStage && $boxTotal > 0) {
            // box-level scan card (v6 / v6.1) — no manual count; the scans ARE the count
            $sum = $this->boxes()->summary($s, $scanStage);
            $pct = $boxTotal > 0 ? (int) round($sum['scanned'] / $boxTotal * 100) : 0;
            $missCodes = implode(', ', $sum['missing_codes']);
            $card = '<div class="action" data-stage="' . e($scanStage) . '" data-total="' . $boxTotal . '">'
                . '<div class="alabel">' . e($av['label']) . '</div>'
                . '<div class="muted" style="margin:2px 0 10px">Scan each box\'s QR label. ' . $boxTotal . ' to scan.</div>'
                . '<div class="bigprog"><div class="bpnum"><span id="scnN">' . $sum['scanned'] . '</span> / ' . $boxTotal . '</div>'
                . '<div class="bplab">Boxes scanned</div>'
                . '<div class="bar"><div id="barfill" class="fill" style="width:' . $pct . '%"></div></div></div>'
                . '<div class="tiles">'
                . '<div class="tile"><div class="tv">' . $boxTotal . '</div><div class="tl">Expected</div></div>'
                . '<div class="tile ok"><div class="tv" id="tScan">' . $sum['scanned'] . '</div><div class="tl">Scanned</div></div>'
                . '<div class="tile warn"><div class="tv" id="tMiss">' . $sum['missing'] . '</div><div class="tl">Missing</div></div>'
                . '</div>'
                . '<div id="missbox" class="missbox"' . ($sum['missing'] > 0 ? '' : ' style="display:none"') . '>Still to scan: <b id="misscodes">' . e($missCodes) . '</b></div>'
                . '<div id="scanchips" class="scanchips"></div>'
                . '<video id="cam" playsinline style="display:none"></video>'
                . '<button id="cambtn" class="btn ghost2" type="button">📷 Scan with camera</button>'
                . '<div class="manrow"><input id="mancode" placeholder="…or type a box code (e.g. ' . e($s->shipment_number) . '-B1)"><button id="manadd" class="btn ghost2" type="button">Add</button></div>'
                . '<button id="go" class="btn big" data-action="' . e($av['action']) . '">' . e($av['label']) . '</button>'
                . '<div id="msg" class="muted" style="margin-top:8px"></div>'
                . '</div>';
        } elseif ($av) {
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

        // v4.7 — the AGENT sees what is inside each box and ticks customers off the manifest.
        $packHtml = '';
        $maniHtml = '';
        if ($role === 'destination_agent') {
            $lines  = ShipmentOrder::where('shipment_id', $s->id)->orderBy('id')->get();
            $orders = $lines->count() ? Order::whereIn('id', $lines->pluck('order_id'))->get()->keyBy('id') : collect();
            $bxs    = ShipmentBox::where('shipment_id', $s->id)->orderBy('box_number')->get();

            $boxOf = [];
            $packRows = '';
            foreach ($bxs as $b) {
                $who = [];
                foreach ((array) ($b->order_ids ?? []) as $oid) {
                    $boxOf[(int) $oid][] = 'B' . (int) $b->box_number;
                    $o = $orders->get((int) $oid);
                    if ($o) $who[] = (string) ($o->customer_name ?: $o->customer_phone);
                }
                if (! $who && ! $b->packing && ! $b->note) continue;
                $packRows .= '<div class="pk"><b>Box ' . (int) $b->box_number . '</b>'
                    . ($b->packing ? ' <span class="pill">' . e($b->packing) . '</span>' : '')
                    . ($who ? '<div>' . e(implode(', ', $who)) . '</div>' : '')
                    . ($b->note ? '<div class="muted">' . e($b->note) . '</div>' : '') . '</div>';
            }
            if ($packRows !== '') {
                $packHtml = '<div class="tl"><div class="tlh">What is in each box</div>' . $packRows . '</div>';
            }

            if ($lines->count()) {
                $canTick = ($s->status === SM::ARRIVED || $s->arrived_at);
                $mrows = '';
                foreach ($lines as $ln) {
                    $o = $orders->get($ln->order_id);
                    $name = $o ? (string) ($o->customer_name ?: $o->customer_phone) : ('#' . $ln->order_id);
                    $whereB = isset($boxOf[(int) $ln->order_id]) ? implode(' ', $boxOf[(int) $ln->order_id]) : '';
                    $chip = $ln->status === ShipmentOrder::DELIVERED
                        ? '<span class="pill ok">✓ delivered</span>'
                        : ($ln->status === ShipmentOrder::RETURNED ? '<span class="pill bad">returned</span>' : '<span class="pill">on board</span>');
                    $btns = '';
                    if ($canTick && $ln->status === ShipmentOrder::ON_BOARD) {
                        $btns = '<div class="lnbtns">'
                            . '<button class="btn mini lnbtn" data-line="' . (int) $ln->id . '" data-to="delivered">✓ Given</button>'
                            . '<button class="btn mini ghost lnbtn" data-line="' . (int) $ln->id . '" data-to="returned">↩ Back to Kampala</button>'
                            . '</div>';
                    }
                    $mrows .= '<div class="mline"><div><b>' . e($name) . '</b> <span class="muted">' . e($o->order_no ?? '') . '</span>'
                        . ($whereB !== '' ? ' <span class="pill">' . e($whereB) . '</span>' : '')
                        . ($o && $o->items_text ? '<div class="muted" style="margin-top:2px">' . e(mb_substr((string) $o->items_text, 0, 90)) . '</div>' : '')
                        . '</div><div>' . $chip . $btns . '</div></div>';
                }
                $hint = $canTick ? '' : '<div class="muted" style="margin-bottom:6px">Confirm arrival above first — then tick each customer as they collect.</div>';
                $maniHtml = '<div class="tl"><div class="tlh">Customer orders (' . $lines->count() . ')</div>' . $hint . $mrows . '</div>';
            }
        }

        $body = '<div class="card">'
            . '<div class="hdr">📦 ' . e($shop) . ' · ' . e($roleLabel) . '</div>'
            . '<h1>' . e($s->shipment_number) . ' ' . $statusBadge . '</h1>'
            . ($route ? '<div class="muted">' . $route . '</div>' : '')
            . ($expected !== null ? '<div class="exp">Expected boxes: <b>' . (int) $expected . '</b></div>' : '')
            . $card
            . $packHtml
            . $maniHtml
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
            . '.scanprog{font-weight:800;font-size:17px;margin:6px 0 8px}'
            . '.bigprog{text-align:center;margin:6px 0 12px}'
            . '.bpnum{font-size:40px;font-weight:900;line-height:1;color:#0B3D22}'
            . '.bplab{font-size:12px;font-weight:700;color:#6E7D72;text-transform:uppercase;letter-spacing:.05em;margin-top:3px}'
            . '.bar{height:12px;background:#e7ece8;border-radius:8px;overflow:hidden;margin-top:10px}'
            . '.bar .fill{height:100%;background:#15803D;border-radius:8px;transition:width .3s ease}'
            . '.tiles{display:flex;gap:8px;margin:4px 0 10px}'
            . '.tile{flex:1;background:#f4f8f5;border:1px solid #e0eae3;border-radius:12px;padding:10px 6px;text-align:center}'
            . '.tile .tv{font-size:26px;font-weight:900;line-height:1}.tile .tl{font-size:11px;font-weight:700;color:#6E7D72;text-transform:uppercase;margin-top:3px}'
            . '.tile.ok .tv{color:#15803D}.tile.warn{background:#fdf6e9;border-color:#f2e2b8}.tile.warn .tv{color:#a86a12}'
            . '.missbox{background:#fdf0d8;border:1px solid #f2d9a6;border-radius:11px;padding:10px 12px;font-size:14px;margin-bottom:10px;color:#7a5212}'
            . '.scanchips{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px}'
            . '.scanchips .c{background:#e7f6ec;color:#15803D;border-radius:9px;padding:3px 9px;font-size:12px;font-weight:700}'
            . '.btn.ghost2{background:#eef2f8;color:#1f3a5f;width:100%;margin-top:6px}'
            . '.manrow{display:flex;gap:8px;margin-top:8px}.manrow input{flex:1}.manrow .btn{padding:11px 14px;width:auto}'
            . '#cam{width:100%;border-radius:12px;margin-top:8px;background:#000;max-height:280px;object-fit:cover}'
            . '.pill{font-size:11px;font-weight:800;background:#eef2ee;color:#46554B;border-radius:8px;padding:2px 8px}'
            . '.pill.ok{background:#e7f6ec;color:#15803D}.pill.bad{background:#fdecec;color:#b23b3b}'
            . '.pk{padding:7px 0;border-bottom:1px dashed #e7ece8;font-size:14px}'
            . '.mline{display:flex;justify-content:space-between;gap:10px;align-items:flex-start;padding:9px 0;border-bottom:1px dashed #e7ece8;font-size:14px}'
            . '.lnbtns{display:flex;flex-direction:column;gap:6px;margin-top:6px}'
            . '.btn.mini{background:#15803D;color:#fff;padding:8px 10px;font-size:12px;border-radius:9px;white-space:nowrap}'
            . '.btn.mini.ghost{background:#eef2f8;color:#1f3a5f}'
            . '</style></head><body>' . $body
            . '<script>' . $this->pageJs($role, $token) . '</script></body></html>';

        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /** Page script: box-scan mode when a scan card is present, else the manual count/photo form. */
    private function pageJs(string $role, string $token): string
    {
        $base = '/' . ($role === 'transport' ? 't' : 'a') . '/' . $token;
        $js = <<<'JS'
(function(){
  var base="__BASE__";
  var go=document.getElementById("go"), msg=document.getElementById("msg");
  var act=document.querySelector(".action[data-stage]");

  /* v4.7 — agent manifest tick-off (delegated; buttons exist only after arrival) */
  document.addEventListener("click",function(ev){
    var b=ev.target.closest?ev.target.closest(".lnbtn"):null;
    if(!b)return;
    var to=b.getAttribute("data-to");
    if(to==="returned" && !window.confirm("Send this customer's goods back to Kampala?"))return;
    b.disabled=true; b.textContent="…";
    fetch(base+"/line",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({line_id:parseInt(b.getAttribute("data-line"),10),status:to})})
      .then(function(r){return r.json();}).then(function(d){
        if(d&&d.ok){ location.reload(); }
        else { b.disabled=false; b.textContent=to==="delivered"?"✓ Given":"↩ Back to Kampala"; alert((d&&d.error)?d.error:"Could not save."); }
      }).catch(function(){ b.disabled=false; alert("Network error"); });
  });

  if(act && go){ /* ---- BOX SCAN MODE (v6) ---- */
    var total=parseInt(act.getAttribute("data-total"),10)||0;
    var chips=document.getElementById("scanchips");
    var scnN=document.getElementById("scnN"), bar=document.getElementById("barfill");
    var tScan=document.getElementById("tScan"), tMiss=document.getElementById("tMiss");
    var missbox=document.getElementById("missbox"), misscodes=document.getElementById("misscodes");
    var nums=[], lastCode="", lastT=0, stream=null, detector=null, timer=null;
    function chip(n){ if(nums.indexOf(n)<0){nums.push(n);nums.sort(function(a,b){return a-b;});chips.innerHTML=nums.map(function(x){return '<span class="c">B'+x+'</span>';}).join("");} }
    function applySummary(d){
      var exp=(d.expected!=null)?d.expected:total, sc=(d.scanned!=null)?d.scanned:0, miss=(d.missing!=null)?d.missing:Math.max(0,exp-sc);
      if(scnN)scnN.textContent=sc; if(tScan)tScan.textContent=sc; if(tMiss)tMiss.textContent=miss;
      if(bar)bar.style.width=(exp?Math.round(sc/exp*100):0)+"%";
      if(missbox&&misscodes){ if(d.missing_codes&&d.missing_codes.length){misscodes.textContent=d.missing_codes.join(", ");missbox.style.display="";}else{missbox.style.display="none";} }
    }
    var cam=document.getElementById("cam"), cambtn=document.getElementById("cambtn");
    var mancode=document.getElementById("mancode"), manadd=document.getElementById("manadd");
    function send(code){
      code=(code||"").trim(); if(!code)return;
      var now=Date.now(); if(code===lastCode && now-lastT<2500)return; lastCode=code; lastT=now;
      fetch(base+"/scan",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({code:code})})
        .then(function(r){return r.json();}).then(function(d){
          if(d&&d.ok){ if(d.box_number)chip(d.box_number); applySummary(d); if(navigator.vibrate)navigator.vibrate(40);
            msg.textContent="\u2713 Box "+d.box_number+(d.already?" (already scanned)":" scanned"); }
          else { msg.textContent=(d&&d.error)?d.error:"Scan failed"; }
        }).catch(function(){msg.textContent="Network error";});
    }
    if(manadd)manadd.onclick=function(){send(mancode.value);mancode.value="";};
    if(mancode)mancode.addEventListener("keydown",function(e){if(e.key==="Enter"){e.preventDefault();send(mancode.value);mancode.value="";}});
    function stopCam(){ if(timer)clearTimeout(timer); if(stream){stream.getTracks().forEach(function(t){t.stop();});} stream=null; cam.style.display="none"; cambtn.textContent="\ud83d\udcf7 Scan with camera"; }
    function loop(){ if(!stream)return; detector.detect(cam).then(function(cs){ if(cs&&cs.length)cs.forEach(function(c){send(c.rawValue);}); }).catch(function(){}); timer=setTimeout(loop,500); }
    cambtn.onclick=function(){
      if(stream){stopCam();return;}
      if(!("BarcodeDetector" in window)){ msg.textContent="Camera scanning isn't supported here \u2014 type each box code instead."; return; }
      navigator.mediaDevices.getUserMedia({video:{facingMode:"environment"}}).then(function(s){
        stream=s; cam.srcObject=s; cam.style.display="block"; cam.play();
        cambtn.textContent="\u25a0 Stop camera"; detector=new BarcodeDetector({formats:["qr_code"]}); loop();
      }).catch(function(){msg.textContent="Could not open the camera. Type each box code instead.";});
    };
    go.onclick=function(){
      go.disabled=true; msg.textContent="Confirming\u2026"; stopCam();
      fetch(base+"/scan-confirm",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({})})
        .then(function(r){return r.json();}).then(function(d){
          if(d&&d.ok){ var short=(d.total-d.scanned); msg.textContent="\u2713 Confirmed "+d.scanned+"/"+d.total+(short>0?" \u2014 \u26a0 "+short+" box(es) not scanned, flagged":"")+". Refreshing\u2026"; setTimeout(function(){location.reload();},1100); }
          else { go.disabled=false; msg.textContent=(d&&d.error)?d.error:"Could not confirm."; }
        }).catch(function(){go.disabled=false;msg.textContent="Network error";});
    };
    return;
  }

  /* ---- MANUAL COUNT MODE (no boxes generated) ---- */
  var box=document.getElementById("box"), ph=document.getElementById("photo"), pv=document.getElementById("pv"), data="";
  if(!go||!box)return;
  ph.onchange=function(){var f=ph.files&&ph.files[0];if(!f)return;var rd=new FileReader();rd.onload=function(){data=rd.result;pv.innerHTML='<img src="'+data+'">';};rd.readAsDataURL(f);};
  go.onclick=function(){
    if(box.value===""||Number(box.value)<0){msg.textContent="Enter the number of boxes.";return;}
    if(!data){msg.textContent="Add a photo first.";return;}
    go.disabled=true; msg.textContent="Submitting\u2026";
    fetch(base+"/action",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({action:go.dataset.action,box_count:box.value,photo:data})})
      .then(function(r){return r.json();}).then(function(d){
        if(d&&d.ok){msg.textContent="\u2713 Saved"+((d.exceptions&&d.exceptions.length)?" \u2014 note: box count differs from before":"")+". Refreshing\u2026";setTimeout(function(){location.reload();},900);}
        else{go.disabled=false;msg.textContent=(d&&d.error)?d.error:"Could not save.";}
      }).catch(function(){go.disabled=false;msg.textContent="Network error.";});
  };
})();
JS;
        return str_replace('__BASE__', $base, $js);
    }
}
