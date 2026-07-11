<?php declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\SourcingRequest;
use App\Models\Tenant;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\MessageLog;
use Illuminate\Http\Request;

/**
 * Sourcing Requests — Asish asks Jayesh to find a price for an item customers want.
 * Access = anyone with the trading panel (Asish posts, Jayesh prices, both see).
 * The buyer (Jayesh) is auto-asked on WhatsApp and nudged until he replies the price.
 */
class SourcingController extends Controller
{
    /** Max auto-nudges before we stop (so it can never spam the buyer). */
    public const REMINDER_CAP = 6;

    private function ok(Request $request)
    {
        $u = $request->user();
        if (! $u || ! $u->tenant_id) return null;
        if (! (bool) $u->tenant->setting('order_system_enabled', false)) return null;
        return $u;
    }

    private function buyerPhone(Tenant $t): string
    {
        return preg_replace('/[^0-9]/', '', (string) $t->setting('sourcing_buyer_phone', ''));
    }

    /** Send (or re-send) a sourcing request to the buyer on WhatsApp. Best-effort; never throws. */
    public static function sendToBuyer(Tenant $t, SourcingRequest $r, bool $reminder = false, string $context = ''): bool
    {
        try {
            $phone = preg_replace('/[^0-9]/', '', (string) $t->setting('sourcing_buyer_phone', ''));
            $inst  = (string) ($t->whatsapp_instance ?? '');
            if ($phone === '' || $inst === '') return false;

            $name = trim((string) $t->setting('sourcing_buyer_name', '')) ?: 'there';
            $ref  = 'SR-' . $r->id;
            $lines = [];
            if ($context !== '') {
                $lines[] = $context;
            } elseif ($reminder) {
                $lines[] = "\u{23F0} Reminder — still need the Juba price for {$ref}:";
            } else {
                $lines[] = "\u{1F6D2} Hi {$name}, please get me the Juba price — {$ref}:";
            }
            $lines[] = "*{$r->item_name}*";
            if (trim((string) $r->note) !== '') $lines[] = (string) $r->note;
            if ($r->demand) $lines[] = 'Demand: ' . ucfirst((string) $r->demand);
            $lines[] = '';
            $lines[] = "Reply here with the price, e.g.  *{$r->id} 45000*";
            $msg = implode("\n", $lines);

            $gw = app(WhatsAppManager::class)->forTenant($t);
            // On first contact, send the request PHOTO (with the text as caption) so Jayesh can
            // see exactly what to price. Reminders stay text-only (no spammy re-sends).
            $media = (! $reminder && $r->photo_path) ? url('/storage/' . $r->photo_path) : '';
            if ($media !== '' && method_exists($gw, 'sendImage')) {
                try { $gw->sendImage($inst, $phone, $media, $msg); }
                catch (\Throwable $e) { $gw->sendText($inst, $phone, $msg); }
            } else {
                $gw->sendText($inst, $phone, $msg);
            }
            try { MessageLog::record($t->id, $phone, $inst, 'out', 'system', $msg); } catch (\Throwable $e) {}

            $r->reminder_count = (int) $r->reminder_count + ($reminder ? 1 : 0);
            if ($r->notified_at === null) $r->notified_at = now();
            $r->last_reminded_at = now();
            $r->save();
            return true;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('sourcing.notify_failed', ['id' => $r->id, 'err' => $e->getMessage()]);
            return false;
        }
    }

    /** Tell the person who asked (Asish) that the price is in — on WhatsApp. Best-effort. */
    public static function notifyRequester(Tenant $t, SourcingRequest $r): bool
    {
        try {
            $inst = (string) ($t->whatsapp_instance ?? '');
            if ($inst === '' || ! $r->requested_by_user_id) return false;
            $u = \App\Models\User::where('tenant_id', $t->id)->find($r->requested_by_user_id);
            $phone = $u ? preg_replace('/[^0-9]/', '', (string) $u->phone) : '';
            if ($phone === '') return false;

            $cur = $r->currency ?: 'UGX';
            $lines = ["\u{2705} Price in — *{$r->item_name}*", $cur . ' ' . number_format((float) $r->quoted_price)];
            if (trim((string) $r->supplier_name) !== '') $lines[] = 'Supplier: ' . $r->supplier_name;
            if (trim((string) $r->availability) !== '') $lines[] = (string) $r->availability;
            if (trim((string) $r->price_note) !== '') $lines[] = (string) $r->price_note;
            if (trim((string) $r->priced_by) !== '') $lines[] = 'by ' . $r->priced_by;
            $lines[] = "(SR-{$r->id})";
            $msg = implode("\n", $lines);

            $gw = app(WhatsAppManager::class)->forTenant($t);
            // Include the photo so Asish sees exactly which item got priced (helps when he has
            // several similar requests open).
            $media = $r->photo_path ? url('/storage/' . $r->photo_path) : '';
            if ($media !== '' && method_exists($gw, 'sendImage')) {
                try { $gw->sendImage($inst, $phone, $media, $msg); }
                catch (\Throwable $e) { $gw->sendText($inst, $phone, $msg); }
            } else {
                $gw->sendText($inst, $phone, $msg);
            }
            try { MessageLog::record($t->id, $phone, $inst, 'out', 'system', $msg); } catch (\Throwable $e) {}
            return true;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('sourcing.notify_requester_failed', ['id' => $r->id, 'err' => $e->getMessage()]);
            return false;
        }
    }

