<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class DeliveryZone extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id','name','active','match_keywords','center_lat','center_lng',
        'radius_m','flat_fee','per_km_fee','min_fee','free_over','eta_minutes','default_rider_id'];

    protected $casts = [
        'active'         => 'boolean',
        'match_keywords' => 'array',
        'center_lat'     => 'float',
        'center_lng'     => 'float',
    ];
}
