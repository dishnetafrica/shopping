<?php
namespace App\Services\Bot\Merchant;

use App\Services\Bot\Pricing\WeightParser;

/**
 * Merchant Conversation Mode — deterministic multi-change extractor. Pure logic, no GPT.
 *
 * One natural-language merchant message may carry several changes. extract() splits it into
 * clauses and runs ordered detectors; each clause is consumed by at most one detector.
 * Product NAMES are returned as raw strings — the assistant resolves them to catalogue rows
 * later (via SearchService), so this stays pure and unit-testable.
 *
 * Returns:
 *   [
 *     'changes'   => [ {type, ...payload}, ... ],   // proposed changes (need YES)
 *     'selfcheck' => ['menu'|'specials'|'hours'|'availability', ...],  // read-only queries
 *     'unparsed'  => ['clause text', ...],          // shown back to the merchant, never guessed
 *   ]
 *
 * change shapes:
 *   {type:'menu',         items:['fafda','jalebi','patra']}
 *   {type:'availability', target:'khakhra', available:false}
 *   {type:'special',      target:'jalebi'}
 *   {type:'hours',        open:'10:00'|null, close:'19:00'|null, closed:bool|null}
 *   {type:'price',        target:'kaju katri', weight_grams:1000|null, price:90000}
 *   {type:'notice',       text:'Delivery after 5pm today'}
 *   {type:'note',         text:'call supplier'}
 */
class MerchantConversationParser
{
    public static function extract(string $text): array
    {
        $changes = []; $selfcheck = []; $unparsed = [];

        foreach (self::clauses($text) as $clause) {
            $c = trim($clause);
            if ($c === '') continue;

            if ($q = self::selfCheck($c)) { $selfcheck = array_values(array_unique(array_merge($selfcheck, $q))); continue; }

            // ordered: most specific first; notice before availability/menu (time-conditioned)
            $hit = self::note($c)
                ?? self::notice($c)
                ?? self::price($c)
                ?? self::hours($c)
                ?? self::menu($c)
                ?? self::special($c)
                ?? self::availability($c);

            if ($hit) $changes[] = $hit; else $unparsed[] = $c;
        }

        return ['changes' => $changes, 'selfcheck' => $selfcheck, 'unparsed' => $unparsed];
    }

    // ---- clause splitting ----
    private static function clauses(string $t): array
    {
        return preg_split('/[.;\n\r!]+/u', trim($t)) ?: [];
    }

    // ---- detectors (each returns a change array or null) ----

    private static function note(string $c): ?array
    {
        if (preg_match('/^\s*note[:\-]\s*(.+)$/i', $c, $m)) {
            return ['type' => 'note', 'text' => trim($m[1])];
        }
        return null;
    }

    private static function notice(string $c): ?array
    {
        $l = mb_strtolower($c);
        $isNotice =
            preg_match('/\b(?:delivery|deliver)\b.*\bafter\b\s*\d/i', $c) ||
            preg_match('/\b(?:only cash|cash only)\b/i', $c) ||
            preg_match('/\bclosed for\b.*\d/i', $c) ||                       // closed for lunch 1pm-2pm
            preg_match('/\bfresh\b.+\bafter\b\s*\d/i', $c) ||               // fresh jalebi after 4pm
            preg_match('/\bafter\b\s*\d{1,2}\s*(?:am|pm)\b/i', $c) ||
            preg_match('/\d{1,2}\s*(?:am|pm)?\s*[-–to]+\s*\d{1,2}\s*(?:am|pm)\b/i', $c); // time range
        return $isNotice ? ['type' => 'notice', 'text' => trim($c)] : null;
    }

    private static function price(string $c): ?array
    {
        // product + weight + price  ("Kaju katri 1kg 90000")
        if (preg_match('/^(.+?)\s+(\d+(?:\.\d+)?\s*(?:kgs?|kilos?|kilograms?|gm?s?|grams?|g))\s+(\d{3,})\s*$/i', $c, $m)) {
            return ['type' => 'price', 'target' => self::cleanTarget($m[1]),
                    'weight_grams' => WeightParser::grams($m[2]), 'price' => (int) $m[3]];
        }
        // verb form  ("Increase fafda to 35000", "set fafda 35000")
        if (preg_match('/\b(?:increase|set|change|update|reduce|lower|raise|price)\s+(.+?)\s+(?:to|=|at|by)?\s*(\d{3,})\s*$/i', $c, $m)) {
            return ['type' => 'price', 'target' => self::cleanTarget($m[1]), 'weight_grams' => null, 'price' => (int) $m[2]];
        }
        // bare  ("fafda 35000") — require >=4 digits so it can't be a weight/qty
        if (preg_match('/^([a-z][a-z \']+?)\s+(\d{4,})\s*$/i', $c, $m)) {
            return ['type' => 'price', 'target' => self::cleanTarget($m[1]), 'weight_grams' => null, 'price' => (int) $m[2]];
        }
        return null;
    }

