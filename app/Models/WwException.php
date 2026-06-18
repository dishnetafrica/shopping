<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\Winworld\ExceptionFlow;
use App\Services\Winworld\SlaClock;
use Illuminate\Database\Eloquent\Model;

class WwException extends Model
{
    use BelongsToTenant;
    protected $table = 'ww_exceptions';
    protected $fillable = ['tenant_id','ref','type','customer_id','customer_name','sales_order_id','subject','amount',
        'status','owner_role','stage_started_at','sla_due_at','sla_alerted_at','sm_by','sm_at','md_by','md_at','resolution'];
    protected $casts = ['amount'=>'decimal:2','stage_started_at'=>'datetime','sla_due_at'=>'datetime','sla_alerted_at'=>'datetime','sm_at'=>'datetime','md_at'=>'datetime'];

    public function applySla(): void
    {
        $this->owner_role = ExceptionFlow::role($this->type);
        $start = $this->stage_started_at ?: now();
        $this->sla_due_at = SlaClock::dueAt($start, ExceptionFlow::sla($this->type));
    }
    public function approvalsDone(): array
    {
        $d = [];
        if ($this->sm_by) $d[] = 'sm';
        if ($this->md_by) $d[] = 'md';
        return $d;
    }
    public function canResolve(): bool
    {
        return ExceptionFlow::canResolve($this->type, $this->approvalsDone(), (float) $this->amount);
    }
    public function slaStatus(): string
    {
        if (! $this->sla_due_at || in_array($this->status, ['resolved','rejected'], true)) return 'ok';
        return SlaClock::status($this->sla_due_at, now());
    }
}
