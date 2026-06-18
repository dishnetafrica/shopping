<?php
namespace App\Services\Bot;

/**
 * Builds the dedupe key for a lead: sha1(customer_phone | intent | normalized_interest).
 *
 * Keying on the *content* (not just customer + time) means three different
 * opportunities from the same person in one morning — "Need Starlink", "Need Fiber",
 * "Need Dedicated Internet" — each create their own lead, while a literal repeat of
 * the same request collapses to one. Pure, no dependencies.
 */
class LeadDedupe
{
    public static function key(string $phone, string $intent, string $interest): string
    {
        $phone  = preg_replace('/[^0-9]/', '', $phone);
        $intent = $intent === 'ticket' ? 'ticket' : 'lead';
        return sha1($phone . '|' . $intent . '|' . self::norm($interest));
    }

    /** Lowercase, strip punctuation (keeping unicode letters/digits, incl. Gujarati), collapse spaces. */
    public static function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }
}
