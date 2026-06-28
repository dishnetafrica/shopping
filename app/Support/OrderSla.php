<?php
namespace App\Support;

/**
 * How long an order may sit in each status before it counts as "needs attention" — and that depends
 * entirely on the business. A restaurant order stuck 15 min is late; a manufacturer's B2B order
 * legitimately sits in "New" for days awaiting payment/production. So the thresholds are per-vertical,
 * and the digest cadence differs too (see OrderPendingDigestCommand). Pure: no DB, no framework.
 */
class OrderSla
{
    public const ACTIVE = ['New', 'Accepted', 'Confirmed', 'Preparing', 'Ready', 'Packed', 'Dispatched', 'Out for delivery'];
    public const TERMINAL = ['Delivered', 'Customer confirmed', 'Cancelled', 'Rejected'];
    public const DISPATCH = ['Packed', 'Dispatched', 'Out for delivery'];

    /** Minutes-in-status before "overdue", per vertical. */
    private const PROFILES = [
        Vertical::RESTAURANT => ['New' => 15, 'Accepted' => 30, 'Confirmed' => 30, 'Preparing' => 45, 'Ready' => 20, 'Packed' => 60, 'Dispatched' => 60, 'Out for delivery' => 60, '_default' => 45],
        Vertical::GROCERY    => ['New' => 60, 'Accepted' => 120, 'Confirmed' => 120, 'Preparing' => 120, 'Ready' => 60, 'Packed' => 240, 'Dispatched' => 240, 'Out for delivery' => 240, '_default' => 120],
        Vertical::SNACKS     => ['New' => 480, 'Accepted' => 720, 'Confirmed' => 720, 'Preparing' => 720, 'Ready' => 240, 'Packed' => 1440, 'Dispatched' => 1440, 'Out for delivery' => 1440, '_default' => 720],
        // Manufacturer / wholesale: days, never minutes. B2B orders wait on payment + production runs.
        Vertical::MANUFACTURER => ['New' => 2880, 'Accepted' => 4320, 'Confirmed' => 4320, 'Preparing' => 7200, 'Ready' => 2880, 'Packed' => 4320, 'Dispatched' => 10080, 'Out for delivery' => 10080, '_default' => 4320],
    ];

    public static function isActive(string $status): bool
    {
        foreach (self::ACTIVE as $s) if (strcasecmp($s, $status) === 0) return true;
        return false;
    }

    public static function isDispatch(string $status): bool
    {
        foreach (self::DISPATCH as $s) if (strcasecmp($s, $status) === 0) return true;
        return false;
    }

    /** Slow verticals get a twice-daily digest; restaurant gets near-real-time (batched) alerts. */
    public static function isRealtime(string $vertical): bool
    {
        return $vertical === Vertical::RESTAURANT;
    }

    /** Minutes before an order in $status is overdue for $vertical, honouring per-tenant overrides. */
    public static function staleMinutes(string $vertical, string $status, array $overrides = []): int
    {
        if (array_key_exists($status, $overrides)) return max(1, (int) $overrides[$status]);
        $profile = self::PROFILES[$vertical] ?? self::PROFILES[Vertical::GROCERY];
        return (int) ($profile[$status] ?? $profile['_default']);
    }
}
