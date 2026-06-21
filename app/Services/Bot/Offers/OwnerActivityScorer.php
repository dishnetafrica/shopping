<?php

namespace App\Services\Bot\Offers;

/**
 * Status Intelligence v15 — Owner Activity Scorer. Pure logic, no framework deps.
 *
 * Owners rarely use exact phrases. This reads a free-form owner message, detects the most likely
 * business-state event from its SIGNALS (food item, freshness, availability, stock, price), and
 * scores confidence 0-100. The caller acts on the band:
 *   >= 90  auto-apply        60-89  ask the owner to confirm        < 60  ignore
 *
 * A clean structured match (OwnerUpdateParser) short-circuits to confidence 95.
 *
 * @return array{event:?string,item:string,qty:?int,price:?int,display:string,confidence:int,source:string,signals:array}
 */
class OwnerActivityScorer
{
    private const FRESH = ['fresh', 'taaza', 'taza', 'garam', 'hot', 'new', 'batch', 'just made',
        'made fresh', 'out of kitchen', 'nikli', 'nikla'];
    private const AVAIL = ['available', 'ready', 'started', 'start', 'serving', 'serve', 'live',
        'open', 'taiyar', 'tayar', 'aavi gaya', 'aavi gayu', 'chalu', 'shuru'];
    private const SOLD  = ['sold out', 'soldout', 'finished', 'khatam', 'khattam', 'over', 'no more',
        'out of stock', 'nathi', 'khalas', 'empty'];
    private const LOW   = ['left', 'baki', 'baaki', 'remaining', 'bachi', 'bachya'];
    private const PRICE = ['price', 'rate', 'rs', 'rupiya', 'ugx', 'ksh', 'each'];
    private const MEAL  = ['lunch', 'dinner', 'breakfast', 'thali', 'menu', 'jaman', 'tiffin'];
    private const TEMP  = ['now', 'today', 'aaje', 'aaj', 'abhi', 'hamna'];
    private const EMOJI = ['😍', '🔥', '😋', '🤤', '👌', '🙏', '✨', '😘'];

    public static function score(string $text): array
    {
        $blank = ['event' => null, 'item' => '', 'qty' => null, 'price' => null,
                  'display' => '', 'confidence' => 0, 'source' => 'none', 'signals' => []];

        $raw = trim($text);
        if ($raw === '') return $blank;

        $t   = ' ' . trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]+/', ' ', mb_strtolower($raw)))) . ' ';
        $num = preg_match('/\b(\d{1,7})\b/', $t, $nm) ? (int) $nm[1] : null;

        $hasFresh = self::hasAny($t, self::FRESH);
        $hasAvail = self::hasAny($t, self::AVAIL);
        $hasSold  = self::hasAny($t, self::SOLD);
        $hasLow   = self::hasAny($t, self::LOW);
        $hasPrice = self::hasAny($t, self::PRICE);
        $hasMeal  = self::hasAny($t, self::MEAL);
        $hasTemp  = self::hasAny($t, self::TEMP);
        $hasEmoji = self::hasAnyRaw($raw, self::EMOJI);

        $foodItem = self::foodPhrase($t);
        $hasFood  = $foodItem !== '';
        $mealItem = self::firstOf($t, self::MEAL);

        // ---- choose the event ----
        $event = null; $item = ''; $qty = null; $price = null; $signals = [];

        if ($num !== null && $hasLow) {
            $event = 'low_stock'; $qty = $num; $item = $foodItem ?: ($mealItem ?: 'thali'); $signals[] = 'low_stock';
        } elseif ($num !== null && $hasPrice) {
            $event = 'price_change'; $price = $num; $item = $foodItem ?: $mealItem; $signals[] = 'price';
        } elseif ($hasSold) {
            $event = 'sold_out'; $item = $foodItem ?: $mealItem; $signals[] = 'sold';
        } elseif ($hasAvail || $hasFresh) {
            if ($hasFood)      { $event = 'available'; $item = $foodItem; }
            elseif ($hasMeal)  { $event = $hasAvail ? 'ready' : 'available'; $item = $mealItem; }
            elseif ($hasAvail) { $event = 'ready'; $item = ''; }            // generic readiness
            // fresh-only with no food/meal -> too vague, stays null
        }

        if ($event === null) return $blank;

        // ---- confidence ----
        $score = 0;
        if ($hasFood)  { $score += 30; $signals[] = 'food'; }
        if ($hasMeal)  { $score += 25; $signals[] = 'meal'; }
        if ($hasFresh) { $score += 20; $signals[] = 'fresh'; }
        if ($hasAvail) { $score += 45; $signals[] = 'avail'; }
        if ($hasSold)  { $score += 55; }
        if ($num !== null && $hasLow)   $score += 65;
        if ($num !== null && $hasPrice) $score += 65;
        if ($hasEmoji) { $score += 5; $signals[] = 'emoji'; }
        if ($hasTemp)  { $score += 8; $signals[] = 'temporal'; }

        // An unambiguous state change (stock word, number+left/price, or an availability verb with a
        // concrete subject) is "clear" -> auto band. Freshness/caption-like messages stay fuzzy.
        $clear = $hasSold
            || ($num !== null && ($hasLow || $hasPrice))
            || ($hasAvail && ($hasFood || $hasMeal));
        if ($clear) { $score = max($score, 92); $signals[] = 'clear'; }

        if (str_ends_with(rtrim($raw), '?')) $score -= 30;     // a question is not an assertion

        $score = max(0, min(100, $score));

        return [
            'event'      => $event,
            'item'       => $item,
            'qty'        => $qty,
            'price'      => $price,
            'display'    => $item !== '' ? ucwords($item) : '',
            'confidence' => $score,
            'source'     => $clear ? 'clear' : 'fuzzy',
            'signals'    => $signals,
        ];
    }

    private static function hasAny(string $t, array $kw): bool
    {
        foreach ($kw as $k) {
            if (str_contains($t, ' ' . $k . ' ') || str_contains($t, $k)) return true;
        }
        return false;
    }

    private static function hasAnyRaw(string $raw, array $kw): bool
    {
        foreach ($kw as $k) {
            if (str_contains($raw, $k)) return true;
        }
        return false;
    }

    private static function firstOf(string $t, array $words): string
    {
        foreach (explode(' ', trim($t)) as $w) {
            if (in_array($w, $words, true)) return $w;
        }
        return '';
    }

    /** Consecutive known-food tokens, joined (e.g. "hot fafda" -> "fafda", "tameta sev" -> "tameta sev"). */
    private static function foodPhrase(string $t): string
    {
        $out = [];
        foreach (explode(' ', trim($t)) as $w) {
            if ($w === '' || ctype_digit($w)) continue;
            if (ItemAliases::isKnownFood($w)) $out[] = $w;
        }
        return trim(implode(' ', $out));
    }
}
