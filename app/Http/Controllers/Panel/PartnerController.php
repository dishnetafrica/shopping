<?php declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\OrderCost;
use App\Models\Partner;
use App\Models\PartnerCapital;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Partnership capital accounts. Owner-only. Each capital movement (opening / introduced /
 * drawing) writes a partner_capital ledger row AND a mirrored cash row in order_costs, in
 * one transaction, so Cash Position and the capital accounts always reconcile.
 */
class PartnerController extends Controller
{
    private function guard(Request $request)
    {
        $u = $request->user();
        if (! $u || ! $u->tenant_id) return null;
        if (! (bool) $u->tenant->setting('order_system_enabled', false)) return null;
        if (! (method_exists($u,'isOwnerLike') ? $u->isOwnerLike() : false)) return null;
        return $u;
    }

    public function page(Request $request)
    {
        $u = $request->user();
        if (! $u || ! $u->tenant_id) return redirect('/app/login');
        if (! (bool) $u->tenant->setting('order_system_enabled', false)) abort(404);
        if (! (method_exists($u,'isOwnerLike') ? $u->isOwnerLike() : false)) abort(403);
        $path = resource_path('panel/partners.html');
        if (! is_file($path)) abort(500, 'Partners asset missing.');
        return response(file_get_contents($path), 200)
            ->header('Content-Type','text/html; charset=UTF-8')->header('Cache-Control','no-store');
    }

    /** GET /papi/partners — partners with computed capital (USD + per currency). */
    public function index(Request $request)
    {
        $u = $this->guard($request);
        if (! $u) return response()->json(['ok'=>false],403);
        $t = $u->tenant;

        $partners = Partner::where('tenant_id',$t->id)->orderBy('name')->get();
        $cap = PartnerCapital::where('tenant_id',$t->id)->get();

        $byPartner = [];
        foreach ($cap as $c) {
            $sign = ($c->kind === 'drawing') ? -1 : 1;
            $pid = $c->partner_id;
            if (! isset($byPartner[$pid])) $byPartner[$pid] = ['usd'=>0.0, 'cur'=>[]];
            $byPartner[$pid]['usd'] += $sign * (float)$c->usd_amount;
            $byPartner[$pid]['cur'][$c->currency] = ($byPartner[$pid]['cur'][$c->currency] ?? 0) + $sign * (float)$c->native_amount;
        }

        $out = $partners->map(function ($p) use ($byPartner) {
            $b = $byPartner[$p->id] ?? ['usd'=>0.0,'cur'=>[]];
            $cur = [];
            foreach ($b['cur'] as $k=>$v) if (abs($v) > 0.005) $cur[] = ['currency'=>$k,'amount'=>round($v,2)];
            return [
                'id'=>$p->id, 'name'=>$p->name, 'user_id'=>$p->user_id,
                'ownership_pct'=>(float)$p->ownership_pct,
                'capital_usd'=>round($b['usd'],2),
                'by_currency'=>$cur,
            ];
        })->values();

        $totalUsd = round($out->sum('capital_usd'), 2);
        $totalPct = round($partners->sum('ownership_pct'), 2);
        return response()->json(['ok'=>true,'partners'=>$out,'total_usd'=>$totalUsd,'total_pct'=>$totalPct]);
    }

    /** POST /papi/partners — add a partner. */
    public function store(Request $request)
    {
        $u = $this->guard($request);
        if (! $u) return response()->json(['ok'=>false],403);
        $name = trim((string)$request->input('name',''));
        if ($name === '') return response()->json(['ok'=>false,'error'=>'no_name'],422);
        $p = Partner::firstOrCreate(
            ['tenant_id'=>$u->tenant_id,'name'=>$name],
            ['ownership_pct'=>(float)$request->input('ownership_pct',0),
             'user_id'=>$request->filled('user_id') ? (int)$request->input('user_id') : null]
        );
        return response()->json(['ok'=>true,'id'=>$p->id,'name'=>$p->name]);
    }

    /** POST /papi/partners/{id}/pct — set ownership %. */
    public function setPct(Request $request, int $id)
    {
        $u = $this->guard($request);
        if (! $u) return response()->json(['ok'=>false],403);
        $p = Partner::where('tenant_id',$u->tenant_id)->find($id);
        if (! $p) return response()->json(['ok'=>false,'error'=>'bad_partner'],404);
        $p->ownership_pct = (float)$request->input('ownership_pct',0);
        $p->save();
        return response()->json(['ok'=>true,'ownership_pct'=>(float)$p->ownership_pct]);
    }

