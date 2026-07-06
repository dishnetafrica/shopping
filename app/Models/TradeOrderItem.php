<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeOrderItem extends Model
{
    protected $fillable = [
        'tenant_id','order_id','order_no','name','qty','unit','bought_qty','product_id',
    ];
    protected $casts = ['qty' => 'decimal:2', 'bought_qty' => 'decimal:2'];

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
}
