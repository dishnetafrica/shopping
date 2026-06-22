<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentBoxScan extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'shipment_id', 'box_id', 'stage', 'actor', 'actor_name', 'scanned_at'];
    protected $casts = ['scanned_at' => 'datetime'];

    public function box(): BelongsTo { return $this->belongsTo(ShipmentBox::class, 'box_id'); }
    public function shipment(): BelongsTo { return $this->belongsTo(Shipment::class); }
}
