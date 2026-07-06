<?php declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\Request;

/** Suppliers — who we buy from. Quick-add from the Book Purchase screen. */
class SupplierController extends Controller
{
    /** GET /papi/suppliers — this tenant's suppliers. */
    public function index(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok'=>false],401);
        $suppliers = Supplier::where('tenant_id',$t->id)->orderBy('name')
            ->get(['id','name','phone'])
            ->map(fn($s)=>['id'=>$s->id,'name'=>$s->name,'phone'=>$s->phone]);
        return response()->json(['ok'=>true,'suppliers'=>$suppliers]);
    }

    /** POST /papi/suppliers — add (or return existing) a supplier by name. */
    public function store(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) return response()->json(['ok'=>false],401);
        $name = trim((string) $request->input('name',''));
        if ($name === '') return response()->json(['ok'=>false,'error'=>'no_name'],422);

        $s = Supplier::firstOrCreate(
            ['tenant_id'=>$t->id, 'name'=>$name],
            ['phone'=>trim((string)$request->input('phone','')) ?: null,
             'note' =>trim((string)$request->input('note','')) ?: null]
        );
        return response()->json(['ok'=>true,'id'=>$s->id,'name'=>$s->name]);
    }
}
