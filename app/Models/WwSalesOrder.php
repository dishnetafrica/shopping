<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\Winworld\SalesFlow;
use App\Services\Winworld\SlaClock;
use Illuminate\Database\Eloquent\Model;

class WwSalesOrder extends Model
{
    use BelongsToTenant;
    protected $table = 'ww_sales_orders';
    protected $fillable = ['tenant_id','order_no','customer_id','customer_name','contact','source','product_name',
        'qty','value','stage','owner_role','stage_started_at','sla_due_at','status','overdue_days','evidence','assigned_to','indent_id'];
    protected $casts = ['qty'=>'integer','value'=>'decimal:2','stage_started_at'=>'datetime','sla_due_at'=>'datetime','overdue_days'=>'integer'];

    public function customer() { return $this->belongsTo(WwCustomer::class, 'customer_id'); }
    public function events()   { return $this->hasMany(WwSalesEvent::class, 'sales_order_id')->orderByDesc('id'); }

    /** Approval roles already recorded for the CURRENT stage. */
    public function approvalsDone(): array
    {
        return $this->events()
            ->where('action', 'approve')->where('stage', $this->stage)
            ->pluck('role')->filter()->unique()->values()->all();
    }

    /** Recompute owner role + SLA due time from the current stage. */
    public function applyStage(?string $stage = null): void
    {
        if ($stage) { $this->stage = $stage; $this->stage_started_at = now(); }
        $this->owner_role = SalesFlow::role($this->stage);
        $start = $this->stage_started_at ?: now();
        $this->sla_due_at = SlaClock::dueAt($start, SalesFlow::sla($this->stage));
    }

    public function slaStatus(): string
    {
        if (! $this->sla_due_at || $this->status !== 'open') return 'ok';
        return SlaClock::status($this->sla_due_at, now());
    }

    public function canAdvance(): bool
    {
        return SalesFlow::canAdvance($this->stage, $this->approvalsDone(), (int) $this->overdue_days);
    }
}
