<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One custody handoff. Append-only — never updated after insert. */
class ShipmentEvent extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'shipment_id', 'event', 'actor', 'actor_name',
        'box_count', 'photo_url', 'note', 'occurred_at', 'created_at',
    ];

    protected $casts = [
        'box_count'   => 'integer',
        'occurred_at' => 'datetime',
        'created_at'  => 'datetime',
    ];

    public function shipment(): BelongsTo { return $this->belongsTo(Shipment::class); }
}
