<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A long-distance shipment (transport leg). Owns its own state machine — never overload
 * Order.status. See App\Services\Logistics\ShipmentStateMachine.
 */
class Shipment extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'order_id', 'shipment_number', 'status', 'token',
        'boxes_sent', 'boxes_received', 'weight_kg',
        'transport_company', 'bus_number', 'driver_phone',
        'origin_city', 'destination_city', 'destination_agent_name', 'destination_agent_phone',
        'notes', 'sent_at', 'transport_confirmed_at', 'departed_at', 'arrived_at', 'cancelled_at',
    ];

    protected $casts = [
        'boxes_sent'             => 'integer',
        'boxes_received'         => 'integer',
        'weight_kg'              => 'decimal:2',
        'sent_at'                => 'datetime',
        'transport_confirmed_at' => 'datetime',
        'departed_at'            => 'datetime',
        'arrived_at'             => 'datetime',
        'cancelled_at'           => 'datetime',
    ];

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function events(): HasMany { return $this->hasMany(ShipmentEvent::class)->orderBy('id'); }
    public function exceptions(): HasMany { return $this->hasMany(ShipmentException::class); }

    public function openExceptions(): HasMany { return $this->exceptions()->where('resolved', false); }
}
