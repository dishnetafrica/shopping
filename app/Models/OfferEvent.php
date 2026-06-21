<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class OfferEvent extends Model
{
    use BelongsToTenant;

    public $timestamps = false;   // only created_at is tracked, set explicitly

    protected $fillable = [
        'tenant_id', 'offer_id', 'item', 'event_type', 'payload', 'created_at',
    ];

    protected $casts = [
        'payload'    => 'array',
        'created_at' => 'datetime',
    ];
}
