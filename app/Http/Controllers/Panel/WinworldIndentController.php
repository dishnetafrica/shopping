<?php
namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\WwCustomer;
use App\Models\WwIndent;
use App\Models\WwItem;
use App\Models\WwMaterial;
use App\Models\WwPlanning;
use App\Services\Winworld\Blending;
use App\Services\Winworld\Formula;
use App\Services\Winworld\IndentBuilder;
use App\Services\Winworld\StatusFlow;
use Illuminate\Http\Request;

/**
 * Win World Order Indent + Planning. Serves the two panel pages and the
 * papi endpoints behind them. Tenant scoping is automatic. Complex writes
 * (nested blend lines) use POST/JSON, like lead-import.
 */
class WinworldIndentController extends Controller
{
    /* ---------- pages ---------- */
    public function indentsPage(Request $r)  { return $this->serve($r, 'indent.html'); }
    public function planningPage(Request $r) { return $this->serve($r, 'planning.html'); }

    private function serve(Request $r, string $file)
    {
        $u = $r->user();
        if (! $u || ! $u->tenant_id) return redirect('/app/login');
        $path = resource_path('panel/' . $file);
        if (! is_file($path)) abort(500, 'Panel asset missing.');
        $name = (string) ($u->tenant->name ?? 'Win World');
        $html = str_replace('{{WW_TENANT}}', htmlspecialchars($name, ENT_QUOTES), file_get_contents($path));
        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8')->header('Cache-Control', 'no-store');
    }

    /* ---------- lookups ---------- */
    public function options(Request $r)
    {
        return response()->json([
            'ok' => true,
            'customers' => WwCustomer::orderBy('name')->get(['id','name','overdue_days']),
            'items'     => WwItem::where('status','Active')->orderBy('item_code')
                            ->get(['id','item_code','item_name','gram_per_pcs','width_inch','length_inch','gauge']),
            'materials' => WwMaterial::where('active',true)->orderBy('material_name')->get(['id','material_name']),
        ]);
    }

    /* ---------- indents ---------- */
    public function indentList(Request $r)
    {
        $rows = WwIndent::orderByDesc('id')->limit(150)
            ->get(['id','indent_no','customer_name','product_name','order_qty_pcs','order_kg','status','date_of_indent','priority']);
        return response()->json(['ok' => true, 'indents' => $rows]);
    }

    public function indentGet(Request $r)
    {
        $indent = WwIndent::with(['blends','plannings'])->find((int)$r->query('id'));
        if (! $indent) return response()->json(['ok'=>false,'error'=>'Not found'],404);
        return response()->json(['ok'=>true,'indent'=>$indent,'blends'=>$indent->blends,'plannings'=>$indent->plannings]);
    }

    /** Build clone payload from a previous indent (not persisted — for prefill). */
    public function indentClone(Request $r)
    {
        $src = WwIndent::with('blends')->find((int)$r->query('id'));
        if (! $src) return response()->json(['ok'=>false,'error'=>'Not found'],404);
        $data = IndentBuilder::cloneData($src->toArray(), $src->blends->toArray());
        return response()->json(['ok'=>true] + $data);
    }

