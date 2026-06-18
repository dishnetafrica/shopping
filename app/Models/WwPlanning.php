<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\Winworld\Formula;
use App\Services\Winworld\ShiftCalendar;
use Illuminate\Database\Eloquent\Model;

class WwPlanning extends Model
{
    use BelongsToTenant;
    protected $table = 'ww_plannings';
    protected $fillable = ['tenant_id','indent_id','process','machine_id','running_speed','planned_start','auto_output_kg_hr','manual_output_kg_hr','final_output_kg_hr','required_hours','planned_end','status','notes'];
    protected $casts = [
        'running_speed'=>'decimal:3','planned_start'=>'datetime','auto_output_kg_hr'=>'decimal:3',
        'manual_output_kg_hr'=>'decimal:3','final_output_kg_hr'=>'decimal:3','required_hours'=>'decimal:3','planned_end'=>'datetime',
    ];

    public function indent()  { return $this->belongsTo(WwIndent::class, 'indent_id'); }
    public function machine() { return $this->belongsTo(WwMachine::class, 'machine_id'); }

    /**
     * Resolve final output (manual wins), required hours and planned end
     * (shift-aware). Pass order_kg from the indent. Returns required_hours.
     */
    public function recompute(float $orderKg, ?ShiftCalendar $cal = null): float
    {
        $final = Formula::finalOutputKgHr(
            $this->auto_output_kg_hr !== null ? (float)$this->auto_output_kg_hr : null,
            $this->manual_output_kg_hr !== null ? (float)$this->manual_output_kg_hr : null
        );
        $this->final_output_kg_hr = $final ?: null;
        $hours = Formula::requiredHours($orderKg, $final);
        $this->required_hours = round($hours, 3);

        if ($this->planned_start && $hours > 0) {
            $cal ??= new ShiftCalendar();
            $start = \DateTimeImmutable::createFromInterface($this->planned_start);
            $this->planned_end = $cal->addWorkingHours($start, $hours)->format('Y-m-d H:i:s');
        }
        return $hours;
    }
}