    public function page(Request $request)
    {
        $u = $request->user();
        if (! $u || ! $u->tenant_id) return redirect('/app/login');
        if (! (bool) $u->tenant->setting('order_system_enabled', false)) abort(404);
        $path = resource_path('panel/sourcing.html');
        if (! is_file($path)) abort(500, 'Sourcing asset missing.');
        return response(file_get_contents($path), 200)
            ->header('Content-Type','text/html; charset=UTF-8')->header('Cache-Control','no-store');
    }

    /** GET /papi/sourcing — list requests (newest first), + counts. */
    public function index(Request $request)
    {
        $u = $this->ok($request);
        if (! $u) return response()->json(['ok'=>false],403);
        $rows = SourcingRequest::where('tenant_id',$u->tenant_id)
            ->orderByRaw("CASE status WHEN 'new' THEN 0 WHEN 'priced' THEN 1 ELSE 2 END")
            ->orderByDesc('id')->limit(300)->get();
        $list = $rows->map(function ($r) {
            return [
                'id'=>$r->id,'item'=>$r->item_name,'note'=>$r->note,'demand'=>$r->demand,
                'status'=>$r->status,'has_photo'=>(bool)$r->photo_path,'photo'=>$r->photo_path ? '/storage/'.$r->photo_path : null,
                'quoted_price'=>$r->quoted_price!==null?(float)$r->quoted_price:null,'currency'=>$r->currency,
                'supplier'=>$r->supplier_name,'availability'=>$r->availability,'price_note'=>$r->price_note,
                'priced_by'=>$r->priced_by,'requested_by'=>$r->requested_by,
                'reminders'=>(int)$r->reminder_count,'notified'=>(bool)$r->notified_at,
                'product_id'=>$r->product_id!==null?(int)$r->product_id:null,
                'decision_note'=>$r->decision_note,
                'date'=>optional($r->created_at)->format('Y-m-d H:i'),
            ];
        });
        $t = $u->tenant;
        return response()->json([
            'ok'=>true,'requests'=>$list,
            'buyer'=>[
                'phone'=>preg_replace('/[^0-9]/','',(string)$t->setting('sourcing_buyer_phone','')),
                'name'=>trim((string)$t->setting('sourcing_buyer_name','')),
                'auto'=>trim((string)($t->whatsapp_instance ?? '')) !== '',
            ],
            'counts'=>[
                'new'=>$rows->where('status','new')->count(),
                'priced'=>$rows->where('status','priced')->count(),
            ],
        ]);
    }

    /** POST /papi/sourcing — Asish posts a request. */
    public function store(Request $request)
    {
        $u = $this->ok($request);
        if (! $u) return response()->json(['ok'=>false],403);
        $name = trim((string)$request->input('item_name',''));
        if ($name === '') return response()->json(['ok'=>false,'error'=>'no_item'],422);
        $demand = in_array((string)$request->input('demand'), ['low','medium','high'], true) ? (string)$request->input('demand') : 'medium';
        $photo = null;
        if ($request->hasFile('photo')) $photo = $request->file('photo')->store("sourcing/t{$u->tenant_id}", 'public');
        $r = SourcingRequest::create([
            'tenant_id'=>$u->tenant_id,'item_name'=>$name,'note'=>trim((string)$request->input('note','')) ?: null,
            'demand'=>$demand,'photo_path'=>$photo,'status'=>'new',
            'requested_by'=>$u->name ?? '','requested_by_user_id'=>$u->id,
        ]);
        // Auto-ask the buyer (Jayesh) on WhatsApp right away.
        $sent = self::sendToBuyer($u->tenant, $r, false);
        return response()->json(['ok'=>true,'id'=>$r->id,'sent'=>$sent]);
    }

