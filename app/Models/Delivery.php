<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Delivery extends Model
{
    use BelongsToTenant;

    public const ASSIGNED  = 'assigned';
    public const PICKED    = 'picked';
    public const OUT       = 'out';
    public const DELIVERED = 'delivered';
    public const FAILED    = 'failed';

    protected $fillable = ['tenant_id','order_id','rider_id','zone_id','status','fee','distance_km',
        'eta_at','assigned_at','picked_at','out_at','delivered_at','failed_at','failed_reason',
        'proof_photo_url','recipient_name','cod_amount','cod_collected','rider_token'];

    protected $casts = [
        'eta_at'        => 'datetime', 'assigned_at' => 'datetime', 'picked_at' => 'datetime',
        'out_at'        => 'datetime', 'delivered_at' => 'datetime', 'failed_at' => 'datetime',
        'cod_collected' => 'boolean', 'distance_km' => 'float',
    ];

    public function order(): BelongsTo  { return $this->belongsTo(Order::class); }
    public function rider(): BelongsTo  { return $this->belongsTo(Rider::class); }
    public function zone(): BelongsTo   { return $this->belongsTo(DeliveryZone::class, 'zone_id'); }
}
