<?php declare(strict_types=1);

namespace App\Kernel\Events;

/**
 * The serial-lifecycle vocabulary (Asset Lifecycle · S2a). These are AUDIT events — they record what
 * happened to a unit on the spine; the inventory_units.status column remains the source of truth
 * (architecture review, decision #2). Pure contract: names + the status→event map, no I/O.
 */
final class SerialEvents
{
    /** The lifecycle a unit can be in. */
    public const STATUSES = ['in_stock', 'reserved', 'sold', 'installed', 'returned', 'replaced', 'scrapped'];

    /** Emitted when a unit is first registered. */
    public const REGISTERED = 'SerialRegistered';

    /** OBSERVE-ONLY (decision D2): a serialized product shipped without picked serials. Evidence, never a block. */
    public const SALE_UNPICKED = 'SerialSaleUnpicked';

    /** The audit event emitted when a unit transitions INTO each status. */
    public const ON_STATUS = [
        'in_stock'  => 'SerialRestocked',
        'reserved'  => 'SerialReserved',
        'sold'      => 'SerialSold',
        'installed' => 'SerialInstalled',
        'returned'  => 'SerialReturned',
        'replaced'  => 'SerialReplaced',
        'scrapped'  => 'SerialScrapped',
    ];

    public static function isValidStatus(string $status): bool
    {
        return in_array($status, self::STATUSES, true);
    }

    public static function eventFor(string $status): string
    {
        return self::ON_STATUS[$status] ?? 'SerialStatusChanged';
    }
}