    /** GET /papi/sourcing/buyer — the configured buyer (Jayesh) + whether auto-send is possible. */
    public function buyerGet(Request $request)
    {
        $u = $this->ok($request);
        if (! $u) return response()->json(['ok'=>false],403);
        $t = $u->tenant;
        return response()->json(['ok'=>true,
            'phone'=>$this->buyerPhone($t),
            'name'=>trim((string)$t->setting('sourcing_buyer_name','')),
            'wa_ready'=>trim((string)($t->whatsapp_instance ?? '')) !== '',
        ]);
    }

    /** POST /papi/sourcing/buyer — set the buyer's WhatsApp number + name. */
    public function buyerSave(Request $request)
    {
        $u = $this->ok($request);
        if (! $u) return response()->json(['ok'=>false],403);
        $t = $u->tenant;
        $phone = preg_replace('/[^0-9]/', '', (string)$request->input('phone',''));
        $t->putSetting('sourcing_buyer_phone', $phone);
        if ($request->has('name')) $t->putSetting('sourcing_buyer_name', trim((string)$request->input('name','')) ?: null);
        return response()->json(['ok'=>true,'phone'=>$phone]);
    }

    /** POST /papi/sourcing/{id}/remind — nudge the buyer now (manual). */
    public function remind(Request $request, int $id)
    {
        $u = $this->ok($request);
        if (! $u) return response()->json(['ok'=>false],403);
        $r = SourcingRequest::where('tenant_id',$u->tenant_id)->find($id);
        if (! $r) return response()->json(['ok'=>false,'error'=>'not_found'],404);
        if ($r->status !== 'new') return response()->json(['ok'=>false,'error'=>'not_open'],422);
        $ok = self::sendToBuyer($u->tenant, $r, (bool)$r->notified_at);
        return response()->json(['ok'=>$ok,'error'=>$ok?null:'no_whatsapp']);
    }

    /** POST /papi/sourcing/{id}/price — Jayesh adds the price found. */
    public function price(Request $request, int $id)
    {
        $u = $this->ok($request);
        if (! $u) return response()->json(['ok'=>false],403);
        $r = SourcingRequest::where('tenant_id',$u->tenant_id)->find($id);
        if (! $r) return response()->json(['ok'=>false,'error'=>'not_found'],404);
        $price = round((float)$request->input('quoted_price',0), 2);
        if ($price <= 0) return response()->json(['ok'=>false,'error'=>'bad_price'],422);
        $r->quoted_price  = $price;
        $r->currency      = strtoupper((string)$request->input('currency','UGX'));
        $r->supplier_name = trim((string)$request->input('supplier_name','')) ?: null;
        $r->availability  = trim((string)$request->input('availability','')) ?: null;
        $r->price_note    = trim((string)$request->input('price_note','')) ?: null;
        $r->priced_by     = $u->name ?? '';
        $r->priced_at     = now();
        $r->status        = 'priced';
        $r->save();
        // Tell the requester (Asish) — unless they priced their own request.
        if ((int) $r->requested_by_user_id !== (int) $u->id) self::notifyRequester($u->tenant, $r);
        return response()->json(['ok'=>true]);
    }

    /** POST /papi/sourcing/{id}/convert — finalise a priced request into a catalogue product. */
    public function convert(Request $request, int $id)
    {
        $u = $this->ok($request);
        if (! $u) return response()->json(['ok'=>false],403);
        if (! $u->canMenu('products')) return response()->json(['ok'=>false,'error'=>'forbidden'],403);
        $r = SourcingRequest::where('tenant_id',$u->tenant_id)->find($id);
        if (! $r) return response()->json(['ok'=>false,'error'=>'not_found'],404);
        if ($r->quoted_price === null) return response()->json(['ok'=>false,'error'=>'not_priced'],422);
        if ($r->product_id) return response()->json(['ok'=>true,'product_id'=>(int)$r->product_id,'already'=>true]);

        $name = trim((string)$request->input('name','')) ?: trim((string)$r->item_name);
        if ($name === '') return response()->json(['ok'=>false,'error'=>'no_name'],422);

        // Selling price = what you'll sell for in Juba; falls back to the cost if left blank.
        $sell = round((float)$request->input('price', 0), 2);
        if ($sell <= 0) $sell = (float)$r->quoted_price;

        $category = trim((string)$request->input('category',''));
        $scope = $u->categoryScope();
        if (is_array($scope) && $category !== '' && ! in_array($category, $scope, true))
            return response()->json(['ok'=>false,'error'=>'forbidden_category'],403);

        $image = $r->photo_path ? '/storage/'.$r->photo_path : '';

        $p = \App\Models\Product::create([
            'name'       => $name,
            'category'   => $category,
            'keywords'   => trim((string)$request->input('keywords','')),
            'base_price' => $sell,
            'price'      => $sell,
            'cost_price' => (float)$r->quoted_price,      // Jayesh's Juba price is our cost
            'stock'      => (int)$request->input('stock', 0),
            'image_url'  => $image,
            'moq'        => $request->filled('moq') ? max(1,(int)$request->input('moq')) : null,
            'pack_size'  => $request->filled('pack_size') ? max(1,(int)$request->input('pack_size')) : null,
            'unit_label' => trim((string)$request->input('unit_label','')) ?: null,
            'active'     => true,
        ]);

        $r->product_id = $p->id;
        $r->status     = 'closed';
        $r->save();
        return response()->json(['ok'=>true,'product_id'=>$p->id]);
    }

