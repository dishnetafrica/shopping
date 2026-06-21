<?php

namespace App\Services\Bot\Offers;

/**
 * Status Intelligence — customer query matcher. Pure logic, no framework deps.
 *
 * Decides whether a customer message is asking for the day's offer / menu / special, and
 * which KIND so the right active offer is served first. English + romanised Gujarati.
 * Returns ['kind' => thali|fresh|special|menu|today] or null (not an offer query).
 *
 * Examples that match:
 *   "Today's thali"      -> thali     "Aaje su che"      -> menu
 *   "Lunch today"        -> today     "Su special che"   -> special
 *   "Kathiyawadi thali"  -> thali     "Lunch menu"       -> menu
 *   "fresh today"        -> fresh     "what's special"   -> special
 */
class OfferQueryMatcher
{
    public static function detect(string $text): ?array
    {
        $t = ' ' . preg_replace('/\s+/', ' ', mb_strtolower(trim($text))) . ' ';
        $t = preg_replace('/[^\p{L}\p{N}\s\'\?]+/u', ' ', $t);
        $t = ' ' . trim(preg_replace('/\s+/', ' ', $t)) . ' ';
        if (trim($t) === '') return null;

        // thali / set meal (incl. named thali like "kathiyawadi thali")
        if (preg_match('/\b(thali|kathiyawadi|gujarati thali|rajasthani thali|punjabi thali)\b/', $t)
            || preg_match('/\b(lunch|dinner) (thali|special)\b/', $t)) {
            return ['kind' => 'thali'];
        }

        // fresh batch
        if (preg_match('/\b(fresh today|taaza|taza|fresh|garam garam|hot fresh)\b/', $t)) {
            return ['kind' => 'fresh'];
        }

        // special / offer
        if (preg_match('/\b(special|specials|offer|offers|deal|deals|discount|combo)\b/', $t)
            || preg_match('/\bsu+\s+special\b/', $t)) {
            return ['kind' => 'special'];
        }

        // menu / "what's there today" (English + Gujlish "su che", "aaje su che", "aaj su che")
        if (preg_match('/\b(menu|lunch menu|dinner menu|food menu|today.?s? menu|whats? on the menu|what.?s? cooking|whats? available)\b/', $t)
            || preg_match('/\b(aaj|aaje|aaj nu|aaje nu)\s+(su|shu)\s+(che|chhe)\b/', $t)
            || preg_match('/\b(su|shu)\s+(che|chhe)\b/', $t)
            || preg_match('/\b(aaj|aaje)\s+(su|shu)\b/', $t)) {
            return ['kind' => 'menu'];
        }

        // today's <food> / lunch today / dinner today
        if (preg_match('/\b(today|aaj|aaje)\b/', $t) && preg_match('/\b(lunch|dinner|food|eat|jaman|jamvanu|thali|special|menu)\b/', $t)) {
            return ['kind' => 'today'];
        }
        if (preg_match('/\b(lunch|dinner) (today|now)\b/', $t)) {
            return ['kind' => 'today'];
        }

        return null;
    }

    /** Offer types to prefer, in order, for a detected query kind. */
    public static function typesForKind(string $kind): array
    {
        return match ($kind) {
            'thali'   => [OfferTypeClassifier::DAILY_THALI, OfferTypeClassifier::FRESH, OfferTypeClassifier::SPECIAL],
            'fresh'   => [OfferTypeClassifier::FRESH, OfferTypeClassifier::DAILY_THALI, OfferTypeClassifier::SPECIAL],
            'special' => [OfferTypeClassifier::FESTIVAL, OfferTypeClassifier::WEEKEND, OfferTypeClassifier::SPECIAL, OfferTypeClassifier::DAILY_THALI],
            default   => OfferTypeClassifier::TYPES,   // menu / today -> everything, ranked
        };
    }
}
