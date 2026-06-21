<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MerchantChangeRequest extends Model
{
    protected $fillable = [
        'tenant_id', 'merchant_phone', 'change_type', 'payload_json', 'previous_json',
        'status', 'conversation_id', 'confirmed_at', 'applied_at', 'cancelled_at',
    ];
    protected $casts = [
        'payload_json'  => 'array',
        'previous_json' => 'array',
        'confirmed_at'  => 'datetime',
        'applied_at'    => 'datetime',
        'cancelled_at'  => 'datetime',
    ];
}
