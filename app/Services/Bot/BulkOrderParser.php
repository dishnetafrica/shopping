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
 *     Paneer, Khakhra, Gathiya   (bare products, no qty -> qty 1 each)         [defect-fix]
 *     Need 1kg Paneer and 2kg Mavo   (qty in the middle of a fragment)         [defect-fix]
 *     Paneer 1kg Khakhra 2 packets Mavo 500gm  (space-separated multi-item)    [defect-fix]
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
        'thela', 'thaila', 'kg', 'kgs', 'gm', 'gms', 'g', 'gram', 'grams', 'kilo', 'kilos',
        'ml', 'ltr', 'lt', 'litre', 'liter', 'l', 'dozen', 'dz',
    ];

    /** Gujlish + English number words. Kept to order-context words; collisions are low risk here. */
    private const NUMWORDS = [
        'ek' => 1, 'be' => 2, 'bay' => 2, 'tran' => 3, 'tren' => 3, 'char' => 4, 'chaar' => 4,
        'paanch' => 5, 'panch' => 5, 'paach' => 5, 'chh' => 6, 'chha' => 6, 'cha' => 6,
        'saat' => 7, 'sat' => 7, 'aath' => 8, 'ath' => 8, 'nav' => 9, 'nau' => 9, 'das' => 10, 'dus' => 10,
        'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6,
        'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
    ];

    /** Leading verbs/articles/greetings dropped from a fragment before reading the product ("for" kept). */
    private const VERBS = [
        'i','we','u','you','me','my','need','want','wanted','give','bring','send','pls','please','plz',
        'kindly','get','got','gimme','lemme','order','the','a','an','some','do','have','also','add','to',
        'can','could','would','will','shall','may','let','looking',
    ];

    /** Leading greeting tokens (with elongations) also stripped before reading the product. */
    private const GREET_TOK = '/^(hi+|hello+|helo+|hey+|hii+|hlo|heloo+|jsk|namaste|namaskar|jai|shree|shri|krishna|krisna|krushna|ram|radhe|kem|cho)$/u';

    /** Single tokens that are never a product (chit-chat / affirmations / time words). */
    private const BARE_REJECT = [
        'ok','okay','okk','okkk','yes','no','np','thanks','thank','thanx','tnx','sorry','welcome',
        'nice','hmm','ji','haan','ha','baraber','barabar','thik','sir','madam','bhai','bhabhi','bhaiya',
        'today','tomorrow','now','here','there','done','ready','for','party','then','please','pls','hi',
        'not','half','quarter','only','extra','just','mean','less','more','no','nahi','nai',
    ];

    /** Words that mark a number as a clock time, not an order quantity. */
    private const TIME = ['pm', 'am', 'oclock', 'noon', 'baje', 'hrs', 'hr', 'clock'];

    public static function looksLikeBulkOrder(string $text): bool
    {
        return count(self::parseAll($text)) >= 2;
    }

    /** Returns parsed item lines [ ['qty'=>int,'query'=>string], … ]. Greeting parts dropped. */
    public static function parseAll(string $text): array
    {
        $out = [];
        // A no-quantity question ("Do you have almonds and walnuts?") is an availability query,
        // not an order — don't turn its bare product words into cart lines (avoids false adds).
        $hasQty = preg_match('/\d/u', $text)
            || preg_match('/\b(ek|be|bay|tran|tren|char|chaar|paanch|panch|paach|chh|chha|saat|sat|aath|ath|nav|nau|das|dus|one|two|three|four|five|six|seven|eight|nine|ten)\b/iu', $text);
        $isQuestion = preg_match('/\?\s*$/u', $text)
            || preg_match('/^\s*(do|does|did|is|are|have|has|can|could|will|would|kya|shu|su|what|which)\b/iu', trim($text));
        $allowBare = ! ($isQuestion && ! $hasQty);

        foreach (preg_split('/\r\n|\r|\n/', trim($text)) as $line) {
            foreach (preg_split('/\s*,\s*|\s+and\s+|\s+ane\s+|\s*\+\s*/iu', trim($line)) as $part) {
                $part = trim($part);
                if ($part === '' || self::isGreetingOnly($part)) continue;
                if (self::looksLikePhone($part)) continue;             // phone number, not an order line
                foreach (self::segmentByQty($part) as $seg) {
                    $seg = trim($seg);
                    if ($seg === '' || self::isGreetingOnly($seg)) continue;
                    if ($p = self::parseLine($seg)) { if (! self::isJunkQuery($p['query'])) $out[] = $p; continue; }
                    if ($allowBare && ($bare = self::bareProduct($seg)) !== null) $out[] = ['qty' => 1, 'query' => $bare];
                }
            }
        }
        return $out;
    }

    /** Parse one item fragment into ['qty','query']; null if it has no quantity at all. */
    public static function parseLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '') return null;
        $toks = preg_split('/\s+/u', mb_strtolower($line));

        // A) leading numeric qty — "2 packet panipuri", "1kg sev", "2-panipuri"
        if (preg_match('/^(\d{1,3})\s*(?:x|\*|-|\.)?\s*(.+)$/u', $line, $m)) {
            $first = preg_split('/\s+/u', trim(mb_strtolower($m[2])))[0] ?? '';
            if (! in_array($first, self::TIME, true)) {                 // "7 pm" is a time, not a qty
                $q = self::cleanQuery($m[2]);
                if ($q !== '') {
                    $r = ['qty' => self::clamp($m[1]), 'query' => $q];
                    if ($g = self::gramsFor((int) $m[1], $first)) $r['weight_grams'] = $g;
                    return $r;
                }
            }
        }
        // C) trailing numeric qty (+ optional unit) — "kachori 2", "sev 1 kg", "panipuri 2 packet"
        if (preg_match('/^(.+?)\s+(\d{1,3})\s*([\p{L}]+)?$/u', $line, $m)) {
            $unit = mb_strtolower($m[3] ?? '');
            if ($unit === '' || in_array($unit, self::UNITS, true)) {
                $q = self::cleanQuery($m[1]);
                if ($q !== '') {
                    $r = ['qty' => self::clamp($m[2]), 'query' => $q];
                    if ($g = self::gramsFor((int) $m[2], $unit)) $r['weight_grams'] = $g;
                    return $r;
                }
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
        // E) interior numeric qty — "need 1kg paneer", "1kg paneer please"   [defect-fix]
        if (preg_match('/^(.*?)\b(\d{1,3})\s*([\p{L}]+)?\b(.*)$/u', $line, $m)) {
            $unit = mb_strtolower($m[3] ?? '');
            if ($unit === '' || in_array($unit, self::UNITS, true)) {
                $rest = self::stripLeadingVerbs(trim(($m[1] ?? '') . ' ' . ($m[4] ?? '')));
                $q = self::cleanQuery($rest);
                if ($q !== '') {
                    $r = ['qty' => self::clamp($m[2]), 'query' => $q];
                    if ($g = self::gramsFor((int) $m[2], $unit)) $r['weight_grams'] = $g;
                    return $r;
                }
            }
        }
        return null;
    }

    /**
     * Split a single fragment that carries TWO OR MORE quantities into one sub-fragment per item.
     * Handles both qty-leading ("1kg ghathiya 2 packet sev") and qty-trailing
     * ("paneer 1kg khakhra 2 packets mavo 500gm"). Fragments with <2 quantities are returned as-is.
     */
    private static function segmentByQty(string $frag): array
    {
        $clean = self::stripLeadingVerbs($frag);
        $toks = preg_split('/\s+/u', trim(mb_strtolower($clean)), -1, PREG_SPLIT_NO_EMPTY);
        if (count($toks) < 2) return [$frag];
        $isQty = static function ($t) {
            if (isset(self::NUMWORDS[$t])) return true;
            if (! preg_match('/^(\d{1,3})([\p{L}]*)$/u', $t, $mm)) return false;
            return ! in_array(mb_strtolower($mm[2]), self::TIME, true);   // "7pm"/"8am" are times, not qty
        };
        $qtyCount = 0;
        foreach ($toks as $t) if ($isQty($t)) $qtyCount++;
        if ($qtyCount < 2) return [$frag];

        $segs = []; $buf = [];
        if ($isQty($toks[0])) {                       // qty-leading: a new segment begins at each qty
            foreach ($toks as $t) {
                if ($isQty($t) && $buf) { $segs[] = implode(' ', $buf); $buf = [$t]; }
                else $buf[] = $t;
            }
            if ($buf) $segs[] = implode(' ', $buf);
        } else {                                       // qty-trailing: close a segment after each qty(+unit)
            $n = count($toks);
            for ($i = 0; $i < $n; $i++) {
                $buf[] = $toks[$i];
                if ($isQty($toks[$i])) {
                    if ($i + 1 < $n && in_array($toks[$i + 1], self::UNITS, true)) { $buf[] = $toks[++$i]; }
                    $segs[] = implode(' ', $buf); $buf = [];
                }
            }
            if ($buf) $segs[] = implode(' ', $buf);
        }
        return $segs ?: [$frag];
    }

    /** A bare product (no quantity): single product token only, never chit-chat. null otherwise. */
    /** A parsed query that is a single negation/modifier token is not a product. */
    private static function isJunkQuery(string $q): bool
    {
        $t = preg_split('/\s+/u', trim($q), -1, PREG_SPLIT_NO_EMPTY);
        return count($t) === 1 && in_array($t[0], self::BARE_REJECT, true);
    }

    private static function bareProduct(string $seg): ?string
    {
        $s = preg_replace('/[^\p{L}\s]+/u', ' ', mb_strtolower(trim($seg)));
        $s = self::cleanQuery(self::stripLeadingVerbs($s));
        $toks = preg_split('/\s+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY);
        if (count($toks) !== 1) return null;          // single-token only — avoids parsing prose as items
        $w = $toks[0];
        if (mb_strlen($w) < 2) return null;
        if (in_array($w, self::GREET, true) || in_array($w, self::UNITS, true)
            || in_array($w, self::TIME, true)
            || isset(self::NUMWORDS[$w]) || in_array($w, self::BARE_REJECT, true)) return null;
        return $w;
    }

    private static function stripLeadingVerbs(string $s): string
    {
        $toks = preg_split('/\s+/u', trim(mb_strtolower($s)), -1, PREG_SPLIT_NO_EMPTY);
        while ($toks && (in_array($toks[0], self::VERBS, true) || preg_match(self::GREET_TOK, $toks[0]))) {
            array_shift($toks);
        }
        return implode(' ', $toks);
    }

    private static function clamp($n): int
    {
        return max(1, min(999, (int) $n));
    }

    /** Grams for a number+unit pair when the unit is a weight unit; null otherwise. */
    private const WEIGHT_KG = ['kg', 'kgs', 'kilo', 'kilos', 'kilogram', 'kilograms'];
    private const WEIGHT_G  = ['g', 'gm', 'gms', 'gram', 'grams'];
    private static function gramsFor(int $n, string $unit): ?int
    {
        $u = mb_strtolower(trim($unit));
        if (in_array($u, self::WEIGHT_KG, true)) return $n * 1000;
        if (in_array($u, self::WEIGHT_G, true))  return $n;
        return null;
    }

    private static function cleanQuery(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $units = implode('|', array_map('preg_quote', self::UNITS));
        $s = preg_replace('/^(?:' . $units . ')\b\s*/u', '', $s);
        $s = preg_replace('/\s*\b(?:' . $units . ')$/u', '', $s);
        $s = self::stripLeadingVerbs($s);                       // drop leading greeting/verb noise
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    /** A run of >=9 digits uninterrupted by letters (spaces/+/- allowed) is a phone number, not an order. */
    private static function looksLikePhone(string $s): bool
    {
        foreach (preg_split('/\p{L}+/u', $s) as $chunk) {
            if (strlen(preg_replace('/\D/', '', $chunk)) >= 9) return true;
        }
        return false;
    }

    private static function isGreetingOnly(string $line): bool
    {
        $l = mb_strtolower(trim($line));
        $l = trim(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $l));
        $l = preg_replace('/\s+/', ' ', $l);
        return in_array($l, self::GREET, true);
    }
}
