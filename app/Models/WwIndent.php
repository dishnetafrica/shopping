<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\Winworld\Formula;
use Illuminate\Database\Eloquent\Model;

class WwIndent extends Model
{
    use BelongsToTenant;
    protected $table = 'ww_indents';

    public const DOC_REF  = 'WIL/MKT/OIF/001';
    public const STATUSES = ['Open','Planned','In Process','Completed','Closed'];

    protected $fillable = [
        'tenant_id','indent_no','doc_ref','customer_id','customer_name','item_id','product_name',
        'sales_person','date_of_indent','order_qty_pcs','mixing_qty','priority','sample_available',
        'sdh_remarks','pdh_remarks','status','order_kg','planned_completion','delay_days',
        'needs_blending','needs_extrusion','needs_printing','needs_cutting',
        'ext_width','ext_gusset','ext_gauge','ext_film_colour','ext_weight_per_roll','ext_type_of_roll','ext_sample',
        'prn_specification','prn_no_colours','prn_colours','prn_single_double','prn_direction','prn_gap_top','prn_gap_bottom','prn_sample',
        'cut_type','cut_bag_size','cut_sealing','cut_bottom_gusset','cut_handle_punch','cut_handle_position','cut_hole_punch','cut_hole_positions','cut_sample',
    ];

    protected $casts = [
        'date_of_indent'=>'date','order_qty_pcs'=>'integer','mixing_qty'=>'decimal:3','order_kg'=>'decimal:3',
        'planned_completion'=>'datetime','delay_days'=>'integer','sample_available'=>'boolean',
        'needs_blending'=>'boolean','needs_extrusion'=>'boolean','needs_printing'=>'boolean','needs_cutting'=>'boolean',
        'ext_sample'=>'boolean','prn_sample'=>'boolean','cut_sample'=>'boolean',
    ];

    public function customer() { return $this->belongsTo(WwCustomer::class, 'customer_id'); }
    public function item()     { return $this->belongsTo(WwItem::class, 'item_id'); }
    public function blends()   { return $this->hasMany(WwIndentBlend::class, 'indent_id')->orderBy('line_no'); }
    public function qc()       { return $this->hasMany(WwIndentQc::class, 'indent_id'); }
    public function plannings(){ return $this->hasMany(WwPlanning::class, 'indent_id'); }
    public function productionEntries() { return $this->hasMany(WwProductionEntry::class, 'indent_id'); }

    /** Processes this indent routes through, in line order. */
    public function activeProcesses(): array
    {
        $map = ['Blending'=>$this->needs_blending,'Extrusion'=>$this->needs_extrusion,'Printing'=>$this->needs_printing,'Cutting'=>$this->needs_cutting];
        return array_keys(array_filter($map, fn($v) => (bool)$v));
    }

    /** order_kg = qty x item gram/pcs / 1000. Stores and returns it. */
    public function recomputeOrderKg(): float
    {
        $gram = $this->item?->gram_per_pcs;
        if ($gram === null && $this->item) $gram = $this->item->recomputeGramPerPcs();
        $this->order_kg = round(Formula::orderKg((int)$this->order_qty_pcs, (float)$gram), 3);
        return (float)$this->order_kg;
    }
}
