<?php

namespace App\Models;
use App\Models\Concerns\BelongsToTenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One product line on a Local Purchase Order (LPO). */
class PurchaseOrderLine extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'purchase_order_id', 'product_id', 'name',
        'ordered_qty', 'unit_cost', 'received_qty', 'line_status', 'remarks', 'received_at',
    ];

    protected $casts = [
        'ordered_qty'  => 'decimal:3',
        'unit_cost'    => 'decimal:4',
        'received_qty' => 'decimal:3',
        'received_at'  => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    /** Re-derive the line status from the ordered vs received quantities. */
    public function deriveStatus(): string
    {
        $ordered = (float) $this->ordered_qty;
        $got = (float) $this->received_qty;
        if ($got <= 0) {
            return 'pending';
        }
        if ($ordered > 0 && $got + 0.0001 < $ordered) {
            return 'partial';
        }

        return 'received';
    }

    /** Expected line value (ordered × unit cost). Reference only — never a posted amount. */
    public function lineValue(): float
    {
        return round((float) $this->ordered_qty * (float) $this->unit_cost, 2);
    }
}
