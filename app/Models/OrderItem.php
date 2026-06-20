<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use BelongsToTenant;
    protected $fillable = ['tenant_id','order_id','product_id','name','price','qty','notes','modifiers'];
    protected $casts = ['price'=>'decimal:2','modifiers'=>'array'];
    public function order() { return $this->belongsTo(Order::class); }
}
