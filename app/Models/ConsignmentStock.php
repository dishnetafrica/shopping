<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A loose-stock line on a consignment — market stock Asish sells in Juba, tracked through
 * request → sent → received → sold so the request/fulfilment gap is always visible.
 */
class ConsignmentStock extends Model
{
    use BelongsToTenant;

    protected $table = 'consignment_stock';

    protected $fillable = [
        'tenant_id', 'shipment_id', 'product_id', 'name',
        'qty_requested', 'qty_sent', 'qty_received', 'qty_sold',
        'unit_price', 'currency', 'note',
    ];

    protected $casts = [
        'qty_requested' => 'decimal:2', 'qty_sent' => 'decimal:2',
        'qty_received'  => 'decimal:2', 'qty_sold' => 'decimal:2',
        'unit_price'    => 'decimal:2',
    ];

    public function shipment(): BelongsTo { return $this->belongsTo(Shipment::class); }

    /** Requested but not yet sent (the shortfall to carry forward). */
    public function shortfall(): float
    {
        return max(0.0, (float) $this->qty_requested - (float) $this->qty_sent);
    }

    /** Still in Asish's hand to sell (received − sold; falls back to sent if not yet confirmed). */
    public function remaining(): float
    {
        $base = $this->qty_received !== null ? (float) $this->qty_received : (float) $this->qty_sent;
        return max(0.0, $base - (float) $this->qty_sold);
    }
}
