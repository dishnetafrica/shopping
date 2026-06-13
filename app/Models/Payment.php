<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'tenant_id', 'provider', 'plan', 'months', 'amount', 'currency',
        'tx_ref', 'provider_ref', 'network', 'phone', 'status', 'meta',
    ];

    protected $casts = ['meta' => 'array'];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
