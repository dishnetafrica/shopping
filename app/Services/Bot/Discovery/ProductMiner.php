<?php

namespace App\Services\Bot\Discovery;

/**
 * Business Discovery — product extraction. Pure logic.
 *
 * Two modes:
 *  - Catalogue mode (a non-empty $catalogue is passed): the catalogue is the WHITELIST. Discovered
 *    terms are matched against real product names / keywords / category. Only products that exist in
 *    the catalogue AND have a real signal (chat mention or order) are returned. Frequent chat terms
 *    that match no product are returned separately as UNVERIFIED candidates (never shown in Top
 *    Products). This stops generic chat words ("Https", "More", "Good") becoming products.
 *  - Legacy mode (no catalogue): orders are ground truth, recurring nouns are weak candidates. Used
 *    by tenants with no catalogue and by pure unit tests.
 *
 * @return list of ['name'=>string,'count'=>int,'confidence'=>int,'source'=>'orders'|'chat']
 */
class ProductMiner
{
    private const STOP = [
        'the','and','for','you','your','please','want','need','have','this','that','with','from',
        'hello','hi','hey','thanks','thank','order','delivery','deliver','how','much','price','can',
        'will','are','was','yes','no','ok','okay','today','now','get','give','send','one','two','some',
        'na','kwa','ya','wa','la','do','to','of','is','it','me','my','we','at','in','on','a','an',
    ];

    /** Obvious non-products that should never become candidates, even unverified. */
    private const GENERIC = [
        'http','https','www','com','net','org','co','message','more','info','photo','what','good',
        'available','number','whatsapp','link','click','here','time','dinner','lunch','morning',
        'evening','night','minutes','hours','welcome','sorry','sure','fine','great',
    ];

    public static function mine(MessageCorpus $corpus, array $orders, array $catalogue = [], int $top = 15): array
    {
        return self::analyze($corpus, $orders, $catalogue, $top)['products'];
    }

    /** Full result: verified catalogue products + unverified candidate terms. */
    public static function analyze(MessageCorpus $corpus, array $orders, array $catalogue = [], int $top = 15): array
    {
        if (empty($catalogue)) {
            return ['products' => self::legacy($corpus, $orders, $top), 'unverified' => []];
        }

        $index = [];  // term => [productKey => true]
        $prod  = [];  // productKey => ['name','category','chat'=>0,'orders'=>0]
        foreach ($catalogue as $p) {
            $name = trim((string) ($p['name'] ?? ''));
            if ($name === '') continue;
            $key = self::keyNorm($name);
            if ($key === '') continue;
            $prod[$key] = ['name' => $name, 'category' => (string) ($p['category'] ?? ''), 'chat' => 0, 'orders' => 0];
            foreach (self::termsFor($p) as $term) {
                $index[$term][$key] = true;
            }
        }

        $tokenCounts = [];
        foreach ($corpus->customer as $msg) foreach (self::tokens($msg) as $w) $tokenCounts[$w] = ($tokenCounts[$w] ?? 0) + 1;
        foreach ($corpus->owner as $msg)    foreach (self::tokens($msg) as $w) $tokenCounts[$w] = ($tokenCounts[$w] ?? 0) + 1;

        $matched = [];
        foreach ($tokenCounts as $w => $c) {
            if (! isset($index[$w])) continue;
            foreach ($index[$w] as $key => $_) $prod[$key]['chat'] += $c;
            $matched[$w] = true;
        }

        $allText = ' ' . self::keyNorm(implode(' ', array_merge($corpus->customer, $corpus->owner))) . ' ';
        foreach ($prod as $key => $pp) {
            if (substr_count($key, ' ') >= 1) {
                $cnt = substr_count($allText, ' ' . $key . ' ');
                if ($cnt > 0) $prod[$key]['chat'] += $cnt * 2;
            }
        }

        foreach ($orders as $o) {
            foreach (self::itemsOf($o) as $itemName) {
                $key = self::matchItem($itemName, $prod, $index);
                if ($key !== null) $prod[$key]['orders'] += 1;
            }
        }

        $out = [];
        foreach ($prod as $pp) {
            $signal = $pp['chat'] + $pp['orders'];
            if ($signal <= 0) continue;
            $score = $pp['orders'] * 5 + $pp['chat'];
            $conf  = $pp['orders'] > 0 ? min(98, 75 + $pp['orders'] * 3) : min(85, 45 + $pp['chat'] * 3);
            $out[] = [
                'name'       => $pp['name'],
                'count'      => $signal,
                'confidence' => $conf,
                'source'     => $pp['orders'] > 0 ? 'orders' : 'chat',
                '_score'     => $score,
            ];
        }
        usort($out, fn ($a, $b) => $b['_score'] <=> $a['_score']);
        $out = array_slice($out, 0, $top);
        foreach ($out as &$o2) unset($o2['_score']);
        unset($o2);

        $unv = [];
        foreach ($tokenCounts as $w => $c) {
            if ($c < 4 || isset($matched[$w])) continue;
            if (in_array($w, self::STOP, true) || in_array($w, self::GENERIC, true)) continue;
            if (preg_match('/\d/', $w) && mb_strlen($w) <= 3) continue;
            $unv[] = ['term' => self::title($w), 'count' => $c];
        }
        usort($unv, fn ($a, $b) => $b['count'] <=> $a['count']);
        $unv = array_slice($unv, 0, 10);

        return ['products' => $out, 'unverified' => $unv];
    }

