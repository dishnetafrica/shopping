<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One append-only stock movement (Roadmap E6.1). On-hand for a SKU at a location is the signed sum
 * of its movements' `qty`. See App\Services\Inventory\StockLedger for the write API + projection.
 */
class StockMovement extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'product_id', 'sku', 'location', 'type', 'qty',
        'ref_type', 'ref_id', 'note', 'created_by',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
