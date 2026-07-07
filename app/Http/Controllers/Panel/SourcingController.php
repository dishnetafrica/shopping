<?php declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\SourcingRequest;
use Illuminate\Http\Request;

/**
 * Sourcing Requests — Asish asks Jayesh to find a price for an item customers want.
 * Access = anyone with the trading panel (Asish posts, Jayesh prices, both see).
 */
class SourcingController extends Controller
{
    private function ok(Request $request)
    {
        $u = $request->user();
        if (! $u || ! $u->tenant_id) return null;
        if (! (bool) $u->tenant->setting('order_system_enabled', false)) return null;
        return $u;
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
                'date'=>optional($r->created_at)->format('Y-m-d H:i'),
            ];
        });
        return response()->json([
            'ok'=>true,'requests'=>$list,
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
        return response()->json(['ok'=>true,'id'=>$r->id]);
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
        return response()->json(['ok'=>true]);
    }

    /** POST /papi/sourcing/{id}/status — close/reopen. */
    public function status(Request $request, int $id)
    {
        $u = $this->ok($request);
        if (! $u) return response()->json(['ok'=>false],403);
        $r = SourcingRequest::where('tenant_id',$u->tenant_id)->find($id);
        if (! $r) return response()->json(['ok'=>false,'error'=>'not_found'],404);
        $s = (string)$request->input('status','closed');
        if (! in_array($s, ['new','priced','closed'], true)) $s = 'closed';
        $r->status = $s; $r->save();
        return response()->json(['ok'=>true,'status'=>$s]);
    }
}
