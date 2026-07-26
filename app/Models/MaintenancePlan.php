<?php declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recurring maintenance / service plan (Asset Lifecycle · Phase 2 · S6). Tracks when a customer's
 * system is next due for service; each logged visit spawns a service Work Order and advances next_due.
 * Tenant-scoped.
 */
class MaintenancePlan extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'customer_id', 'customer_name', 'customer_phone',
        'product_id', 'inventory_unit_id', 'serial',
        'source_work_order_id', 'work_order_id',
        'interval_months', 'last_service_at', 'next_due', 'status', 'notes', 'meta',
    ];

    protected $casts = [
        'last_service_at' => 'date',
        'next_due'        => 'date',
        'interval_months' => 'integer',
        'meta'            => 'array',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(InventoryUnit::class, 'inventory_unit_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
