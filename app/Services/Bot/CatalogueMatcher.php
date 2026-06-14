<?php
namespace App\Services\Bot;

/**
 * CatalogueMatcher — resolves free-text to catalogue products.
 * Ported from the production n8n brain: synonyms, stopwords, units, Damerau
 * (transposition-aware) fuzzy matching across name/keywords/category, and
 * clarify-on-price-spread. Pure PHP over an array of product rows.
 */
class CatalogueMatcher
{
    public const SYN = [
        'sakar'=>'sugar','sakkar'=>'sugar','sakr'=>'sugar','khand'=>'sugar','cheeni'=>'sugar','chini'=>'sugar',
        'tel'=>'oil','telu'=>'oil','teel'=>'oil',
        'doodh'=>'milk','dudh'=>'milk','dood'=>'milk','dodh'=>'milk',
        'lot'=>'flour','atta'=>'flour','aata'=>'flour','maida'=>'flour',
        'chokha'=>'rice','chawal'=>'rice','chaval'=>'rice',
        'ghi'=>'ghee','ghyu'=>'ghee','namak'=>'salt','mithu'=>'salt',
        'chai'=>'tea','cha'=>'tea','pani'=>'water','paani'=>'water',
        'anda'=>'eggs','ande'=>'eggs','sabu'=>'soap','sabun'=>'soap',
        'mirch'=>'chilli','mirchi'=>'chilli','haldi'=>'turmeric','jeera'=>'cumin','jiru'=>'cumin',
        'gud'=>'jaggery','toor'=>'dal','tuvar'=>'dal','daal'=>'dal','dal'=>'dal',
    ];

    public const STOP = [
        'i','want','need','the','a','an','some','please','pls','of','me','do','you','have',
        'is','how','much','price','give','show','get','buy','order','for','to','my','we','can',
        'it','that','this','one','yes','no','ok','okay','send','deliver','today','now','am','are',
        'what','which','available','stock','check','with','and','plus','add','got','any','there',
        'kindly','u','more','also','list',
    ];

    public const UNITS = [
        'kg','kgs','g','gm','gms','gram','grams','mg','ml','cl','l','lt','ltr','ltrs','litre',
        'litres','liter','liters','pc','pcs','pk','pkt','pkts','pack','packs','packet','packets',
        'piece','pieces','box','boxes','tin','tins','btl','bottle','bottles','jar','jars','bar',
        'bars','sachet','sachets','roll','rolls','dozen','loaf','loaves','nos','no',
    ];

