<?php

namespace App\Services\Bot\Offers;

/**
 * Status Intelligence v17 — confidence bands.
 *
 *   >= 95  auto      apply immediately
 *   70-94  review    queue for owner Approve / Reject / Edit (don't apply yet)
 *   < 70   feed      record to the activity feed only (don't apply, don't queue)
 */
class ActivityBand
{
    public const AUTO   = 'auto';
    public const REVIEW = 'review';
    public const FEED   = 'feed';

    public static function of(int $confidence): string
    {
        if ($confidence >= 95) return self::AUTO;
        if ($confidence >= 70) return self::REVIEW;
        return self::FEED;
    }
}
