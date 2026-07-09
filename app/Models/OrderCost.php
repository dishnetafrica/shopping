<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderCost extends Model
{
    protected $fillable = [
        'tenant_id','order_id','order_no','product_id','qty','unit_cost','stage','currency','native_amount',
        'rate_to_usd','usd_amount','holder','holder_user_id','receipt_path','status','note','category','created_by',
        'purchase_group_id','supplier_id','supplier_name','voided_at','voided_by','void_reason',
        'pay_status','paid_native',
    ];
    protected $casts = [
        'native_amount' => 'decimal:2', 'rate_to_usd' => 'decimal:6', 'usd_amount' => 'decimal:2',
        'paid_native' => 'decimal:2',
    ];

    /**
     * Cash reflects money ACTUALLY paid. Any row that isn't explicitly on credit is
     * treated as paid in full, so existing writers (expenses, cashbook, funding) need
     * no change — only credit purchases set paid_native = 0.
     */
    protected static function booted(): void
    {
        static::observe(\App\Observers\OrderCostObserver::class);
        static::creating(function (self $m) {
            if ($m->paid_native === null) {
                $m->paid_native = ((string) $m->pay_status === 'credit') ? 0 : (float) $m->native_amount;
            }
        });
    }

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }

    /** Compute the USD base value from native + rate (DishNet convention: rate = native per 1 USD). */
    public static function toUsd(float $native, float $rateToUsd): float
    {
        return $rateToUsd > 0 ? round($native / $rateToUsd, 2) : 0.0;
    }
}
