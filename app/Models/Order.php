<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use BelongsToTenant;
    protected $fillable = ['tenant_id','order_no','customer_name','customer_phone','items_text',
        'items_json','total','location','payment','status','channel','rider_id','branch_id','track_token','delivered_at'];
    protected $casts = ['items_json'=>'array','total'=>'decimal:2','delivered_at'=>'datetime'];

    public function items(): HasMany { return $this->hasMany(OrderItem::class); }
    public function rider() { return $this->belongsTo(Rider::class); }
}
