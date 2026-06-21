<?php

namespace App\Services\Bot\Discovery;

/**
 * Business Discovery — product extraction. Pure logic.
 *
 * Orders are ground truth, so product names from order items carry high confidence; repeated
 * product-like nouns in customer messages are added as lower-confidence candidates.
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

    public static function mine(MessageCorpus $corpus, array $orders, int $top = 15): array
    {
        $counts = []; $source = [];

        // 1) order items — strong signal
        foreach ($orders as $o) {
            foreach (self::itemsOf($o) as $name) {
                $k = self::norm($name);
                if ($k === '') continue;
                $counts[$k] = ($counts[$k] ?? 0) + 2;        // weight orders heavier
                $source[$k] = 'orders';
            }
        }

        // 2) repeated nouns in customer messages — weak signal
        $tokenCounts = [];
        foreach ($corpus->customer as $msg) {
            foreach (self::tokens($msg) as $w) {
                $tokenCounts[$w] = ($tokenCounts[$w] ?? 0) + 1;
            }
        }
        foreach ($tokenCounts as $w => $c) {
            if ($c < 3) continue;                            // must recur to count
            if (! isset($counts[$w])) { $counts[$w] = $c; $source[$w] = 'chat'; }
            else $counts[$w] += $c;
        }

        arsort($counts);
        $out = [];
        foreach (array_slice($counts, 0, $top, true) as $name => $c) {
            $src = $source[$name] ?? 'chat';
            $conf = $src === 'orders' ? min(95, 60 + $c * 3) : min(70, 30 + $c * 4);
            $out[] = ['name' => self::title($name), 'count' => $c, 'confidence' => $conf, 'source' => $src];
        }
        return $out;
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
        $words = preg_split('/[^a-z]+/', $msg) ?: [];
        return array_values(array_filter($words, fn ($w) => mb_strlen($w) >= 3 && ! in_array($w, self::STOP, true)));
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
