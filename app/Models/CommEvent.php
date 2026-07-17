<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Immutable engagement/audit event: provider webhooks (delivered, bounced,
 * complained, clicked…) and our own open-pixel / unsubscribe hits.
 * Raw provider payload is preserved for audit.
 */
class CommEvent extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'message_id', 'event', 'provider', 'payload', 'occurred_at'];
    protected $casts    = ['payload' => 'array', 'occurred_at' => 'datetime'];
}
