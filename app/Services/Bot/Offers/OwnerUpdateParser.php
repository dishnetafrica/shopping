<?php

namespace App\Services\Bot\Offers;

/**
 * Status Intelligence — owner intent parser. Pure logic, no framework deps.
 *
 * Turns a short owner WhatsApp message into a business-state event. Conservative: only fires on
 * clear update phrases (≤ 8 words), so ordinary owner chatter is ignored.
 *
 *   "Only 5 Thali Left"   -> low_stock,    item thali,  qty 5
 *   "Fafda Sold Out"      -> sold_out,     item fafda
 *   "Fresh Jalebi Ready"  -> available,    item jalebi
 *   "Lunch Ready"         -> ready,        item lunch
 *   "Jalebi available"    -> available,    item jalebi
 *   "Thali price 15000"   -> price_change, item thali,  price 15000
 *
 * @return array{event:string,item:string,qty?:int,price?:int,display:string}|null
 */
class OwnerUpdateParser
{
    private const SOLD  = ['sold out', 'soldout', 'sold-out', 'out of stock', 'stock out', 'no more',
        'finished', 'khatam', 'khattam', 'khalas', 'samapt', 'samap', 'nathi', 'over', 'empty', 'done', 'sold'];
    private const READY = ['ready', 'taiyar', 'tayar', 'taiyaar'];
    private const FRESH = ['fresh', 'taaza', 'taza', 'garam garam', 'garam', 'just made', 'made fresh', 'new batch', 'hot'];
    private const AVAIL = ['available', 'in stock', 'aavi gaya', 'aavi gayu', 'aavi gai', 'mali jashe'];
    private const MEAL  = ['lunch', 'dinner', 'breakfast', 'thali', 'menu', 'jaman'];
    private const LOW   = ['left', 'baki', 'baaki', 'remaining', 'bachi', 'bachya', 'bache'];
    private const PRICEW = ['price', 'rate', 'now', 'each', 'rs', 'rupiya', 'ugx', 'ksh', 'shilling', 'shillings'];
    private const STOP  = ['the', 'is', 'are', 'today', 'only', 'for', 'guys', 'all', 'our', 'of', 'a',
        'an', 'please', 'che', 'chhe', 'aaje', 'aaj', 'and', 'we', 'have', 'got', 'some', 'left'];

    public static function parse(string $text): ?array
    {
        $t = trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]+/', ' ', mb_strtolower($text))));
        if ($t === '') return null;

        $words = explode(' ', $t);
        if (count($words) > 8) return null;                 // updates are short

        // A) low_stock / price_change — anything with a number
        if (preg_match('/\b(\d{1,7})\b/', $t, $nm)) {
            $n = (int) $nm[1];
            if (self::hasAny($t, self::LOW)) {
                $item = self::strip($t, array_merge(self::LOW, self::STOP)) ?: 'thali';
                return ['event' => 'low_stock', 'item' => $item, 'qty' => $n, 'display' => self::disp($item)];
            }
            if (self::hasAny($t, self::PRICEW)) {
                $item = self::strip($t, array_merge(self::PRICEW, self::STOP));
                if ($item === '') return null;
                return ['event' => 'price_change', 'item' => $item, 'price' => $n, 'display' => self::disp($item)];
            }
        }

        // B) sold_out
        if (self::hasAny($t, self::SOLD)) {
            $item = self::strip($t, array_merge(self::SOLD, self::STOP));
            if ($item === '') return null;
            return ['event' => 'sold_out', 'item' => $item, 'display' => self::disp($item)];
        }

        // C) ready / fresh / available
        $hasReady = self::hasAny($t, self::READY);
        if ($hasReady || self::hasAny($t, self::FRESH) || self::hasAny($t, self::AVAIL)) {
            $item = self::strip($t, array_merge(self::READY, self::FRESH, self::AVAIL, self::STOP));
            if ($item === '') return null;
            $isMeal = (bool) array_intersect(explode(' ', $item), self::MEAL);
            $event  = ($isMeal && $hasReady) ? 'ready' : 'available';
            return ['event' => $event, 'item' => $item, 'display' => self::disp($item)];
        }

        return null;
    }

    private static function hasAny(string $t, array $kw): bool
    {
        foreach ($kw as $k) {
            if (str_contains($t, $k)) return true;
        }
        return false;
    }

    /** Remove every phrase (longest first) + filler, return the remaining item words. */
    private static function strip(string $t, array $phrases): string
    {
        usort($phrases, fn ($a, $b) => strlen($b) <=> strlen($a));
        foreach ($phrases as $p) {
            $t = preg_replace('/\b' . preg_quote($p, '/') . '\b/', ' ', $t);
        }
        $toks = array_filter(
            explode(' ', trim(preg_replace('/\s+/', ' ', $t))),
            fn ($w) => $w !== '' && ! ctype_digit($w)
        );
        return trim(implode(' ', $toks));
    }

    private static function disp(string $item): string
    {
        return ucwords(trim($item));
    }
}
