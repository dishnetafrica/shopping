<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderCost extends Model
{
    protected $fillable = [
        'tenant_id','order_id','order_no','stage','currency','native_amount',
        'rate_to_usd','usd_amount','holder','holder_user_id','receipt_path','status','note','created_by',
        'purchase_group_id','supplier_id','supplier_name',
    ];
    protected $casts = [
        'native_amount' => 'decimal:2', 'rate_to_usd' => 'decimal:6', 'usd_amount' => 'decimal:2',
    ];

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }

    /** Compute the USD base value from native + rate (DishNet convention: rate = native per 1 USD). */
    public static function toUsd(float $native, float $rateToUsd): float
    {
        return $rateToUsd > 0 ? round($native / $rateToUsd, 2) : 0.0;
    }
}
