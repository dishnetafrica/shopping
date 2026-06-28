<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Current inventory belief for a product at one location (point-in-time, with confidence + freshness). */
class InventorySnapshot extends Model
{
    use BelongsToTenant;
    protected $fillable = ['tenant_id', 'product_id', 'location', 'quantity', 'confidence', 'source', 'observed_at'];
    protected $casts = ['quantity' => 'integer', 'observed_at' => 'datetime'];

    public function product() { return $this->belongsTo(Product::class); }
}
