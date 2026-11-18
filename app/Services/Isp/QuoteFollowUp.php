<?php

namespace App\Services\Isp;

/**
 * PURE. When and how an Internet-provider business follows up a quotation the customer has not answered
 * (DishNet, 18 Nov 2026: "does our AI do auto follow-up to customers? The idea is the AI works as the
 * relationship and sales manager").
 *
 * Two touches, then it stops: day 2 ("did you get a chance to look at it?") and day 5 (the validity date,
 * and an offer to book or adjust). The words are fixed and every fact in them comes from the records —
 * the customer's name, their salesperson, the quotation number and its validity date — so a follow-up
 * can never carry a price, discount, date or promise that was not approved.
 */
final class QuoteFollowUp
{
    /** Day of each touch, counted from when the quotation was sent. */
    public const DAYS = [1 => 2, 2 => 5];

    /** Never follow up a quotation older than this. */
    public const MAX_DAYS = 14;

    /** Which touch is due (1 or 2), or null. $done = touches already sent for this quotation. */
    public static function touch(int $daysSinceSent, int $done): ?int
    {
        if ($daysSinceSent > self::MAX_DAYS) {
            return null;
        }
        $next = $done + 1;
        if (! isset(self::DAYS[$next]) || $daysSinceSent < self::DAYS[$next]) {
            return null;
        }

        return $next;
    }

    /** The message, in the salesperson's name. No amount, no promise; only facts from the records. */
    public static function message(int $touch, ?string $customerName, ?string $salesName, string $company, string $quoteNo, ?string $validUntil): string
    {
        $first = trim((string) preg_replace('/\s.*/u', '', trim((string) $customerName)));
        $hello = 'Hello' . ($first !== '' && preg_match('/^\p{L}[\p{L}\'-]{1,30}$/u', $first) ? ' ' . $first : '');
        $me = trim((string) $salesName) !== '' ? trim((string) $salesName) . ' from ' . trim($company) : trim($company);

        if ($touch === 1) {
            return "{$hello}, this is {$me}. Did you get a chance to look at your quotation {$quoteNo}? "
                . "If you have any question about it, or you would like to go ahead, just reply here and I will arrange it.";
        }

        $valid = $validUntil !== null && $validUntil !== '' ? " Your quotation {$quoteNo} is valid until {$validUntil}." : '';

        return "{$hello}, {$me} again.{$valid} Shall I book your installation, or would you like me to adjust it (another plan, kit or accessories)? "
            . "If now is not the right time, just tell me and I will not disturb you.";
    }
}
