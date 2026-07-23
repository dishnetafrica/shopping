<?php

namespace App\Models;
use App\Models\Concerns\BelongsToTenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One product line on a Demand Plan / Procurement Request. */
class DemandPlanLine extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'demand_plan_id', 'product_id', 'name',
        'current_stock', 'in_transit', 'expected_sales', 'suggested_qty', 'requested_qty',
        'qty_available', 'qty_purchased', 'qty_packed', 'qty_missing', 'pack_damaged', 'packed_at',
        'qty_sent', 'sent_at', 'supplier_id', 'supplier_name', 'expected_delivery',
        'remarks', 'line_status', 'received_qty', 'damaged_qty', 'received_at',
        'shipment_id', 'consignment_id',
    ];

    protected $casts = [
        'current_stock'  => 'decimal:3',
        'in_transit'     => 'decimal:3',
        'expected_sales' => 'decimal:3',
        'suggested_qty'  => 'decimal:3',
        'requested_qty'  => 'decimal:3',
        'qty_available'  => 'decimal:3',
        'qty_purchased'  => 'decimal:3',
        'qty_packed'     => 'decimal:3',
        'qty_missing'    => 'decimal:3',
        'pack_damaged'   => 'decimal:3',
        'qty_sent'       => 'decimal:3',
        'received_qty'   => 'decimal:3',
        'damaged_qty'    => 'decimal:3',
        'expected_delivery' => 'date',
        'packed_at'      => 'datetime',
        'sent_at'        => 'datetime',
        'received_at'    => 'datetime',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(DemandPlan::class, 'demand_plan_id');
    }

    /** Re-derive the line status from the requested vs purchased quantities. */
    public function deriveStatus(): string
    {
        $req = (float) $this->requested_qty;
        $bought = (float) $this->qty_purchased;
        if ($this->line_status === 'not_available') {
            return 'not_available';
        }
        if ($bought <= 0) {
            return 'pending';
        }
        if ($req > 0 && $bought + 0.0001 < $req) {
            return 'partial';
        }

        return 'purchased';
    }
}