    /**
     * POST /papi/partners/{id}/capital — record opening / introduced / drawing.
     * Writes the capital ledger row AND a mirrored cash row (funding for in, drawing for
     * out) so Cash Position moves by the same amount, in one transaction.
     */
    public function capital(Request $request, int $id)
    {
        $u = $this->guard($request);
        if (! $u) return response()->json(['ok'=>false],403);
        $t = $u->tenant;
        $p = Partner::where('tenant_id',$t->id)->find($id);
        if (! $p) return response()->json(['ok'=>false,'error'=>'bad_partner'],404);

        $kind = (string)$request->input('kind','introduced');
        if (! in_array($kind, ['opening','introduced','drawing'], true)) $kind = 'introduced';
        $cur    = strtoupper((string)$request->input('currency','UGX'));
        $amount = round((float)$request->input('amount',0), 2);
        if ($amount <= 0) return response()->json(['ok'=>false,'error'=>'bad_amount'],422);
        $rate = (float)$request->input('rate_to_usd', 0);
        if ($rate <= 0) $rate = $cur==='USD' ? 1.0 : (float)$t->setting('rate_'.strtolower($cur).'_per_usd', $cur==='UGX'?3700:5180);
        if ($rate <= 0) $rate = 1;
        $usd  = OrderCost::toUsd($amount, $rate);
        $note = trim((string)$request->input('note','')) ?: (ucfirst($kind).' capital — '.$p->name);

        // cash side: opening/introduced = money IN (funding); drawing = money OUT
        $stage      = ($kind === 'drawing') ? 'drawing' : 'funding';
        $holderName = $p->name;
        $holderUid  = $p->user_id;

        $result = DB::transaction(function () use ($t,$p,$kind,$cur,$amount,$rate,$usd,$note,$stage,$holderName,$holderUid,$u) {
            $c = OrderCost::create([
                'tenant_id'=>$t->id,'order_id'=>null,'order_no'=>null,
                'stage'=>$stage,'currency'=>$cur,'native_amount'=>$amount,'rate_to_usd'=>$rate,
                'usd_amount'=>$usd,'holder'=>$holderName,'holder_user_id'=>$holderUid,
                'pay_status'=>'cash','paid_native'=>$amount,
                'note'=>$note,'status'=>'approved','created_by'=>$u->name ?? '',
            ]);
            $cap = PartnerCapital::create([
                'tenant_id'=>$t->id,'partner_id'=>$p->id,'kind'=>$kind,'currency'=>$cur,
                'native_amount'=>$amount,'rate_to_usd'=>$rate,'usd_amount'=>$usd,
                'note'=>$note,'order_cost_id'=>$c->id,'created_by'=>$u->name ?? '',
            ]);
            return ['cap_id'=>$cap->id,'cost_id'=>$c->id];
        });

        return response()->json(['ok'=>true,'kind'=>$kind,'usd'=>$usd,'currency'=>$cur,'amount'=>$amount] + $result);
    }

    /**
     * POST /papi/partners/distribute — allocate profit to partners by ownership %.
     * Capital-only: this is retained earnings moving into capital accounts, so it does
     * NOT touch cash (unlike opening/introduced). Splits exactly (last partner absorbs
     * the rounding remainder). Amount is in USD.
     */
    public function distribute(Request $request)
    {
        $u = $this->guard($request);
        if (! $u) return response()->json(['ok'=>false],403);
        $t = $u->tenant;
        $amount = round((float)$request->input('amount_usd',0), 2);
        if ($amount <= 0) return response()->json(['ok'=>false,'error'=>'bad_amount'],422);
        $note = trim((string)$request->input('note','')) ?: 'Profit distribution';

        $partners = Partner::where('tenant_id',$t->id)->where('active',true)
            ->where('ownership_pct','>',0)->orderBy('id')->get()->values();
        $sumPct = (float)$partners->sum('ownership_pct');
        if ($partners->count() === 0 || $sumPct <= 0) return response()->json(['ok'=>false,'error'=>'no_pct'],422);

        $n = $partners->count(); $acc = 0.0; $out = [];
        DB::transaction(function () use ($partners,$n,$amount,$sumPct,$note,$t,$u,&$acc,&$out) {
            for ($i = 0; $i < $n; $i++) {
                $p = $partners[$i];
                $share = ($i === $n-1) ? round($amount - $acc, 2) : round($amount * (float)$p->ownership_pct / $sumPct, 2);
                if ($i < $n-1) $acc += $share;
                if (abs($share) < 0.005) continue;
                PartnerCapital::create([
                    'tenant_id'=>$t->id,'partner_id'=>$p->id,'kind'=>'profit_share','currency'=>'USD',
                    'native_amount'=>$share,'rate_to_usd'=>1,'usd_amount'=>$share,
                    'note'=>$note,'order_cost_id'=>null,'created_by'=>$u->name ?? '',
                ]);
                $out[] = ['name'=>$p->name,'usd'=>$share];
            }
        });
        return response()->json(['ok'=>true,'distributed'=>$amount,'rows'=>$out,'note'=>$note]);
    }
}