    /**
     * No catalogue available — trust ONLY real order history. Chat words are NEVER shown as
     * products (that was the "Https / Tinyurl / More" bug). No catalogue and no orders ⇒ no
     * products, which is the honest result until the owner connects a catalogue.
     */
    private static function legacy(MessageCorpus $corpus, array $orders, int $top): array
    {
        $counts = [];
        foreach ($orders as $o) {
            foreach (self::itemsOf($o) as $name) {
                $k = self::norm($name);
                if ($k === '') continue;
                $counts[$k] = ($counts[$k] ?? 0) + 1;
            }
        }
        arsort($counts);
        $out = [];
        foreach (array_slice($counts, 0, $top, true) as $name => $c) {
            $out[] = ['name' => self::title($name), 'count' => $c, 'confidence' => min(95, 60 + $c * 8), 'source' => 'orders'];
        }
        return $out;
    }

    private static function termsFor(array $p): array
    {
        $terms = [];
        $full = self::keyNorm((string) ($p['name'] ?? ''));
        if ($full !== '') $terms[$full] = true;
        foreach (explode(' ', $full) as $w) {
            if (self::keepWord($w)) $terms[$w] = true;
        }
        $kw = (string) ($p['keywords'] ?? '');
        foreach (preg_split('/[^a-z0-9]+/', mb_strtolower($kw)) ?: [] as $w) {
            if (self::keepWord($w)) $terms[$w] = true;
        }
        return array_keys($terms);
    }

    private static function matchItem(string $itemName, array $prod, array $index): ?string
    {
        $k = self::keyNorm($itemName);
        if ($k === '') return null;
        if (isset($prod[$k])) return $k;
        $best = null; $bestN = 0; $tally = [];
        foreach (array_filter(explode(' ', $k), [self::class, 'keepWord']) as $w) {
            if (! isset($index[$w])) continue;
            foreach ($index[$w] as $key => $_) $tally[$key] = ($tally[$key] ?? 0) + 1;
        }
        foreach ($tally as $key => $n) {
            if ($n > $bestN) { $bestN = $n; $best = $key; }
        }
        return $best;
    }

    private static function keepWord(string $w): bool
    {
        if ($w === '' || in_array($w, self::STOP, true)) return false;
        if (preg_match('/^\d+$/', $w)) return mb_strlen($w) >= 4;
        return mb_strlen($w) >= 3 || (bool) preg_match('/\d/', $w);
    }

    private static function itemsOf(array $o): array
    {
        $names = [];
        $json = $o['items_json'] ?? $o['items'] ?? null;
        if (is_array($json)) {
            foreach ($json as $line) {
                if (is_array($line)) $names[] = (string) ($line['name'] ?? '');
                elseif (is_string($line)) $names[] = $line;
            }
        }
        $txt = trim((string) ($o['items_text'] ?? ''));
        if ($txt !== '') foreach (preg_split('/[,\n;]+/', $txt) as $p) $names[] = $p;
        return array_filter(array_map('trim', $names));
    }

    private static function tokens(string $msg): array
    {
        $msg = mb_strtolower($msg);
        $words = preg_split('/[^a-z0-9]+/', $msg) ?: [];
        return array_values(array_filter($words, [self::class, 'keepWord']));
    }

    private static function keyNorm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', (string) $s));
    }

    private static function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/\b\d+\s*(kg|g|gram|grams|ml|l|ltr|litre|pcs|pc|pack|x)\b/u', '', $s);
        $s = preg_replace('/[^a-z\s]/', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', (string) $s));
    }

    private static function title(string $s): string { return ucwords($s); }
}
