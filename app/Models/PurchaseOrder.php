<?php

namespace App\Models;
use App\Models\Concerns\BelongsToTenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Local Purchase Order (LPO) — one ordering document to one supplier. The header; lines carry the
 * per-product ordered vs received figures. Document + goods-receipt tracker only: money posts through
 * order_costs (supplier bills), stock through StockLedger on receipt.
 */
class PurchaseOrder extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'po_no', 'supplier_id', 'supplier_name', 'status',
        'order_date', 'expected_date', 'currency', 'delivery_address', 'terms', 'notes',
        'created_by', 'sent_at', 'closed_at',
    ];

    protected $casts = [
        'order_date'    => 'date',
        'expected_date' => 'date',
        'sent_at'       => 'datetime',
        'closed_at'     => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    /** Fulfilment 0–100 across all lines (received vs ordered quantity). */
    public function receivedPct(): int
    {
        $ordered = (float) $this->lines->sum('ordered_qty');
        if ($ordered <= 0) {
            return 0;
        }
        $got = (float) $this->lines->sum(fn ($l) => min((float) $l->ordered_qty, (float) $l->received_qty));

        return (int) round(min(100, max(0, $got / $ordered * 100)));
    }
}
