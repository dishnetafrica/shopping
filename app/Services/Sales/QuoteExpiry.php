<?php

namespace App\Services\Sales;

use App\Models\Quotation;
use App\Models\Tenant;
use App\Support\Vertical;

/**
 * Marks quotations expired once their validity date has passed, so a customer cannot accept a price
 * that is no longer offered. Internet-provider businesses only for now; every other business keeps
 * today's behaviour (expired only when staff mark it).
 *
 * Only quotations still waiting on the customer are expired. One the customer accepted, or that was
 * converted, declined or archived, is never touched.
 */
class QuoteExpiry
{
    /** Waiting on the customer: these may expire. */
    public const WAITING = ['draft', 'sent', 'viewed', 'revised', 'customer_revised'];

    /** PURE. Is this quotation past its validity on this day (both Y-m-d)? */
    public static function due(string $status, ?string $validUntil, string $today): bool
    {
        return in_array($status, self::WAITING, true) && $validUntil !== null && $validUntil !== '' && $validUntil < $today;
    }

    /** @return int how many quotations were marked expired */
    public function run(): int
    {
        $done = 0;
        foreach (Tenant::query()->get() as $t) {
            if (Vertical::of($t) !== Vertical::ISP) {
                continue;
            }
            $tz = (string) $t->setting('timezone', '') ?: (string) config('app.timezone', 'UTC');
            $today = now(in_array($tz, timezone_identifiers_list(), true) ? $tz : 'UTC')->toDateString();
            $done += Quotation::withoutGlobalScopes()->where('tenant_id', $t->id)
                ->whereIn('status', self::WAITING)->whereNotNull('valid_until')->where('valid_until', '<', $today)
                ->update(['status' => 'expired', 'updated_at' => now()]);
        }

        return $done;
    }
}
