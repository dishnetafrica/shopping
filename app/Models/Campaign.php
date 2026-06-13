<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Campaign extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'name', 'type', 'audience', 'category', 'message',
        'image_url', 'product_ids', 'cta', 'status', 'scheduled_for', 'stats',
    ];

    protected $casts = [
        'product_ids'   => 'array',
        'stats'         => 'array',
        'scheduled_for' => 'datetime',
    ];

    public function typeLabel(): string
    {
        return [
            'promotion' => 'Product Promotion',
            'launch'    => 'New Product Launch',
            'discount'  => 'Discount Campaign',
            'seasonal'  => 'Seasonal Offer',
        ][$this->type] ?? 'Campaign';
    }
}
