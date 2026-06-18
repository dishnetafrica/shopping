<?php
namespace App\Services\Bot;

/**
 * Detects and parses a multi-line "shopping list" order, e.g.
 *
 *     Jsk
 *     2 packet panipuri
 *     2 packet kachori
 *     1 plain boondi
 *
 * A leading greeting line ("Jsk", "Jai Shree Krishna", "hi"…) must not cause the whole
 * message to be answered as a greeting. Each order line begins with a quantity. Pure logic —
 * the brain does the catalogue matching + cart building. Matching quality is unchanged; this
 * only routes a bulk list into the order flow instead of the greeting.
 */
class BulkOrderParser
{
    private const GREET = [
        'jsk', 'jai shree krishna', 'jai shri krishna', 'jai shree krisna', 'jay shree krishna',
        'jaishreekrishna', 'jai shree krushna', 'hi', 'hello', 'hey', 'namaste', 'namaskar',
        'good morning', 'good afternoon', 'good evening', 'ram ram', 'radhe radhe', 'kem cho',
    ];

    /** Pack/unit words to drop from the front of a parsed item (kept flavour words like "plain"). */
    private const UNITS = [
        'packet', 'packets', 'pkt', 'pkts', 'pcs', 'piece', 'pieces', 'pc', 'nos', 'no',
        'box', 'boxes', 'bag', 'bags', 'kg', 'kgs', 'gm', 'gms', 'g', 'ml', 'ltr', 'l', 'dozen',
    ];

    /** True when the message is a multi-line list with at least 2 quantity-led item lines. */
    public static function looksLikeBulkOrder(string $text): bool
    {
        return count(self::parseAll($text)) >= 2;
    }

    /** Returns the parsed item lines: [ ['qty'=>int, 'query'=>string], … ]. Greeting lines dropped. */
    public static function parseAll(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($text));
        $out = [];
        foreach ($lines as $ln) {
            $ln = trim($ln);
            if ($ln === '' || self::isGreetingOnly($ln)) continue;
            if ($p = self::parseLine($ln)) $out[] = $p;
        }
        return $out;
    }

    /** Parse one line "2 packet panipuri" → ['qty'=>2,'query'=>'panipuri']; null if not a qty-led line. */
    public static function parseLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '') return null;
        if (! preg_match('/^(\d{1,3})\s*(?:x|\*|-|\.)?\s+(.+)$/u', $line, $m)) return null;
        $qty = max(1, min(999, (int) $m[1]));
        $q   = self::cleanQuery($m[2]);
        if ($q === '') return null;
        return ['qty' => $qty, 'query' => $q];
    }

    private static function cleanQuery(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $units = implode('|', array_map('preg_quote', self::UNITS));
        $s = preg_replace('/^(?:' . $units . ')\b\s*/u', '', $s); // drop a leading unit word only
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
