<?php declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A warranty (Asset Lifecycle · S3a). Attaches to a serial (inventory_unit) or a non-serialized
 * invoice line. `status` is the source of truth; "expired" is derived from warranty_end. Tenant-scoped.
 */
class Warranty extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'product_id', 'inventory_unit_id', 'invoice_id', 'invoice_item_id',
        'customer_id', 'customer_name', 'customer_phone', 'serial', 'manufacturer',
        'installation_date', 'warranty_start', 'warranty_end',
        'manufacturer_months', 'company_months', 'status', 'meta',
    ];

    protected $casts = [
        'installation_date'   => 'date',
        'warranty_start'      => 'date',
        'warranty_end'        => 'date',
        'manufacturer_months' => 'integer',
        'company_months'      => 'integer',
        'meta'                => 'array',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(InventoryUnit::class, 'inventory_unit_id');
    }
}
