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
        'chai'=>'tea','cha'=>'tea','coke'=>'coca','cok'=>'coca','pani'=>'water','paani'=>'water',
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
        // negatives / fillers — never products; keep them out of fuzzy matching
        'dont','don','not','nope','nah','none','nothing','anything','something','everything',
        'else','thanks','thank','thx','just','looking','wanna','im','nahi','kuch','dun','dont',
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

        // Build the catalogue-wide token set so fuzzy matching is a TYPO FALLBACK
        // only: if a query word matches some product exactly, we must not also
        // fuzzy-pull look-alikes (e.g. "rice" should never drag in "race").
        $allTokens = [];
        foreach ($products as $p) {
            foreach ($this->productTokens($p) as $t) $allTokens[$t] = true;
        }
        $hasExact = [];
        foreach ($q as $w) {
            $sing = (mb_strlen($w) > 3 && str_ends_with($w, 's')) ? rtrim($w, 's') : null;
            $hasExact[$w] = isset($allTokens[$w]) || ($sing !== null && isset($allTokens[$sing]));
        }

        $scored = [];
        foreach ($products as $p) {
            $pt = $this->productTokens($p);
            if (!$pt) continue;
            $ptSet = array_flip($pt);
            $nameSet = array_flip($this->tokens($p['name'] ?? ''));   // NAME tokens weigh more than keyword/category
            $score = 0.0; $hits = 0;
            if ($this->normName($p['name'] ?? '') === $nq) $score += 1000;
            foreach ($q as $w) {                     // count hits over ORIGINAL query tokens (coverage)
                $cand = [$w];
                if (mb_strlen($w) > 3 && str_ends_with($w, 's')) $cand[] = rtrim($w, 's');
                $inName = false; $inAny = false;
                foreach ($cand as $cw) {
                    if (isset($nameSet[$cw])) { $inName = true; $inAny = true; break; }
                    if (isset($ptSet[$cw]))   { $inAny = true; }
                }
                // Fuzzy ONLY when this word matches nothing exactly in the whole catalogue.
                if (!$inAny && ! $hasExact[$w] && mb_strlen($w) >= 4) {
                    foreach ($pt as $s) {
                        if ($w[0] === $s[0] && abs(strlen($s) - strlen($w)) <= 1 && self::damerau($w, $s) <= 1) {
                            $inAny = true;
                            if (isset($nameSet[$s])) $inName = true;
                            break;
                        }
                    }
                }
                // A match in the product NAME (e.g. "Milk") dominates a match that only
                // appears in keywords/category (e.g. a yoghurt that lists "milk").
                if ($inName)      { $hits++; $score += 120; }
                elseif ($inAny)   { $hits++; $score += 40; }
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

    /**
     * Broad multi-term browse ("india gate rice chenab super brown rice ravi rice mb"):
     * when a long query is dominated by ONE product category/head term, return only that
     * category's products (best first), ignoring unrelated products that merely collide on
     * noise tokens (snacks/blades/gum). Returns null when it isn't a confident single-category
     * browse, so precise/short/multi-category queries fall through to normal handling.
     *
     * @return array{category:string,products:array}|null
     */
    public function categoryBrowse(string $query, array $products, int $limit = 20): ?array
    {
        $q = $this->tokens($query);
        if (count($q) < 4) return null;                 // not a broad browse — leave to normal path

        $scored = $this->search($query, $products);
        if (count($scored) < 4) return null;

        // score mass per category
        $catScore = []; $total = 0.0; $catLabel = [];
        foreach ($scored as $s) {
            $raw = trim((string) ($s['product']['category'] ?? ''));
            $c   = mb_strtolower($raw);
            $catScore[$c] = ($catScore[$c] ?? 0) + $s['score'];
            $total += $s['score'];
            if ($raw !== '' && ! isset($catLabel[$c])) $catLabel[$c] = $raw;
        }
        if ($total <= 0) return null;
        arsort($catScore);
        $domCat  = (string) array_key_first($catScore);
        $domConf = $catScore[$domCat] / $total;

        // head term = the query token present in the most product NAMES (e.g. "rice")
        $cov = [];
        foreach (array_unique($q) as $w) {
            $n = 0;
            foreach ($scored as $s) {
                if (in_array($w, $this->tokens((string) ($s['product']['name'] ?? '')), true)) $n++;
            }
            $cov[$w] = $n;
        }
        arsort($cov);
        $domHead = (string) array_key_first($cov);
        $headCov = $cov[$domHead] ?? 0;

        // Decide the focus set.
        $prods = []; $label = '';
        if ($domCat !== '' && $domConf >= 0.70) {
            // confident category: keep that category, plus any product whose NAME carries the head
            foreach ($scored as $s) {
                $c = mb_strtolower(trim((string) ($s['product']['category'] ?? '')));
                $inHead = $headCov >= 2 && in_array($domHead, $this->tokens((string) ($s['product']['name'] ?? '')), true);
                if ($c === $domCat || $inHead) $prods[] = $s['product'];
            }
            $label = $catLabel[$domCat] ?? ucfirst($domHead);
        } elseif ($headCov >= 4) {
            // no usable category data: focus by the dominant head term in the name
            foreach ($scored as $s) {
                if (in_array($domHead, $this->tokens((string) ($s['product']['name'] ?? '')), true)) {
                    $prods[] = $s['product'];
                }
            }
            $label = ucfirst($domHead);
        } else {
            return null;
        }

        if (count($prods) < 4) return null;             // not enough to call it a category browse
        return ['category' => $label, 'products' => array_slice($prods, 0, max(1, $limit))];
    }

    /** Normalise a size token: "2 kg"->"2kg", "500 ml"->"500ml", "1 Litre"->"1l". Null if none. */
    public static function normSize(string $s): ?string
    {
        if (!preg_match('/(\d+(?:\.\d+)?)\s*(kgs|kg|gms|gms|gm|grams|gram|g|mg|ml|cl|ltrs|ltr|lt|litres|litre|liters|liter|l)\b/i', mb_strtolower($s), $m)) {
            return null;
        }
        $num = $m[1];
        if (strpos($num, '.') !== false) {
            $num = rtrim(rtrim($num, '0'), '.'); // 2.0 -> 2, 1.50 -> 1.5
        }                                        // but 500 stays 500, 250 stays 250
        $unit = $m[2];
        $unit = match (true) {
            in_array($unit, ['kgs','kg'], true) => 'kg',
            in_array($unit, ['gms','gm','grams','gram','g'], true) => 'g',
            $unit === 'mg' => 'mg',
            $unit === 'ml' => 'ml',
            $unit === 'cl' => 'cl',
            default => 'l', // ltr/litre/liter/l
        };
        return $num . $unit;
    }

    /** Extract a product's pack size from its name (first weight/volume token). */
    public static function skuSize(string $name): ?string
    {
        return self::normSize($name);
    }

    /** Numeric magnitude of a pack size in grams/ml for sorting (1kg->1000, 500g->500). Null if none. */
    public static function sizeMagnitude(string $name): ?float
    {
        $s = self::skuSize($name);
        if ($s === null || ! preg_match('/^(\d+(?:\.\d+)?)(kg|g|mg|l|ml|cl)$/', $s, $m)) return null;
        $n = (float) $m[1];
        return match ($m[2]) {
            'kg' => $n * 1000, 'g' => $n, 'mg' => $n / 1000,
            'l'  => $n * 1000, 'cl' => $n * 10, 'ml' => $n,
            default => $n,
        };
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
