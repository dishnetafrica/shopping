<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A box-count discrepancy (or damage) raised on a shipment leg. */
class ShipmentException extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'shipment_id', 'type', 'from_stage', 'to_stage',
        'expected', 'got', 'delta', 'detail', 'resolved', 'resolved_by', 'resolved_at', 'created_at',
    ];

    protected $casts = [
        'expected'    => 'integer',
        'got'         => 'integer',
        'delta'       => 'integer',
        'resolved'    => 'boolean',
        'resolved_at' => 'datetime',
        'created_at'  => 'datetime',
    ];

    public function shipment(): BelongsTo { return $this->belongsTo(Shipment::class); }
}
