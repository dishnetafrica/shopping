<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A WhatsApp number attached to a shop. A tenant can have several — one primary plus additional
 * numbers (branches, a support line, or a ban-safe backup) that all feed the same catalogue and
 * orders. Deliberately does NOT use the BelongsToTenant scope: inbound routing resolves a channel
 * to its tenant BEFORE any tenant context exists, so this table is queried globally by instance.
 */
class WhatsappChannel extends Model
{
    protected $fillable = [
        'tenant_id', 'instance', 'number', 'driver', 'label', 'is_primary', 'active',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'active'     => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Resolve the tenant id that owns a given active instance (inbound routing). */
    public static function tenantIdForInstance(string $instance): ?int
    {
        if ($instance === '') return null;
        $row = static::query()->where('instance', $instance)->where('active', true)->first();
        return $row?->tenant_id;
    }

    /** True when this instance is an active channel of the given tenant (reply-on-same-number guard). */
    public static function isTenantInstance(int $tenantId, string $instance): bool
    {
        if ($instance === '') return false;
        return static::query()
            ->where('tenant_id', $tenantId)
            ->where('instance', $instance)
            ->where('active', true)
            ->exists();
    }
}
