<?php declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single product's value for one spec definition. Tenant-scoped.
 */
class ProductSpec extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'product_id', 'spec_definition_id', 'value_text', 'value_number',
    ];

    protected $casts = [
        'value_number' => 'decimal:4',
    ];

    public function definition(): BelongsTo
    {
        return $this->belongsTo(SpecDefinition::class, 'spec_definition_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
