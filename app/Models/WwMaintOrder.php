<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class WwMaintOrder extends Model
{
    use BelongsToTenant;
    protected $table = 'ww_maint_orders';
    protected $fillable = ['tenant_id','ref','machine_id','type','title','priority','status',
        'reported_at','started_at','completed_at','due_at','downtime_min','reported_by','done_by','notes'];
    protected $casts = [
        'reported_at'=>'datetime','started_at'=>'datetime','completed_at'=>'datetime','due_at'=>'datetime',
        'downtime_min'=>'integer',
    ];

    public function machine() { return $this->belongsTo(WwMachine::class, 'machine_id'); }
}
