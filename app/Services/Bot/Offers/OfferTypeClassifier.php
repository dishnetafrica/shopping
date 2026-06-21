<?php

namespace App\Services\Bot\Offers;

/**
 * Status Intelligence — offer-type classifier. Pure logic, no framework deps.
 *
 * Maps the text of an owner's poster / status / menu image to one of the five offer types.
 * Order matters: festival and weekend cues win over a generic "special", and an explicit
 * "fresh today" wins over a thali so a fresh-batch post isn't filed as the daily meal.
 */
class OfferTypeClassifier
{
    public const DAILY_THALI = 'daily_thali';
    public const SPECIAL     = 'special_offer';
    public const WEEKEND     = 'weekend_offer';
    public const FESTIVAL    = 'festival_offer';
    public const FRESH       = 'fresh_today';

    public const TYPES = [self::DAILY_THALI, self::SPECIAL, self::WEEKEND, self::FESTIVAL, self::FRESH];

    public static function classify(string $text, array $items = []): string
    {
        $t = ' ' . preg_replace('/\s+/', ' ', mb_strtolower($text . ' ' . implode(' ', $items))) . ' ';

        // 1) Festival / occasion — strongest signal.
        if (preg_match('/\b(festival|diwali|deepavali|holi|navratri|navaratri|navratri|eid|ramadan|ramzan|christmas|x-?mas|new year|sankranti|uttarayan|makar|raksha bandhan|rakhi|janmashtami|ganesh|dussehra|dasara|pongal|onam|ugadi|gudi padwa|special day)\b/', $t)) {
            return self::FESTIVAL;
        }

        // 2) Fresh-today batch (explicit) — before thali so "fresh jalebi today" isn't a meal.
        if (preg_match('/\b(fresh today|freshly made|just made|made today|hot ?(and )?fresh|garam garam|taaza|taza|aaj taaza|fresh batch|fresh stock)\b/', $t)) {
            return self::FRESH;
        }

        // 3) Weekend (only when no thali — "weekend thali" stays a thali).
        if (preg_match('/\b(weekend|saturday|sunday|sat-?sun|sat & sun)\b/', $t) && ! preg_match('/\bthali\b/', $t)) {
            return self::WEEKEND;
        }

        // 4) Daily set-meal (thali / lunch menu / set meal).
        if (preg_match('/\b(thali|set ?meal|lunch menu|dinner menu|menu of the day|daily menu|today.?s? (lunch|menu)|combo meal)\b/', $t)) {
            return self::DAILY_THALI;
        }

        // 5) A meal made of staple items, even with no keyword -> treat as the day's thali.
        if (self::looksLikeMeal($t)) {
            return self::DAILY_THALI;
        }

        // 6) Anything else with an offer cue -> a special; otherwise default to special.
        return self::SPECIAL;
    }

    /** Heuristic: the text lists several thali staples, so it's a set meal even without "thali". */
    private static function looksLikeMeal(string $t): bool
    {
        $staples = ['chapati', 'roti', 'phulka', 'dal', 'rice', 'sabji', 'shak', 'sabzi', 'papad',
            'salad', 'chaas', 'buttermilk', 'raita', 'curry', 'kadhi', 'khichdi', 'rotli', 'bhakri'];
        $hits = 0;
        foreach ($staples as $s) { if (str_contains($t, $s)) $hits++; }
        return $hits >= 3;
    }
}