    public function tokens(string $s): array
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s);
        $out = [];
        foreach (preg_split('/\s+/', $s, -1, PREG_SPLIT_NO_EMPTY) as $t) {
            if (in_array($t, self::UNITS, true)) continue;
            if (preg_match('/^\d+$/', $t)) continue;
            if (preg_match('/^\d+(kg|g|gm|gms|ml|l|ltr)$/', $t)) continue;
            if (in_array($t, self::STOP, true)) continue;
            if (mb_strlen($t) < 2) continue;
            $out[] = self::SYN[$t] ?? $t;
        }
        return $out;
    }

    private array $ptCache = [];

    private function productTokens(array $p): array
    {
        $key = $p['id'] ?? null;
        if ($key !== null && isset($this->ptCache[$key])) return $this->ptCache[$key];
        $blob = ($p['name'] ?? '') . ' ' . ($p['keywords'] ?? '') . ' ' . ($p['category'] ?? '');
        $s = preg_replace('/[^a-z0-9 ]+/', ' ', mb_strtolower($blob));
        $out = [];
        foreach (preg_split('/\s+/', $s, -1, PREG_SPLIT_NO_EMPTY) as $t) {
            if (in_array($t, self::UNITS, true)) continue;
            if (preg_match('/^\d+(kg|g|gm|gms|ml|l|ltr)?$/', $t)) continue;
            if (mb_strlen($t) < 2) continue;
            $out[] = self::SYN[$t] ?? $t;
        }
        if ($key !== null) $this->ptCache[$key] = $out;
        return $out;
    }

    private function normName(string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower(preg_replace('/[^a-z0-9 ]+/i', ' ', $name))));
    }

    /** Damerau optimal-string-alignment distance (catches single transpositions e.g. rcie->rice). */
    public static function damerau(string $a, string $b): int
    {
        $la = strlen($a); $lb = strlen($b);
        if ($a === $b) return 0;
        if (abs($la - $lb) > 2) return 99;
        $d = [];
        for ($i = 0; $i <= $la; $i++) $d[$i][0] = $i;
        for ($j = 0; $j <= $lb; $j++) $d[0][$j] = $j;
        for ($i = 1; $i <= $la; $i++) {
            for ($j = 1; $j <= $lb; $j++) {
                $cost = ($a[$i - 1] === $b[$j - 1]) ? 0 : 1;
                $d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + $cost);
                if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                    $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);
                }
            }
        }
        return $d[$la][$lb];
    }

    /** @return array list of ['product'=>row,'score'=>float,'hits'=>int], best first. */
    public function search(string $query, array $products): array
    {
        $q = $this->tokens($query);
        if (!$q) return [];
        $qWords = [];
        foreach ($q as $w) {
            $qWords[$w] = true;
            if (mb_strlen($w) > 3 && str_ends_with($w, 's')) $qWords[rtrim($w, 's')] = true;
        }
        $qWords = array_keys($qWords);
        $nq = implode(' ', $q);

        $scored = [];
        foreach ($products as $p) {
            $pt = $this->productTokens($p);
            if (!$pt) continue;
            $ptSet = array_flip($pt);
            $score = 0.0; $hits = 0;
            if ($this->normName($p['name'] ?? '') === $nq) $score += 1000;
            foreach ($q as $w) {                     // count hits over ORIGINAL query tokens (coverage)
                $cand = [$w];
                if (mb_strlen($w) > 3 && str_ends_with($w, 's')) $cand[] = rtrim($w, 's');
                $matched = false;
                foreach ($cand as $cw) {
                    if (isset($ptSet[$cw])) { $matched = true; break; }
                }
                if (!$matched && mb_strlen($w) >= 4) {
                    foreach ($pt as $s) {
                        if ($w[0] === $s[0] && abs(strlen($s) - strlen($w)) <= 1 && self::damerau($w, $s) <= 1) { $matched = true; break; }
                    }
                }
                if ($matched) { $hits++; $score += 100; }
            }
            if ($hits === 0 && $score < 1000) continue;
            $score += ($hits / max(1, count($q))) * 50;
            if (($p['stock'] ?? 1) > 0) $score += 5;
            $score -= max(0, count($pt) - 1);
            $scored[] = ['product' => $p, 'score' => $score, 'hits' => $hits];
        }
        usort($scored, function ($a, $b) {
            if ($b['score'] != $a['score']) return $b['score'] <=> $a['score'];
            return strlen($a['product']['name']) <=> strlen($b['product']['name']);
        });
        return $scored;
    }

    /** Clarify guard: single generic word -> >=2 SKUs with >=3x price spread -> ask. */
    public function clarifyCheck(string $query, array $products): ?array
    {
        $q = $this->tokens($query);
        if (count($q) !== 1) return null;
        $t = $q[0];
        $nq = implode(' ', $q);
        // an exact product-name match is unambiguous; but a keyword shared by several
        // SKUs does NOT disambiguate, so it must not suppress the clarify prompt.
        foreach ($products as $p) {
            if ($this->normName($p['name'] ?? '') === $nq) return null;
        }
        $cands = [];
        foreach ($products as $p) {
            if (in_array($t, $this->productTokens($p), true) && (float) ($p['price'] ?? 0) > 0) $cands[] = $p;
        }
        if (count($cands) < 2) return null;
        $prices = array_map(fn ($p) => (float) $p['price'], $cands);
        if (min($prices) <= 0 || max($prices) / min($prices) < 3) return null;
        usort($cands, fn ($a, $b) => (float) $a['price'] <=> (float) $b['price']);
        return array_slice($cands, 0, 3);
    }
}
