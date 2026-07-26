<?php declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One part of one kit (Asset Lifecycle · S4a): "kit_product_id contains `quantity` of
 * component_product_id". Both sides are ordinary products. Tenant-scoped.
 */
class KitComponent extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'kit_product_id', 'component_product_id', 'quantity', 'sort', 'meta',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'sort'     => 'integer',
        'meta'     => 'array',
    ];

    /** The part product. */
    public function component(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_product_id');
    }

    /** The bundle product. */
    public function kit(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'kit_product_id');
    }
}
