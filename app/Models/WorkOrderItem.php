<?php declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Equipment on a work order (Asset Lifecycle · Phase 2 · S5): a product and, when serial-tracked, the
 * specific inventory unit being installed/serviced. Tenant-scoped.
 */
class WorkOrderItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'work_order_id', 'product_id', 'inventory_unit_id', 'serial', 'qty', 'meta',
    ];

    protected $casts = [
        'qty'  => 'decimal:3',
        'meta' => 'array',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(InventoryUnit::class, 'inventory_unit_id');
    }
}
