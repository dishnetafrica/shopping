<?php declare(strict_types=1);

namespace App\Kernel\Events;

/**
 * The warranty vocabulary (Asset Lifecycle · S3a). AUDIT events — the warranties.status column is the
 * source of truth; these record the lifecycle on the spine. Claim events (opened/approved/…) arrive
 * with the claims workflow (S7). Pure contract, no I/O.
 */
final class WarrantyEvents
{
    /** Stored lifecycle statuses (expired is derived from warranty_end, not stored). */
    public const STATUSES = ['active', 'void'];

    public const ACTIVATED = 'WarrantyActivated';
    public const VOIDED    = 'WarrantyVoided';
}
