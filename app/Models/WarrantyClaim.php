<?php declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A warranty claim (Asset Lifecycle · Phase 2 · S7): opened against a warranty, then approved or
 * rejected with a resolution. The `status` column is the source of truth. Tenant-scoped.
 */
class WarrantyClaim extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'warranty_id', 'product_id', 'inventory_unit_id', 'serial',
        'customer_id', 'customer_name', 'customer_phone',
        'fault_description', 'status', 'resolution', 'resolution_notes',
        'opened_by', 'decided_by', 'decided_at', 'meta',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
        'meta'       => 'array',
    ];

    public function warranty(): BelongsTo
    {
        return $this->belongsTo(Warranty::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(InventoryUnit::class, 'inventory_unit_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
