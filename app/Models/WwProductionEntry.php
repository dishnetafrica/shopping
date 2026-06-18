<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\Winworld\Formula;
use App\Services\Winworld\Oee;
use Illuminate\Database\Eloquent\Model;

class WwProductionEntry extends Model
{
    use BelongsToTenant;
    protected $table = 'ww_production_entries';
    protected $fillable = ['tenant_id','indent_id','planning_id','process','machine_id','shift','start_time','end_time','produced_qty_pcs','produced_kg','scrap_kg','changeover_min','actual_hours','actual_output_kg_hr','target_output_kg_hr','efficiency_pct','qc_result','status','stop_reason','remarks'];
    protected $casts = [
        'start_time'=>'datetime','end_time'=>'datetime','produced_qty_pcs'=>'integer',
        'produced_kg'=>'decimal:3','scrap_kg'=>'decimal:3','changeover_min'=>'integer',
        'actual_hours'=>'decimal:3','actual_output_kg_hr'=>'decimal:3','target_output_kg_hr'=>'decimal:3','efficiency_pct'=>'decimal:2',
    ];

    public const STOP_REASONS = ['Machine Breakdown','Power Failure','Material Shortage'];

    public function indent()   { return $this->belongsTo(WwIndent::class, 'indent_id'); }
    public function planning() { return $this->belongsTo(WwPlanning::class, 'planning_id'); }
    public function machine()  { return $this->belongsTo(WwMachine::class, 'machine_id'); }

    /** Derive actual_hours, actual_output and efficiency_pct from recorded actuals. */
    public function recompute(): void
    {
        if ($this->start_time && $this->end_time) {
            $this->actual_hours = round(Formula::elapsedHours(
                \DateTimeImmutable::createFromInterface($this->start_time),
                \DateTimeImmutable::createFromInterface($this->end_time)
            ), 3);
        }
        $this->actual_output_kg_hr = round(Formula::actualOutputKgHr((float)$this->produced_kg, (float)$this->actual_hours), 3) ?: null;
        if ($this->target_output_kg_hr) {
            $this->efficiency_pct = Oee::compute(0, 0, (float)$this->actual_output_kg_hr, (float)$this->target_output_kg_hr, (float)$this->produced_kg, (float)$this->scrap_kg)['efficiency_pct'];
        }
    }

    /** Full OEE for this entry given the planned (available) hours. */
    public function oee(float $plannedHours): array
    {
        return Oee::compute((float)$this->actual_hours, $plannedHours, (float)$this->actual_output_kg_hr, (float)$this->target_output_kg_hr, (float)$this->produced_kg, (float)$this->scrap_kg);
    }
}
