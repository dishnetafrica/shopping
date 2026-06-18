<?php
namespace App\Services\Bot;

/**
 * Detects and parses a multi-line / multi-item "shopping list" order the way Pal's
 * (Gujarati/Kampala) customers actually type it, e.g.
 *
 *     Jsk
 *     2 packet panipuri          (qty first)
 *     kachori 2 packet           (qty after product)
 *     be packet sev              (Gujarati number word: be = 2)
 *     1kg ghathiya               (glued qty+unit)
 *     2 panipuri, 2 kachori      (comma / "and" / "ane" / "+" on one line)
 *
 * A leading greeting line ("Jsk", "Jai Shree Krishna", "kem cho", "hi") must not make the
 * whole message read as a greeting. Pure logic — the brain does catalogue matching + cart
 * building, so match quality is unchanged; this only routes a list into the order flow.
 */
class BulkOrderParser
{
    private const GREET = [
        'jsk', 'jai shree krishna', 'jai shri krishna', 'jai shree krisna', 'jay shree krishna',
        'jaishreekrishna', 'jai shree krushna', 'hi', 'hii', 'hello', 'helo', 'hey', 'namaste',
        'namaskar', 'good morning', 'good afternoon', 'good evening', 'ram ram', 'radhe radhe',
        'kem cho', 'kemcho', 'jai shree', 'jsk bhai',
    ];

    /** Pack/unit words dropped from the front OR back of an item (flavour words like "plain" kept). */
    private const UNITS = [
        'packet', 'packets', 'pkt', 'pkts', 'pack', 'packs', 'pcs', 'piece', 'pieces', 'pc',
        'nag', 'nags', 'nug', 'nos', 'no', 'box', 'boxes', 'dabba', 'dabbo', 'bag', 'bags',
        'thela', 'thaila', 'kg', 'kgs', 'gm', 'gms', 'g', 'ml', 'ltr', 'l', 'dozen', 'dz',
    ];

    /** Gujlish + English number words. Kept to order-context words; collisions are low risk here. */
    private const NUMWORDS = [
        'ek' => 1, 'be' => 2, 'bay' => 2, 'tran' => 3, 'tren' => 3, 'char' => 4, 'chaar' => 4,
        'paanch' => 5, 'panch' => 5, 'paach' => 5, 'chh' => 6, 'chha' => 6, 'cha' => 6,
        'saat' => 7, 'sat' => 7, 'aath' => 8, 'ath' => 8, 'nav' => 9, 'nau' => 9, 'das' => 10, 'dus' => 10,
        'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6,
        'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
    ];

    public static function looksLikeBulkOrder(string $text): bool
    {
        return count(self::parseAll($text)) >= 2;
    }

    /** Returns parsed item lines [ ['qty'=>int,'query'=>string], … ]. Greeting parts dropped. */
    public static function parseAll(string $text): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', trim($text)) as $line) {
            // One line may carry several items: commas, "and", "ane", "+".
            foreach (preg_split('/\s*,\s*|\s+and\s+|\s+ane\s+|\s*\+\s*/iu', trim($line)) as $part) {
                $part = trim($part);
                if ($part === '' || self::isGreetingOnly($part)) continue;
                if ($p = self::parseLine($part)) $out[] = $p;
            }
        }
        return $out;
    }

    /** Parse one item fragment into ['qty','query']; null if no quantity is present. */
    public static function parseLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '') return null;
        $toks = preg_split('/\s+/u', mb_strtolower($line));

        // A) leading numeric qty — "2 packet panipuri", "1kg sev", "2-panipuri"
        if (preg_match('/^(\d{1,3})\s*(?:x|\*|-|\.)?\s*(.+)$/u', $line, $m)) {
            $q = self::cleanQuery($m[2]);
            if ($q !== '') return ['qty' => self::clamp($m[1]), 'query' => $q];
        }
        // C) trailing numeric qty (+ optional unit) — "kachori 2", "sev 1 kg", "panipuri 2 packet"
        if (preg_match('/^(.+?)\s+(\d{1,3})\s*([\p{L}]+)?$/u', $line, $m)) {
            $unit = mb_strtolower($m[3] ?? '');
            if ($unit === '' || in_array($unit, self::UNITS, true)) {
                $q = self::cleanQuery($m[1]);
                if ($q !== '') return ['qty' => self::clamp($m[2]), 'query' => $q];
            }
        }
        // B) leading number word — "be packet kachori", "ek farsi puri"
        if (count($toks) >= 2 && isset(self::NUMWORDS[$toks[0]])) {
            $q = self::cleanQuery(implode(' ', array_slice($toks, 1)));
            if ($q !== '') return ['qty' => self::NUMWORDS[$toks[0]], 'query' => $q];
        }
        // D) trailing number word — "kachori be"
        if (count($toks) >= 2 && isset(self::NUMWORDS[end($toks)])) {
            $q = self::cleanQuery(implode(' ', array_slice($toks, 0, -1)));
            if ($q !== '') return ['qty' => self::NUMWORDS[end($toks)], 'query' => $q];
        }
        return null;
    }

    private static function clamp($n): int
    {
        return max(1, min(999, (int) $n));
    }

    private static function cleanQuery(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $units = implode('|', array_map('preg_quote', self::UNITS));
        $s = preg_replace('/^(?:' . $units . ')\b\s*/u', '', $s);   // drop leading unit word
        $s = preg_replace('/\s*\b(?:' . $units . ')$/u', '', $s);   // drop trailing unit word
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    private static function isGreetingOnly(string $line): bool
    {
        $l = mb_strtolower(trim($line));
        $l = trim(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $l));
        $l = preg_replace('/\s+/', ' ', $l);
        return in_array($l, self::GREET, true);
    }
}
