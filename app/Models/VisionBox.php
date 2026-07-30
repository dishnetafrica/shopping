<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One physical Vision Gateway box (mini-PC) in the fleet. Identity = box_uid (machine-id) +
 * a box-held secret (stored here only as sha256). Lifecycle: pending → claimed (→ revoked).
 */
class VisionBox extends Model
{
    protected $fillable = [
        'box_uid', 'secret_sha256', 'claim_code', 'claim_expires_at', 'status',
        'tenant_id', 'store', 'webhook_token', 'fw_version', 'hw_model',
        'last_ip', 'last_seen_at', 'claimed_at', 'meta',
    ];

    protected $casts = [
        'claim_expires_at' => 'datetime',
        'last_seen_at'     => 'datetime',
        'claimed_at'       => 'datetime',
        'meta'             => 'array',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
