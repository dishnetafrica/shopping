<?php declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single physical, serial-tracked unit (Asset Lifecycle · S2a). The `status` column is the source of
 * truth; lifecycle events on the spine are the audit log. Tenant-scoped.
 */
class InventoryUnit extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'product_id', 'serial', 'manufacturer_serial', 'batch', 'status',
        'location', 'supplier', 'purchase_date', 'order_id', 'customer_id', 'warranty_id',
        'invoice_id', 'qr_token', 'meta',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'meta'          => 'array',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
