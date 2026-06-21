<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class DailyOffer extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'offer_type', 'title', 'description', 'price', 'currency',
        'image_url', 'structured_data', 'source', 'valid_from', 'valid_until', 'is_active',
    ];

    protected $casts = [
        'structured_data' => 'array',
        'price'           => 'integer',
        'is_active'       => 'boolean',
        'valid_from'      => 'datetime',
        'valid_until'     => 'datetime',
    ];
}
