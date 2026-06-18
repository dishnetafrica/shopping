<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class WwSalesEvent extends Model
{
    use BelongsToTenant;
    protected $table = 'ww_sales_events';
    protected $fillable = ['tenant_id','sales_order_id','stage','action','role','actor','note','at'];
    protected $casts = ['at'=>'datetime'];
    public function order() { return $this->belongsTo(WwSalesOrder::class, 'sales_order_id'); }
}