    private static function hours(string $c): ?array
    {
        $l = mb_strtolower($c);
        if (preg_match('/\bclosed for\b/i', $l)) return null;               // that's a notice, not a closure
        $out = ['type' => 'hours', 'open' => null, 'close' => null, 'closed' => null];
        $found = false;
        if (preg_match('/\bclosed(?:\s+today)?\b/i', $l) && ! preg_match('/\bclose\s+(?:at\s*)?\d/i', $l)) {
            $out['closed'] = true; $found = true;
        }
        if (preg_match('/\bopen(?:s|ing)?\b\s*(?:at|from)?\s*(\d{1,2})(?::(\d{2}))?\s*(am|pm)?/i', $l, $m)) {
            $out['open'] = self::time((int) $m[1], (int) ($m[2] ?? 0), $m[3] ?? '', 'open'); $found = true;
        }
        if (preg_match('/\bclose(?:s|d|ing)?\b\s*(?:at)?\s*(\d{1,2})(?::(\d{2}))?\s*(am|pm)?/i', $l, $m)) {
            $out['close'] = self::time((int) $m[1], (int) ($m[2] ?? 0), $m[3] ?? '', 'close'); $found = true;
        }
        return $found ? $out : null;
    }

    private static function menu(string $c): ?array
    {
        if (preg_match('/\btoday(?:\'?s)?\b.*?\b(?:menu|we have|available|hava)\b\s*[:\-]?\s*(.+)$/i', $c, $m)
            || preg_match('/^\s*menu\s*[:\-]\s*(.+)$/i', $c, $m)) {
            $items = self::splitList($m[1]);
            if ($items) return ['type' => 'menu', 'items' => $items];
        }
        return null;
    }

    private static function special(string $c): ?array
    {
        if (preg_match('/\b(?:today\'?s\s+special|special|promote|feature)\b\s*[:\-]?\s*(.+)$/i', $c, $m)) {
            $t = self::cleanTarget($m[1]);
            if ($t !== '') return ['type' => 'special', 'target' => $t];
        }
        return null;
    }

    private static function availability(string $c): ?array
    {
        // unavailable
        if (preg_match('/\b(?:out of stock|sold out|finished|khatam|nathi)\b\s*(.+)$/i', $c, $m)
            || preg_match('/\b(?:don\'?t|do not|not)\s+(?:sell|selling)\b\s+(.+)$/i', $c, $m)
            || preg_match('/\bno\s+([a-z\'].+)$/i', $c, $m)) {
            $t = self::cleanTarget($m[1]);
            if ($t !== '') return ['type' => 'availability', 'target' => $t, 'available' => false];
        }
        // available again (no time condition — that path was claimed by notice())
        if (preg_match('/\b(?:fresh|back in stock|back|now available|available)\b\s*(.+)$/i', $c, $m)
            || preg_match('/\bwe have\s+fresh\s+(.+)$/i', $c, $m)) {
            $t = self::cleanTarget($m[1]);
            if ($t !== '') return ['type' => 'availability', 'target' => $t, 'available' => true];
        }
        return null;
    }

    private static function selfCheck(string $c): array
    {
        $l = mb_strtolower(trim($c));
        $isQuestion = str_ends_with($l, '?') || preg_match('/^(what|whats|what\'s|are|is|do|does|r|can|how|when)\b/i', $l);
        if (! $isQuestion) return [];
        $out = [];
        if (preg_match('/today\'?s?\s+menu/i', $l))                          $out[] = 'menu';
        if (preg_match('/today\'?s?\s+special/i', $l))                       $out[] = 'specials';
        if (preg_match('/\bopen\b/i', $l) || preg_match('/\bclose\b/i', $l)) $out[] = 'hours';
        if (preg_match('/available|in stock|do we have/i', $l))              $out[] = 'availability';
        return $out;
    }

    // ---- helpers ----

    private static function time(int $h, int $min, string $ampm, string $kind): string
    {
        $ampm = strtolower($ampm);
        if ($ampm === 'pm' && $h < 12) $h += 12;
        elseif ($ampm === 'am' && $h === 12) $h = 0;
        elseif ($ampm === '') {
            // no am/pm: shops open in the morning, close in the evening
            if ($kind === 'close' && $h >= 1 && $h <= 11) $h += 12;
            // open stays as-is (10 -> 10:00)
        }
        return sprintf('%02d:%02d', $h % 24, $min);
    }

    private static function splitList(string $s): array
    {
        $s = trim($s);
        if (preg_match('/,| and | ane |;/i', $s)) {
            $parts = preg_split('/\s*,\s*|\s+and\s+|\s+ane\s+|\s*;\s*/i', $s);
        } else {
            $parts = preg_split('/\s+/u', $s);                              // "Fafda Jalebi Patra"
        }
        $out = [];
        foreach ($parts as $p) {
            $p = self::cleanTarget($p);
            if ($p !== '' && ! in_array($p, ['today', 'and', 'the', 'we', 'have'], true)) $out[] = $p;
        }
        return array_values(array_unique($out));
    }

    private static function cleanTarget(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/[^\p{L}\s\']+/u', ' ', $s);
        $s = preg_replace('/\b(today|please|pls|the|any|some|now)\b/i', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }
}
