<?php declare(strict_types=1);

namespace App\Kernel\Events;

/**
 * The warranty-claim vocabulary (Asset Lifecycle · Phase 2 · S7). AUDIT events on the spine — the
 * warranty_claims row is the source of truth. Pure contract.
 */
final class WarrantyClaimEvents
{
    public const STATUSES    = ['open', 'approved', 'rejected', 'resolved'];
    public const RESOLUTIONS = ['repair', 'replace', 'refund'];

    public const OPENED   = 'WarrantyClaimOpened';
    public const APPROVED = 'WarrantyClaimApproved';
    public const REJECTED = 'WarrantyClaimRejected';
    public const RESOLVED = 'WarrantyClaimResolved';

    public static function isValidStatus(string $status): bool
    {
        return in_array($status, self::STATUSES, true);
    }

    public static function isValidResolution(string $resolution): bool
    {
        return in_array($resolution, self::RESOLUTIONS, true);
    }
}