    public function indentSave(Request $r)
    {
        $in = $r->all();
        $id = (int) ($in['id'] ?? 0);
        $indent = $id ? WwIndent::find($id) : new WwIndent();
        if (! $indent) return response()->json(['ok'=>false,'error'=>'Not found'],404);

        // resolve item dims -> gram/pcs -> order_kg
        $item = ! empty($in['item_id']) ? WwItem::find((int)$in['item_id']) : null;
        $gram = $item?->gram_per_pcs;
        if ($item && ($gram === null || (float)$gram <= 0)) {
            $gram = Formula::gramPerPcs((float)$item->width_inch,(float)$item->length_inch,(float)$item->gauge);
        }
        $qty = (int) ($in['order_qty_pcs'] ?? 0);
        $orderKg = round(Formula::orderKg($qty, (float)$gram), 3);

        $date = ! empty($in['date_of_indent']) ? $in['date_of_indent'] : now()->toDateString();
        $indentNo = trim((string)($in['indent_no'] ?? ''));
        if ($indentNo === '') {
            $indentNo = IndentBuilder::nextIndentNo((int)WwIndent::count() + 1, new \DateTimeImmutable($date));
        }

        $fields = ['indent_no'=>$indentNo,'doc_ref'=>WwIndent::DOC_REF,'date_of_indent'=>$date,
            'customer_id'=>$in['customer_id'] ?? null,'customer_name'=>$in['customer_name'] ?? '',
            'item_id'=>$in['item_id'] ?? null,'product_name'=>$in['product_name'] ?? '',
            'sales_person'=>$in['sales_person'] ?? null,'order_qty_pcs'=>$qty,
            'mixing_qty'=>$in['mixing_qty'] ?? null,'priority'=>$in['priority'] ?? 'Normal',
            'sample_available'=>! empty($in['sample_available']),'sdh_remarks'=>$in['sdh_remarks'] ?? null,
            'pdh_remarks'=>$in['pdh_remarks'] ?? null,'order_kg'=>$orderKg,
            'needs_blending'=>! empty($in['needs_blending']),'needs_extrusion'=>! empty($in['needs_extrusion']),
            'needs_printing'=>! empty($in['needs_printing']),'needs_cutting'=>! empty($in['needs_cutting']),
        ];
        foreach (['ext_width','ext_gusset','ext_gauge','ext_film_colour','ext_weight_per_roll','ext_type_of_roll',
                  'prn_specification','prn_no_colours','prn_colours','prn_single_double','prn_direction','prn_gap_top','prn_gap_bottom',
                  'cut_type','cut_bag_size','cut_sealing','cut_bottom_gusset','cut_handle_punch','cut_handle_position','cut_hole_punch','cut_hole_positions'] as $f) {
            $fields[$f] = $in[$f] ?? null;
        }
        foreach (['ext_sample','prn_sample','cut_sample'] as $f) $fields[$f] = ! empty($in[$f]);
        if (! $id) $fields['status'] = 'Open';

        $indent->fill($fields)->save();

        // blend lines: recompute quantities via the engine, then replace
        $lines = is_array($in['blends'] ?? null) ? $in['blends'] : [];
        $engineLines = array_map(fn($b) => [
            'material' => $b['material_name'] ?? '',
            'pct_a' => (float)($b['pct_a'] ?? 0), 'pct_b' => (float)($b['pct_b'] ?? 0), 'pct_c' => (float)($b['pct_c'] ?? 0),
        ], $lines);
        $blend = Blending::compute((float)($in['mixing_qty'] ?? 0), $engineLines);

        $indent->blends()->delete();
        foreach ($blend['lines'] as $i => $bl) {
            $indent->blends()->create([
                'line_no'=>$i+1,'material_id'=>$lines[$i]['material_id'] ?? null,'material_name'=>$bl['material'],
                'pct_a'=>$bl['pct_a'],'qty_a'=>$bl['qty_a'],'pct_b'=>$bl['pct_b'],'qty_b'=>$bl['qty_b'],'pct_c'=>$bl['pct_c'],'qty_c'=>$bl['qty_c'],
            ]);
        }

        return response()->json(['ok'=>true,'id'=>$indent->id,'indent_no'=>$indent->indent_no,
            'order_kg'=>$orderKg,'blend_totals'=>$blend['totals'],'blend_total_kgs'=>$blend['total_kgs'],'blend_ok'=>$blend['ok']]);
    }

    /* ---------- planning ---------- */
    public function planList(Request $r)
    {
        $indents = WwIndent::with(['plannings.machine:id,machine'])
            ->whereIn('status',['Open','Planned','In Process'])
            ->orderByDesc('id')->limit(120)->get();

        $out = $indents->map(fn(WwIndent $x) => [
            'id'=>$x->id,'indent_no'=>$x->indent_no,'customer'=>$x->customer_name,'product'=>$x->product_name,
            'order_kg'=>$x->order_kg !== null ? (float)$x->order_kg : null,'status'=>$x->status,
            'processes'=>$x->activeProcesses(),
            'plannings'=>$x->plannings->map(fn($p)=>[
                'id'=>$p->id,'process'=>$p->process,'machine_id'=>$p->machine_id,'machine'=>$p->machine?->machine,
                'running_speed'=>$p->running_speed,'planned_start'=>optional($p->planned_start)->format('Y-m-d H:i'),
                'final_output_kg_hr'=>$p->final_output_kg_hr,'required_hours'=>$p->required_hours,
                'planned_end'=>optional($p->planned_end)->format('Y-m-d H:i'),'status'=>$p->status,
            ]),
        ]);
        return response()->json(['ok'=>true,'indents'=>$out]);
    }

    public function planSave(Request $r)
    {
        $in = $r->all();
        $indent = WwIndent::find((int)($in['indent_id'] ?? 0));
        if (! $indent) return response()->json(['ok'=>false,'error'=>'Indent not found'],404);

        $orderKg = $indent->order_kg !== null ? (float)$indent->order_kg : $indent->recomputeOrderKg();

        $id = (int)($in['id'] ?? 0);
        $plan = $id ? WwPlanning::find($id) : new WwPlanning();
        if (! $plan) return response()->json(['ok'=>false,'error'=>'Plan row not found'],404);

        $plan->fill([
            'indent_id'=>$indent->id,'process'=>$in['process'] ?? 'Extrusion','machine_id'=>$in['machine_id'] ?? null,
            'running_speed'=>$in['running_speed'] ?? null,'planned_start'=>$in['planned_start'] ?? null,
            'auto_output_kg_hr'=>$in['auto_output_kg_hr'] ?? null,'manual_output_kg_hr'=>$in['manual_output_kg_hr'] ?? null,
            'notes'=>$in['notes'] ?? null,
        ]);
        if (! $id) $plan->status = 'Planned';
        $plan->recompute($orderKg);   // engine: final output, required hours, planned end
        $plan->save();

        $indent->status = StatusFlow::advance($indent->status, 'Planned');
        $indent->save();

        return response()->json(['ok'=>true,'planning'=>[
            'id'=>$plan->id,'process'=>$plan->process,'machine_id'=>$plan->machine_id,
            'final_output_kg_hr'=>(float)$plan->final_output_kg_hr,'required_hours'=>(float)$plan->required_hours,
            'planned_end'=>optional($plan->planned_end)->format('Y-m-d H:i'),'status'=>$plan->status,
        ],'indent_status'=>$indent->status]);
    }
}
