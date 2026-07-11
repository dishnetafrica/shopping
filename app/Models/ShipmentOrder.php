<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on a consignment's manifest: a customer order carried on a shipment (AWB),
 * plus how far it's got in the destination distribution (on_board -> delivered / returned).
 */
class ShipmentOrder extends Model
{
    use BelongsToTenant;

    public const ON_BOARD  = 'on_board';
    public const DELIVERED = 'delivered';
    public const RETURNED  = 'returned';

    protected $fillable = [
        'tenant_id', 'shipment_id', 'order_id', 'status', 'note', 'delivered_at', 'delivered_by',
    ];

    protected $casts = [
        'delivered_at' => 'datetime',
    ];

    public function shipment(): BelongsTo { return $this->belongsTo(Shipment::class); }
    public function order(): BelongsTo    { return $this->belongsTo(Order::class); }
}
