<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\WwCustomer;
use App\Models\WwException;
use App\Models\WwIndent;
use App\Models\WwIndentBlend;
use App\Models\WwIndentQc;
use App\Models\WwItem;
use App\Models\WwMachine;
use App\Models\WwMaterial;
use App\Models\WwPlanning;
use App\Models\WwProductionEntry;
use App\Models\WwSalesEvent;
use App\Models\WwSalesOrder;
use App\Services\Winworld\SalesFlow;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Demo dataset for Win World — fills every screen with a coherent, realistic
 * scenario so the operation can be understood end-to-end:
 *   masters → indents → planning → production (OEE/downtime) → QC sign-offs
 *   → sales pipeline (with SLA + MD gate) → exceptions.
 * Idempotent: re-running skips if demo data is already present.
 */
class WinworldDemoSeeder extends Seeder
{
    public function run(): void
    {
        $t = Tenant::where('slug', 'winworld')->orWhere('slug', 'galaxypack')->orWhere('slug', 'galaxy')
            ->first()
            ?? Tenant::whereRaw('LOWER(name) LIKE ? OR LOWER(name) LIKE ?', ['%win world%', '%galaxy%'])->first();

        if (! $t) { $this->command->error('WinworldDemoSeeder: no Win World tenant found.'); return; }
        app(TenantContext::class)->set($t->id);
        $tid = $t->id;
        $this->command->info("WinworldDemoSeeder: seeding demo for tenant #{$tid} ({$t->name}).");

        if (WwCustomer::where('customer_code', 'WW-DEMO-1')->exists()) {
            $this->command->warn('WinworldDemoSeeder: demo data already present — skipping.');
            return;
        }

        $machines = WwMachine::pluck('id', 'machine'); // name => id
        $mid = fn (string $name) => $machines[$name] ?? $machines->first();

        /* ---------------- CUSTOMERS ---------------- */
        $cust = [];
        $cust['nile']   = WwCustomer::create(['tenant_id'=>$tid,'customer_code'=>'WW-DEMO-1','name'=>'Nile Beverages Ltd','credit_limit_days'=>30,'ageing_balance'=>4200000,'overdue_days'=>5,'contact'=>'256770000001']);
        $cust['mukwano']= WwCustomer::create(['tenant_id'=>$tid,'customer_code'=>'WW-DEMO-2','name'=>'Mukwano Industries','credit_limit_days'=>45,'ageing_balance'=>0,'overdue_days'=>0,'contact'=>'256770000002']);
        $cust['cafe']   = WwCustomer::create(['tenant_id'=>$tid,'customer_code'=>'WW-DEMO-3','name'=>'Cafe Javas','credit_limit_days'=>30,'ageing_balance'=>9800000,'overdue_days'=>45,'contact'=>'256770000003']);
        $cust['pharma'] = WwCustomer::create(['tenant_id'=>$tid,'customer_code'=>'WW-DEMO-4','name'=>'Kampala Pharma','credit_limit_days'=>30,'ageing_balance'=>1500000,'overdue_days'=>12,'contact'=>'256770000004']);

        /* ---------------- ITEMS ---------------- */
        $mkItem = function (string $code, string $name, string $grp, $w, $l, $g) use ($tid) {
            $i = WwItem::create(['tenant_id'=>$tid,'item_code'=>$code,'item_name'=>$name,'item_group'=>$grp,'width_inch'=>$w,'length_inch'=>$l,'gauge'=>$g,'status'=>'active']);
            $i->recomputeGramPerPcs(); $i->save(); return $i;
        };
        $itLd   = $mkItem('WW-D-LD12','LD Bag 12x18','Bag',12,18,200);
        $itLine = $mkItem('WW-D-LN24','Liner 24x36','Liner',24,36,150);
        $itShop = $mkItem('WW-D-SHOP','Printed Shopping Bag','Bag',15,20,250);

        /* ---------------- MATERIALS (reuse or create) ---------------- */
        $matName = function (string $like, string $code, string $name, string $type) use ($tid) {
            $m = WwMaterial::whereRaw('LOWER(material_name) LIKE ?', ['%'.strtolower($like).'%'])->first();
            return $m ?: WwMaterial::create(['tenant_id'=>$tid,'material_code'=>$code,'material_name'=>$name,'type'=>$type,'uom'=>'kg','active'=>true]);
        };
        $mLd = $matName('ldpe','WW-D-LDPE','LDPE','Resin');
        $mHd = $matName('hdpe','WW-D-HDPE','HDPE','Resin');
        $mMb = $matName('master','WW-D-MB','Masterbatch','Additive');

        /* ---------------- INDENTS ---------------- */
        // I1 — full process job (Mukwano, LD bags)
        $i1 = WwIndent::create([
            'tenant_id'=>$tid,'indent_no'=>'001-DEMO','doc_ref'=>WwIndent::DOC_REF,'customer_id'=>$cust['mukwano']->id,'customer_name'=>$cust['mukwano']->name,
            'item_id'=>$itLd->id,'product_name'=>$itLd->item_name,'sales_person'=>'SDH','date_of_indent'=>now()->subDays(4)->toDateString(),
            'required_date'=>now()->addDays(2)->toDateString(),'order_qty_pcs'=>50000,'mixing_qty'=>500,'priority'=>'High','sample_available'=>true,
            'sdh_remarks'=>'Repeat customer — standard recipe.','status'=>'In Process',
            'needs_blending'=>true,'needs_extrusion'=>true,'needs_printing'=>true,'needs_cutting'=>true,
            'ext_width'=>'12 inch','ext_gusset'=>'0','ext_gauge'=>'200','ext_film_colour'=>'Natural','ext_weight_per_roll'=>'25 kg','ext_type_of_roll'=>'Plain',
            'prn_specification'=>'Logo + text','prn_no_colours'=>'0+4','prn_colours'=>'White/Yellow/Cyan/Brown','prn_single_double'=>'Single','prn_direction'=>'Forward','prn_gap_top'=>'10mm','prn_gap_bottom'=>'10mm','prn_sample'=>true,
            'cut_type'=>'Bottom seal','cut_bag_size'=>'12x18','cut_sealing'=>'Heat','cut_bottom_gusset'=>'No','cut_handle_punch'=>'D-punch','cut_handle_position'=>'Top centre','cut_hole_punch'=>'None','cut_hole_positions'=>'-','cut_sample'=>true,
        ]);
        $i1->recomputeOrderKg(); $i1->save();
        WwIndentBlend::create(['tenant_id'=>$tid,'indent_id'=>$i1->id,'line_no'=>1,'material_id'=>$mLd->id,'material_name'=>$mLd->material_name,'pct_a'=>80,'qty_a'=>400,'pct_b'=>0,'qty_b'=>0,'pct_c'=>0,'qty_c'=>0]);
        WwIndentBlend::create(['tenant_id'=>$tid,'indent_id'=>$i1->id,'line_no'=>2,'material_id'=>$mHd->id,'material_name'=>$mHd->material_name,'pct_a'=>15,'qty_a'=>75,'pct_b'=>0,'qty_b'=>0,'pct_c'=>0,'qty_c'=>0]);
        WwIndentBlend::create(['tenant_id'=>$tid,'indent_id'=>$i1->id,'line_no'=>3,'material_id'=>$mMb->id,'material_name'=>$mMb->material_name,'pct_a'=>5,'qty_a'=>25,'pct_b'=>0,'qty_b'=>0,'pct_c'=>0,'qty_c'=>0]);

        // I2 — extrusion only (Nile, liner)
        $i2 = WwIndent::create([
            'tenant_id'=>$tid,'indent_no'=>'002-DEMO','doc_ref'=>WwIndent::DOC_REF,'customer_id'=>$cust['nile']->id,'customer_name'=>$cust['nile']->name,
            'item_id'=>$itLine->id,'product_name'=>$itLine->item_name,'sales_person'=>'SDH','date_of_indent'=>now()->subDays(2)->toDateString(),
            'required_date'=>now()->addDays(3)->toDateString(),'order_qty_pcs'=>30000,'mixing_qty'=>300,'priority'=>'Normal','sample_available'=>false,
            'sdh_remarks'=>'Plain liner, no print.','status'=>'Planned','needs_extrusion'=>true,
            'ext_width'=>'24 inch','ext_gusset'=>'0','ext_gauge'=>'150','ext_film_colour'=>'Natural','ext_weight_per_roll'=>'30 kg','ext_type_of_roll'=>'Plain',
        ]);
        $i2->recomputeOrderKg(); $i2->save();

        // I3 — at-risk job (Pharma, shopping bag) — required today, will finish late
        $i3 = WwIndent::create([
            'tenant_id'=>$tid,'indent_no'=>'003-DEMO','doc_ref'=>WwIndent::DOC_REF,'customer_id'=>$cust['pharma']->id,'customer_name'=>$cust['pharma']->name,
            'item_id'=>$itShop->id,'product_name'=>$itShop->item_name,'sales_person'=>'SDH','date_of_indent'=>now()->subDays(1)->toDateString(),
            'required_date'=>now()->toDateString(),'order_qty_pcs'=>20000,'mixing_qty'=>250,'priority'=>'High','sample_available'=>true,
            'sdh_remarks'=>'Urgent — pharmacy launch.','status'=>'In Process',
            'needs_extrusion'=>true,'needs_printing'=>true,'needs_cutting'=>true,
            'ext_width'=>'15 inch','ext_gusset'=>'2 inch','ext_gauge'=>'250','ext_film_colour'=>'White','ext_weight_per_roll'=>'20 kg','ext_type_of_roll'=>'Gusseted',
            'prn_specification'=>'Pharmacy branding','prn_no_colours'=>'0+3','prn_colours'=>'Green/Blue/Black','prn_single_double'=>'Double','prn_direction'=>'Forward','prn_gap_top'=>'8mm','prn_gap_bottom'=>'8mm','prn_sample'=>true,
            'cut_type'=>'Patch handle','cut_bag_size'=>'15x20','cut_sealing'=>'Heat','cut_bottom_gusset'=>'Yes','cut_handle_punch'=>'Patch','cut_handle_position'=>'Top','cut_hole_punch'=>'None','cut_hole_positions'=>'-','cut_sample'=>true,
        ]);
        $i3->recomputeOrderKg(); $i3->save();

        /* ---------------- PLANNING ---------------- */
        $plan = function (WwIndent $ind, string $proc, string $machine, float $speed, Carbon $start, float $manualKgHr) use ($tid, $mid) {
            $p = WwPlanning::create(['tenant_id'=>$tid,'indent_id'=>$ind->id,'process'=>$proc,'machine_id'=>$mid($machine),'running_speed'=>$speed,
                'planned_start'=>$start,'manual_output_kg_hr'=>$manualKgHr,'status'=>'Planned']);
            $p->recompute((float) $ind->order_kg); $p->save(); return $p;
        };
        $p1e = $plan($i1,'Extrusion','A-1',120, now()->subDays(3), 45);
        $p1p = $plan($i1,'Printing','FP-01',90, now()->subDays(2), 40);
        $p1c = $plan($i1,'Cutting','SS-1',100, now()->subDays(1), 50);
        $p2e = $plan($i2,'Extrusion','ABA',140, now()->addDay(), 60);
        $p3e = $plan($i3,'Extrusion','A-2',110, now(), 18);   // small rate -> long hours -> finishes after required date
        $p3p = $plan($i3,'Printing','FP-02',80, now()->addHours(6), 16);
        $p3c = $plan($i3,'Cutting','BS-3',95, now()->addHours(10), 20);

        /* ---------------- PRODUCTION ENTRIES (for OEE + downtime) ---------------- */
        $entry = function (WwIndent $ind, ?WwPlanning $pl, string $proc, string $machine, Carbon $start, int $mins, float $kg, float $scrap, float $target, ?string $stop=null, string $qc='pass') use ($tid, $mid) {
            $e = WwProductionEntry::create([
                'tenant_id'=>$tid,'indent_id'=>$ind->id,'planning_id'=>$pl?->id,'process'=>$proc,'machine_id'=>$mid($machine),'shift'=>'Day',
                'start_time'=>$start,'end_time'=>(clone $start)->addMinutes($mins),'produced_qty_pcs'=>(int)round($kg*40),'produced_kg'=>$kg,'scrap_kg'=>$scrap,
                'changeover_min'=>15,'target_output_kg_hr'=>$target,'qc_result'=>$qc,'status'=>$stop?'Stopped':'Completed','stop_reason'=>$stop,
                'remarks'=>$stop?('Lost time: '.$stop):'OK',
            ]);
            $e->recompute(); $e->save(); return $e;
        };
        // good + slow + stops + a reject, spread over recent days
        $entry($i1,$p1e,'Extrusion','A-1', now()->subDays(3)->setTime(8,0),  300, 207, 6,  45);              // ~92%
        $entry($i1,$p1e,'Extrusion','A-1', now()->subDays(2)->setTime(8,0),  300, 135, 5,  45, null,'pass'); // ~60% slow
        $entry($i1,$p1e,'Extrusion','A-1', now()->subDays(2)->setTime(14,0), 90,  20,  2,  45,'Power Failure');
        $entry($i2,$p2e,'Extrusion','ABA', now()->subDays(2)->setTime(9,0),  120, 30,  3,  60,'Material Shortage');
        $entry($i1,$p1p,'Printing','FP-01',now()->subDays(1)->setTime(8,0),  240, 120, 22, 40, null,'reject'); // QC reject + high scrap
        $entry($i1,$p1c,'Cutting','SS-1',  now()->subDays(1)->setTime(13,0), 300, 235, 4,  50);              // good
        $entry($i3,$p3e,'Extrusion','A-2', now()->setTime(8,0),               180, 28,  3,  18,'Machine Breakdown');
        $entry($i3,$p3p,'Printing','FP-02',now()->setTime(11,0),              150, 38,  2,  16);
        $entry($i3,$p3c,'Cutting','BS-3',  now()->setTime(14,0),              180, 58,  3,  20);

        /* ---------------- QC SIGN-OFFS (OIF) ---------------- */
        WwIndentQc::create(['tenant_id'=>$tid,'indent_id'=>$i1->id,'process'=>'Extrusion','production_at'=>now()->subDays(3),'supervisor_sign'=>'Okello','supervisor_at'=>now()->subDays(3),'qc_sign'=>'Nakato','qc_at'=>now()->subDays(3),'sec_head_sign'=>'Mensah','sec_head_at'=>now()->subDays(3),'result'=>'pass']);
        WwIndentQc::create(['tenant_id'=>$tid,'indent_id'=>$i1->id,'process'=>'Printing','production_at'=>now()->subDay(),'supervisor_sign'=>'Okello','supervisor_at'=>now()->subDay(),'result'=>'pass']); // partial
        WwIndentQc::create(['tenant_id'=>$tid,'indent_id'=>$i3->id,'process'=>'Extrusion','production_at'=>now(),'supervisor_sign'=>'Achan','supervisor_at'=>now(),'qc_sign'=>'Nakato','qc_at'=>now(),'result'=>'pass']); // sec-head pending

        /* ---------------- SALES PIPELINE ---------------- */
        $no = 1;
        $so = function (string $stage, $c, string $product, int $qty, float $value, ?Carbon $started=null, array $approvals=[], int $overdue=0, ?string $status='open') use (&$no, $tid) {
            $o = new WwSalesOrder(['tenant_id'=>$tid,'customer_id'=>$c->id,'customer_name'=>$c->name,'contact'=>$c->contact,'source'=>'whatsapp',
                'product_name'=>$product,'qty'=>$qty,'value'=>$value,'overdue_days'=>$overdue,'evidence'=>'WhatsApp enquiry screenshot','status'=>$status ?? 'open']);
            $o->order_no = 'SO-D'.str_pad((string)$no++, 2, '0', STR_PAD_LEFT);
            $o->stage = $stage;
            $o->stage_started_at = $started ?: now();
            $o->applyStage(); // owner_role + sla_due_at from stage_started_at
            $o->save();
            foreach ($approvals as $role) {
                WwSalesEvent::create(['tenant_id'=>$tid,'sales_order_id'=>$o->id,'stage'=>$stage,'action'=>'approve','role'=>$role,'actor'=>strtoupper($role).' Demo','note'=>strtoupper($role).' approved','at'=>now()]);
            }
            return $o;
        };
        $so('enquiry',        $cust['mukwano'],'HD liners 18x24', 40000, 5200000, now()->subMinutes(20));
        $so('order_received', $cust['nile'],   'LD bags 10x14',   25000, 3100000, now()->subHours(3)); // SLA overdue (1h) -> red + breach
        $so('credit_check',   $cust['cafe'],   'Printed bags',    18000, 7400000, now()->subMinutes(30), [], 45); // overdue 45d -> MD gate
        $so('sap_approval',   $cust['pharma'], 'Pharma pouches',  20000, 6100000, now()->subMinutes(40), ['sm']); // SM done, MD pending
        $so('order_indent',   $cust['mukwano'],'LD bags 12x18',   50000, 8900000, now()->subMinutes(15));
        $so('delivery',       $cust['nile'],   'Liner 24x36',     30000, 4600000, now()->subDay());
        $so('delivery',       $cust['cafe'],   'Shopping bags',   12000, 2400000, now()->subDays(2), [], 0, 'won');
        $so('order_received', $cust['pharma'], 'Sample roll',      2000,  300000, now()->subDays(3), [], 0, 'lost');

        /* ---------------- EXCEPTIONS ---------------- */
        $exc = function (string $type, $c, string $subject, float $amount, string $status='open', array $appr=[]) use ($tid) {
            $e = new WwException(['tenant_id'=>$tid,'type'=>$type,'customer_id'=>$c->id,'customer_name'=>$c->name,'subject'=>$subject,'amount'=>$amount,'status'=>$status]);
            $e->ref = 'EXC-D'.WwException::count();
            $e->stage_started_at = now()->subHours(1);
            $e->applySla();
            if (in_array('sm',$appr,true)) { $e->sm_by='SM Demo'; $e->sm_at=now(); }
            if (in_array('md',$appr,true)) { $e->md_by='MD Demo'; $e->md_at=now(); }
            if ($status==='resolved') $e->resolution='Handled and closed.';
            $e->save(); return $e;
        };
        $exc('complaint',    $cust['nile'],  'Print misalignment on last batch', 0,        'open');
        $exc('goods_return', $cust['mukwano'],'500kg off-spec film returned',    5000000,  'approved', ['sm']);          // <=10M -> SM only
        $exc('goods_return', $cust['pharma'], 'Large rejected lot',              15000000, 'open');                       // >10M -> needs SM+MD
        $exc('credit_note',  $cust['cafe'],  'Price adjustment credit',          2000000,  'resolved', ['sm','md']);

        $this->command->info('WinworldDemoSeeder: done — 4 customers, 3 indents, 7 plannings, 9 production runs, 3 QC sets, 8 sales orders, 4 exceptions.');
    }
}
