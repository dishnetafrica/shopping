<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShipmentBox extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'shipment_id', 'box_number', 'code'];
    protected $casts = ['box_number' => 'integer'];

    public function shipment(): BelongsTo { return $this->belongsTo(Shipment::class); }
    public function scans(): HasMany { return $this->hasMany(ShipmentBoxScan::class, 'box_id'); }
}