    /** POST /papi/sourcing/{id}/reject — price too high / won't stock. Terminal, with a reason. */
    public function reject(Request $request, int $id)
    {
        $u = $this->ok($request);
        if (! $u) return response()->json(['ok'=>false],403);
        $r = SourcingRequest::where('tenant_id',$u->tenant_id)->find($id);
        if (! $r) return response()->json(['ok'=>false,'error'=>'not_found'],404);
        $r->decision_note = trim((string)$request->input('reason','')) ?: 'Price too high from Kampala — not viable to sell';
        $r->status = 'rejected';
        $r->save();
        // Let the requester know it won't be stocked (best-effort).
        try {
            if ((int)$r->requested_by_user_id !== (int)$u->id && $r->requested_by_user_id) {
                $usr = \App\Models\User::where('tenant_id',$u->tenant_id)->find($r->requested_by_user_id);
                $ph = $usr ? preg_replace('/[^0-9]/','',(string)$usr->phone) : '';
                $inst = (string)($u->tenant->whatsapp_instance ?? '');
                if ($ph !== '' && $inst !== '') {
                    $msg = "\u{1F6AB} Not stocking *{$r->item_name}* (SR-{$r->id}) — {$r->decision_note}.";
                    app(WhatsAppManager::class)->forTenant($u->tenant)->sendText($inst, $ph, $msg);
                    try { MessageLog::record($u->tenant_id, $ph, $inst, 'out', 'system', $msg); } catch (\Throwable $e) {}
                }
            }
        } catch (\Throwable $e) {}
        return response()->json(['ok'=>true,'status'=>'rejected']);
    }

    /** POST /papi/sourcing/{id}/renegotiate — price too high: ask the buyer for a better one and re-chase. */
    public function renegotiate(Request $request, int $id)
    {
        $u = $this->ok($request);
        if (! $u) return response()->json(['ok'=>false],403);
        $r = SourcingRequest::where('tenant_id',$u->tenant_id)->find($id);
        if (! $r) return response()->json(['ok'=>false,'error'=>'not_found'],404);

        $target = trim((string)$request->input('target',''));
        $was = $r->quoted_price !== null ? (($r->currency ?: 'UGX').' '.number_format((float)$r->quoted_price)) : '';
        // Reopen for a fresh price and restart the chaser.
        $r->status = 'new';
        $r->notified_at = null; $r->last_reminded_at = null; $r->reminder_count = 0;
        $r->save();
        $ctx = "\u{26A0}\u{FE0F} The Juba price" . ($was !== '' ? " ({$was})" : '') . " is too high to sell — can you find a *better price* for SR-{$r->id}?"
             . ($target !== '' ? "\nTarget: {$target}" : '');
        $sent = self::sendToBuyer($u->tenant, $r, false, $ctx);
        return response()->json(['ok'=>true,'sent'=>$sent]);
    }

    /** POST /papi/sourcing/{id}/status — close/reopen. */
    public function status(Request $request, int $id)
    {
        $u = $this->ok($request);
        if (! $u) return response()->json(['ok'=>false],403);
        $r = SourcingRequest::where('tenant_id',$u->tenant_id)->find($id);
        if (! $r) return response()->json(['ok'=>false,'error'=>'not_found'],404);
        $s = (string)$request->input('status','closed');
        if (! in_array($s, ['new','priced','closed','rejected'], true)) $s = 'closed';
        $r->status = $s; $r->save();
        return response()->json(['ok'=>true,'status'=>$s]);
    }
}
