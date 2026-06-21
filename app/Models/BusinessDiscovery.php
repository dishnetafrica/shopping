<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A Business Discovery scan result. Always created as 'pending' — nothing it contains becomes
 * active until the owner approves it from the panel.
 */
class BusinessDiscovery extends Model
{
    use BelongsToTenant;

    protected $table = 'business_discovery';

    protected $fillable = [
        'tenant_id', 'status', 'readiness', 'report',
        'sample_messages', 'sample_orders', 'sent_to', 'sent_at', 'approved_at',
    ];

    protected $casts = [
        'report'          => 'array',
        'readiness'       => 'integer',
        'sample_messages' => 'integer',
        'sample_orders'   => 'integer',
        'sent_at'         => 'datetime',
        'approved_at'     => 'datetime',
    ];
}
