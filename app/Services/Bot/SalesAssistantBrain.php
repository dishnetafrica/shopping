<?php

namespace App\Services\Bot;

use App\Models\Conversation;
use App\Models\OrderItem;
use App\Models\ProductDefault;
use App\Models\Tenant;

/**
 * SalesAssistantBrain — the "human shopkeeper" conversation layer.
 *
 * It sits in front of the deterministic ShoppingEngine and handles the three
 * conversational moves a real attendant makes that a search engine does not:
 *
 *   OPINION   "which one is good?" / "what do you recommend?"  -> recommend ONE,
 *             with the truthful reason (owner's pick -> best-seller -> best value).
 *   DOUBT     "are you sure?"                                  -> reaffirm the last
 *             recommendation on its factual basis and offer the honest alternative.
 *   COMPARE   "which is better, X or Y?"                       -> a FACTUAL comparison
 *             (price), never an invented quality verdict, then ask what matters more.
 *
 * It never invents facts (Rule: never pretend to know). Recommendations are
 * anchored in real data:
 *   1. the owner's product_defaults pick for the term, else
 *   2. the genuine best-seller from order history, else
 *   3. the best-value (cheapest in-stock) match.
 *
 * Bargaining ("too expensive") is NOT handled here — that re-ranks the active
 * list and is owned by the existing FollowUp 'cheaper' path.
 *
 * The recommendation is presented through the SAME numbered-option pipeline the
 * rest of the bot uses, so a reply of "1" adds it to the cart via the existing
 * (well-tested) selection logic — no new add path is introduced.
 *
 * respond() returns the reply string (and persists conversation state) when it
 * handles the message, or null to fall through to normal processing.
 */
class SalesAssistantBrain
{
    public const OPINION = 'opinion';
    public const DOUBT   = 'doubt';
    public const COMPARE = 'compare';

    public function __construct(private ClarificationFlow $clarify)
    {
    }

    public function respond(Tenant $tenant, Conversation $convo, string $text, array $catalogue, string $currency): ?string
    {
        $st    = is_array($convo->state) ? $convo->state : [];
        $byId  = [];
        foreach ($catalogue as $p) {
            $byId[(int) ($p['id'] ?? 0)] = $p;
        }

        // Unit-price / best-value question ("which is cheapest per kg?") — a reasoning task over
        // the named brand (or the active list), not a catalogue search. Runs first so the words
        // "per kg" are never treated as products. Falls through if it can't compute.
        if (self::detectValue($text)) {
            $r = $this->handleValue($tenant, $convo, $text, $catalogue, $byId, $st, $currency);
            if ($r !== null) return $r;
        }

        $intent = self::detect($text);
        if ($intent === null) {
            return null;
        }

        if ($intent === self::DOUBT) {
            return $this->handleDoubt($convo, $st, $currency);
        }

        if ($intent === self::COMPARE) {
            return $this->handleCompare($tenant, $convo, $text, $catalogue, $byId, $st, $currency);
        }

        // OPINION
        return $this->handleOpinion($tenant, $convo, $text, $catalogue, $byId, $st, $currency);
    }

    // ---------------------------------------------------------------- intents

    private function handleOpinion(Tenant $tenant, Conversation $convo, string $text, array $catalogue, array $byId, array $st, string $currency): ?string
    {
        [$term, $candidates] = $this->subject($text, $catalogue, $st);

        // Structured discovery context ("family of 5", "not expensive", "daily use") — used to
        // bias the pick (budget/size) and to shape the wording. Built from the discovery part
        // of the message; harmless (all-null) when the customer gave no extra context.
        $ctx = DiscoveryContextBuilder::build(self::discoverySegment($text), $catalogue);

        // an explicit size ("rice 5kg") pins the pack; otherwise a big household nudges larger
        if (! empty($ctx['size']) && $candidates) {
            $want = $ctx['size'];
            $sized = array_values(array_filter($candidates, function ($p) use ($want) {
                $s = self::parseSize((string) ($p['name'] ?? ''));
                return $s && $s['unit'] === $want['unit'] && abs($s['value'] - $want['value']) < 0.01;
            }));
            if ($sized) $candidates = $sized;
        } elseif (($ctx['family_size'] ?? 0) >= 4 && $candidates) {
            $candidates = $this->preferLargerPacks($candidates);
        }

        // owner's default for the term (independent of the search candidates)
        $defaultProduct = null;
        if ($term !== '') {
            $canon = ProductDefault::canonicalTerm($term);
            if ($canon !== '') {
                $pd = ProductDefault::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('term', $canon)
                    ->where('active', true)
                    ->value('product_id');
                if ($pd && isset($byId[(int) $pd])) {
                    $defaultProduct = $byId[(int) $pd];
                }
            }
        }

        // Nothing to talk about: ask rather than guess.
        if ($defaultProduct === null && ! $candidates) {
            if ($term !== '') {
                return "I don't stock *{$term}* at the moment \u{1F642} Tell me another product and I'll recommend one.";
            }
            return "Sure \u{1F642} Which product would you like me to recommend?";
        }

        $sellerQty = $this->bestSellerQty($tenant, $this->idsOf($candidates, $defaultProduct));
        $pick      = self::pickRecommendation($term, $candidates, $defaultProduct, $sellerQty, $ctx);
        $product   = $pick['product'];
        if (! $product) {
            return "Sure \u{1F642} Which product would you like me to recommend?";
        }

        // cheaper alternative (a genuinely different, cheaper in-stock candidate)
        $alt = $this->cheaperAlternative($product, $candidates);

        $money = fn ($a) => $currency . ' ' . number_format((float) $a);
        $built = $this->clarify->buildOptions(
            [['label' => $term !== '' ? $term : 'recommendation', 'qty' => 1, 'products' => [$product]]],
            $money
        );

        $label = $term !== '' ? "*{$term}*" : 'that';
        $price = $money($product['price']);

        // Social proof leads ONLY when real order history backs it.
        $pid = (int) ($product['id'] ?? 0);
        $topId = null; $topQty = 0;
        foreach ($sellerQty as $id => $q) { if ($q > $topQty) { $topQty = $q; $topId = (int) $id; } }
        $isTopSeller = $pid > 0 && $pid === $topId && $topQty > 0;

        // recommendation clause (without a leading "For ..." — the context phrase supplies that)
        switch ($pick['tag']) {
            case 'popular':
                $clause = "most customers choose *{$product['name']}* ({$price}) — it's our most popular.";
                break;
            case 'premium':
                $clause = "*{$product['name']}* ({$price}) is our top-of-the-range choice.";
                break;
            case 'pick':
                $clause = "I'd recommend *{$product['name']}* ({$price})"
                        . ($isTopSeller ? " — and it's what most customers buy." : ".");
                break;
            case 'value':
                $clause = "*{$product['name']}* ({$price}) is the best value for money.";
                break;
            default:
                $clause = "I'd go with *{$product['name']}* ({$price}).";
        }

        $phrase = DiscoveryContextBuilder::phrase($ctx);
        $lead   = $phrase !== '' ? ($phrase . $clause) : ("For {$label}, " . $clause);

        $msg  = $lead . "\n";
        $msg .= "Reply *1* to add it to your basket";
        $msg .= $alt ? ", or say *cheaper* to see other options. \u{1F642}" : ". \u{1F642}";

        $this->persist($convo, $st, $built['flat'], $term, [
            'product_id' => $product['id'] ?? null,
            'name'       => $product['name'] ?? '',
            'price'      => (float) ($product['price'] ?? 0),
            'basis'      => $pick['basis'],
            'term'       => $term,
            'alt'        => $alt['name'] ?? null,
        ]);

        return $msg;
    }

    /** Keep candidates within ~60% of the largest pack size (soft nudge for big households). */
    private function preferLargerPacks(array $candidates): array
    {
        $max = 0.0;
        foreach ($candidates as $p) { $max = max($max, self::sizeValue((string) ($p['name'] ?? ''))); }
        if ($max <= 0) return $candidates;   // no parseable sizes — leave as-is
        $kept = [];
        foreach ($candidates as $p) {
            $v = self::sizeValue((string) ($p['name'] ?? ''));
            if ($v <= 0 || $v >= $max * 0.6) $kept[] = $p;   // keep unknowns + the larger packs
        }
        return $kept ?: $candidates;
    }

    /**
     * Answer a unit-price / best-value question by RANKING, not searching: resolve the product
     * set the customer means (a named brand like "India Gate", else the active list), compute
     * price per kg / litre, and present the cheapest. Returns null if it can't resolve a set or
     * compute prices, so the caller can fall back to normal handling.
     */
    private function handleValue(Tenant $tenant, Conversation $convo, string $text, array $catalogue, array $byId, array $st, string $currency): ?string
    {
        $set = $this->valueSet($text, $catalogue, $st);
        if (count($set) < 2) return null;

        $rank = self::unitPriceRanking($set, self::valueUnit($text));
        if (! $rank) return null;                 // no parseable sizes — can't compute per-unit

        $unit  = $rank[0]['unit'];
        $money = fn ($a) => $currency . ' ' . number_format((float) $a);
        $top   = array_slice($rank, 0, 3);

        $prods = []; $lines = []; $n = 0;
        foreach ($top as $r) {
            $n++;
            $prods[] = $r['product'];
            $lines[] = "  {$n}. {$r['product']['name']} — " . $money(round($r['unit_price'])) . "/{$unit}";
        }
        $built = $this->clarify->buildOptions(
            [['label' => 'best value', 'qty' => 1, 'products' => $prods]],
            $money
        );

        $msg  = "Best value per {$unit} \u{1F642}\n" . implode("\n", $lines) . "\n";
        $msg .= "Reply *1* to add it to your basket.";

        $this->persist($convo, $st, $built['flat'], '', null);
        return $msg;
    }

    /** The product set a value question refers to: a named brand/term, else the active context. */
    private function valueSet(string $text, array $catalogue, array $st): array
    {
        $m = new CatalogueMatcher();

        // strip the value/question words, leaving the brand or product term
        $cleaned = preg_replace(
            '/\b(which|what|one|ones|is|are|the|a|an|cheaper|cheapest|lowest|highest|best|value|deal|price|priced|per|kg|kilo|kilogram|litre|liter|lt|l|in|for|money|of|good|most|me|tell|show|do|you|have|got|by)\b/i',
            ' ',
            mb_strtolower($text)
        );
        $brand = $m->tokens($cleaned);

        if ($brand) {
            // products covering the MOST brand tokens (>=2 for a multi-word brand) — so "india
            // gate" keeps India Gate SKUs and drops "Cow & Gate" / unrelated single-token hits.
            $maxHit = 0; $best = [];
            foreach ($catalogue as $p) {
                $hit = count(array_intersect($brand, $m->tokens((string) ($p['name'] ?? ''))));
                if ($hit > $maxHit) { $maxHit = $hit; $best = [$p]; }
                elseif ($hit === $maxHit && $hit > 0) { $best[] = $p; }
            }
            $need = count($brand) >= 2 ? 2 : 1;
            if ($maxHit >= $need && count($best) >= 2) return $best;

            // single product noun ("cheapest rice per kg") -> coherent product candidates
            if (count($brand) === 1) {
                $hits = $m->search($brand[0], $catalogue);
                if ($hits) return self::coherentCandidates($brand[0], $hits);
            }
        }

        // fall back to the active list / last search
        $opts = $st['options'] ?? null;
        if (is_array($opts) && $opts) {
            $byId = [];
            foreach ($catalogue as $p) $byId[(int) ($p['id'] ?? 0)] = $p;
            $set = [];
            foreach ($opts as $o) {
                $id = (int) ($o['product_id'] ?? 0);
                if ($id && isset($byId[$id])) $set[] = $byId[$id];
            }
            if ($set) return $set;
        }
        $last = (string) ($st['last_query'] ?? '');
        if ($last !== '') {
            $hits = $m->search($last, $catalogue);
            if ($hits) return array_map(fn ($h) => $h['product'], array_slice($hits, 0, 12));
        }
        return [];
    }

    private function handleCompare(Tenant $tenant, Conversation $convo, string $text, array $catalogue, array $byId, array $st, string $currency): ?string
    {
        $sides = self::parseVersus($text);
        $products = [];

        if (count($sides) >= 2) {
            $m = new CatalogueMatcher();
            foreach ($sides as $sideTerm) {
                $hits = $m->search($sideTerm, $catalogue);
                if ($hits) {
                    $products[] = $hits[0]['product'];
                }
            }
        }

        // Fall back to the active context (e.g. options on screen, or last query).
        if (count($products) < 2) {
            [, $candidates] = $this->subject($text, $catalogue, $st);
            $products = array_slice($candidates, 0, 4);
        }

        // de-duplicate by id
        $seen = []; $uniq = [];
        foreach ($products as $p) {
            $id = (int) ($p['id'] ?? 0);
            if ($id && ! isset($seen[$id])) { $seen[$id] = true; $uniq[] = $p; }
        }
        $products = $uniq;

        if (count($products) < 2) {
            return null; // not enough to compare — let normal handling deal with it
        }
        $products = array_slice($products, 0, 4);

        $sellerQty = $this->bestSellerQty($tenant, array_map(fn ($p) => (int) $p['id'], $products));
        $popularId = null; $popularQty = 0;
        foreach ($products as $p) {
            $id = (int) $p['id'];
            if (($sellerQty[$id] ?? 0) > $popularQty) { $popularQty = $sellerQty[$id]; $popularId = $id; }
        }

        $money = fn ($a) => $currency . ' ' . number_format((float) $a);
        $built = $this->clarify->buildOptions(
            [['label' => 'options', 'qty' => 1, 'products' => $products]],
            $money
        );

        $lines = [];
        $n = 0;
        $star = " \u{2B50} most popular";
        foreach ($products as $p) {
            $n++;
            $tag = ((int) $p['id'] === $popularId && $popularQty > 0) ? $star : '';
            $lines[] = "  {$n}. {$p['name']} — {$money($p['price'])}{$tag}";
        }

        $msg  = "Both are good \u{1F642} Here's the difference:\n";
        $msg .= implode("\n", $lines) . "\n";
        $msg .= $popularId !== null
            ? "Most customers pick the starred one. "
            : '';
        $msg .= "If price matters most, go with the cheaper one. Reply *1* or *2* to add.";

        $this->persist($convo, $st, $built['flat'], '', null);

        return $msg;
    }

    private function handleDoubt(Conversation $convo, array $st, string $currency): ?string
    {
        $rec = $st['last_recommended'] ?? null;
        if (! is_array($rec) || empty($rec['name'])) {
            return null; // nothing was recommended yet — don't fabricate reassurance
        }

        $money = fn ($a) => $currency . ' ' . number_format((float) $a);

        // Re-arm the same single-item option so "1" still adds it.
        $built = $this->clarify->buildOptions(
            [['label' => $rec['term'] ?: 'recommendation', 'qty' => 1, 'products' => [[
                'id'    => $rec['product_id'] ?? null,
                'name'  => $rec['name'],
                'price' => (float) ($rec['price'] ?? 0),
            ]]]],
            $money
        );

        $msg  = "Yes \u{1F642} *{$rec['name']}* is {$rec['basis']}.\n";
        if (! empty($rec['alt'])) {
            $msg .= "If price is your main concern, *{$rec['alt']}* is a cheaper option.\n";
        }
        $msg .= "Reply *1* to add *{$rec['name']}*, or tell me another product.";

        $st['options']       = $built['flat'];
        $st['last_activity'] = time();
        $convo->state        = $st;
        $convo->save();

        return $msg;
    }

    // ---------------------------------------------------------------- pure helpers (testable)

    /** Classify the conversational move, or null when it isn't one. Pure. */
    public static function detect(string $text): ?string
    {
        $lc   = mb_strtolower(trim($text));
        if ($lc === '') return null;
        $norm = trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9\s]+/', ' ', $lc)));
        if ($norm === '') return null;
        $hasQ = str_contains($lc, '?');

        // A bare number is always a selection, never a sales question.
        if (preg_match('/^[\d\s,]+$/', $norm)) return null;

        // COMPARE first ("which is better" contains "better").
        if (preg_match('/\bwhich (one )?is better\b/', $norm)
            || preg_match('/\bwhat ?s? (is )?better\b/', $norm)
            || preg_match('/\bwhich (is )?(the )?best (one|choice)\b/', $norm)
            || str_contains($norm, 'compare')
            || preg_match('/\bdifference between\b/', $norm)
            || preg_match('/\b\w+\s+(vs|versus)\s+\w+/', $norm)) {
            return self::COMPARE;
        }

        // DOUBT — questioning the recommendation, not a bare affirmation.
        if (preg_match('/\b(are|r)\s+(you|u)\s+sure\b/', $norm)
            || preg_match('/\b(you|u)\s+sure\b/', $norm)
            || preg_match('/\bsure about\b/', $norm)
            || ($hasQ && preg_match('/^(sure|really|seriously|honestly)\b/', $norm))
            || preg_match('/\b(is it|is that) (really )?(good|worth|fine|ok)\b/', $norm)) {
            return self::DOUBT;
        }

        // OPINION — asking for a recommendation.
        if (str_contains($norm, 'recommend')
            || str_contains($norm, 'suggest')
            || preg_match('/\b(which|whats?)\b.*\b(good|best|nice)\b/', $norm)
            || preg_match('/\bwhich (should|do) (i|you)\b/', $norm)
            || preg_match('/\bwhat should i (buy|get|take|order|choose)\b/', $norm)
            || preg_match('/\bgood one\b/', $norm)
            || preg_match('/\b(most )?popular( one)?\b/', $norm)
            || preg_match('/\bwhat ?s? (popular|selling|moving|hot|trending)\b/', $norm)
            || preg_match('/\b(moves?|sells?|selling)\b.*\b(fast|fastest|more|most|well|best)\b/', $norm)
            || preg_match('/\bfast(est)?[\s-]?(moving|selling)\b/', $norm)
            || preg_match('/\bbest[\s-]?(seller|selling)\b/', $norm)
            || preg_match('/\bmost people\b.*\b(take|takes|buy|get|order|choose|prefer|pick)\b/', $norm)
            || preg_match('/\bwhat do (people|customers|others|most) .*?(buy|get|order|choose|take|prefer)\b/', $norm)
            || preg_match('/\bany good\b/', $norm)
            || preg_match('/\byour (pick|favou?rite|recommendation)\b/', $norm)) {
            return self::OPINION;
        }

        return null;
    }

    /**
     * Choose the recommendation, truthfully. Pure.
     * Priority: owner's default -> best-seller among candidates -> best value -> first match.
     * @return array{product: ?array, basis: string}
     */
    public static function pickRecommendation(string $term, array $candidates, ?array $defaultProduct, array $sellerQtyById, array $context = []): array
    {
        $budget = $context['budget'] ?? null;

        // An explicit budget is the customer's own constraint — honour it over the generic
        // default. "Not expensive" -> the most affordable; "premium" -> the top of the range.
        if ($budget === 'low') {
            $v = self::cheapestInStock($candidates);
            if ($v) return ['product' => $v, 'tag' => 'value', 'basis' => 'the most affordable option'];
        }
        if ($budget === 'high') {
            $p = self::dearestInStock($candidates);
            if ($p) return ['product' => $p, 'tag' => 'premium', 'basis' => 'our top-of-the-range choice'];
        }

        if ($defaultProduct !== null) {
            return ['product' => $defaultProduct, 'tag' => 'pick', 'basis' => 'the one we recommend here'];
        }

        // best-seller among the candidates
        $best = null; $bestQty = 0;
        foreach ($candidates as $p) {
            $id  = (int) ($p['id'] ?? 0);
            $qty = (int) ($sellerQtyById[$id] ?? 0);
            if ($qty > $bestQty) { $bestQty = $qty; $best = $p; }
        }
        if ($best !== null && $bestQty > 0) {
            return ['product' => $best, 'tag' => 'popular', 'basis' => 'our most popular one, most customers choose it'];
        }

        // best value: cheapest in-stock candidate
        $value = self::cheapestInStock($candidates);
        if ($value !== null) {
            return ['product' => $value, 'tag' => 'value', 'basis' => 'great value for the price'];
        }

        if ($candidates) {
            return ['product' => $candidates[0], 'tag' => 'first', 'basis' => 'a good choice'];
        }
        return ['product' => null, 'tag' => 'none', 'basis' => ''];
    }

    private static function cheapestInStock(array $candidates): ?array
    {
        $v = null;
        foreach ($candidates as $p) {
            if (($p['stock'] ?? 1) <= 0) continue;
            if ($v === null || (float) $p['price'] < (float) $v['price']) $v = $p;
        }
        return $v;
    }

    private static function dearestInStock(array $candidates): ?array
    {
        $v = null;
        foreach ($candidates as $p) {
            if (($p['stock'] ?? 1) <= 0) continue;
            if ($v === null || (float) $p['price'] > (float) $v['price']) $v = $p;
        }
        return $v;
    }

    /** Coarse pack size in a comparable unit (g / ml) for a soft "bigger pack" nudge. 0 = unknown. */
    public static function sizeValue(string $name): float
    {
        if (! preg_match('/(\d+(?:\.\d+)?)\s*(kg|gm|g|ml|cl|ltr|litre|liter|l)\b/i', mb_strtolower($name), $m)) {
            return 0.0;
        }
        $n = (float) $m[1];
        return match ($m[2]) {
            'kg'                                  => $n * 1000,
            'g', 'gm'                             => $n,
            'l', 'ltr', 'litre', 'liter'          => $n * 1000,
            'cl'                                  => $n * 10,
            'ml'                                  => $n,
            default                               => $n,
        };
    }

    /** Parse a pack size into a comparable unit. Mass -> kg, volume -> l. @return array{value:float,unit:string}|null */
    public static function parseSize(string $name): ?array
    {
        if (! preg_match('/(\d+(?:\.\d+)?)\s*(kg|gm|g|ml|cl|ltrs?|litres?|liters?|lt|l)\b/i', mb_strtolower($name), $m)) {
            return null;
        }
        $n = (float) $m[1];
        switch ($m[2]) {
            case 'kg':                                              return ['value' => $n,        'unit' => 'kg'];
            case 'g': case 'gm':                                    return ['value' => $n / 1000, 'unit' => 'kg'];
            case 'l': case 'lt': case 'ltr': case 'ltrs':
            case 'litre': case 'litres': case 'liter': case 'liters': return ['value' => $n,      'unit' => 'l'];
            case 'cl':                                              return ['value' => $n / 100,  'unit' => 'l'];
            case 'ml':                                              return ['value' => $n / 1000, 'unit' => 'l'];
        }
        return null;
    }

    /**
     * Rank products by UNIT price (per kg or per litre), cheapest first. Products without a
     * parseable size in the chosen unit are skipped (you can't compute a per-kg price without
     * a weight). @return array<int,array{product:array,unit:string,unit_price:float}>
     */
    public static function unitPriceRanking(array $products, ?string $unit = null): array
    {
        if ($unit === null) {
            $c = ['kg' => 0, 'l' => 0];
            foreach ($products as $p) {
                $s = self::parseSize((string) ($p['name'] ?? ''));
                if ($s) $c[$s['unit']]++;
            }
            $unit = $c['l'] > $c['kg'] ? 'l' : 'kg';
        }
        $rows = [];
        foreach ($products as $p) {
            if (($p['stock'] ?? 1) <= 0) continue;
            $s = self::parseSize((string) ($p['name'] ?? ''));
            if (! $s || $s['unit'] !== $unit || $s['value'] <= 0) continue;
            $rows[] = ['product' => $p, 'unit' => $unit, 'unit_price' => (float) $p['price'] / $s['value']];
        }
        usort($rows, fn ($a, $b) => $a['unit_price'] <=> $b['unit_price']);
        return $rows;
    }

    /** Is this a unit-price / best-value comparison question? Pure. */
    public static function detectValue(string $text): bool
    {
        $s = mb_strtolower($text);
        return (bool) (preg_match('/\bper\s*(kg|kilo|kilogram|litre|liter|lt|l)\b/', $s)
            || preg_match('/\bbest (value|deal)\b/', $s)
            || preg_match('/\bvalue for money\b/', $s)
            || preg_match('/\b(cheapest|lowest|best price) per\b/', $s)
            || preg_match('/\bcheaper per\b/', $s));
    }

    /** The unit a value question asks about, or null to infer from the product set. */
    public static function valueUnit(string $text): ?string
    {
        $s = mb_strtolower($text);
        if (preg_match('/\bper\s*(litre|liter|lt|l)\b/', $s)) return 'l';
        if (preg_match('/\bper\s*(kg|kilo|kilogram)\b/', $s)) return 'kg';
        return null;
    }

    /** Split "X vs Y", "compare X and Y", "difference between X and Y", "X or Y". Pure. */
    public static function parseVersus(string $text): array
    {
        $t = mb_strtolower(trim($text));
        $t = preg_replace('/[?.!,]+/', ' ', $t);
        $t = trim(preg_replace('/\s+/', ' ', $t));

        // strip a leading question frame
        $t = preg_replace('/^(which is better|whats better|what s better|what is better|compare|difference between|which is good)\s+/', '', $t);

        $parts = [];
        if (preg_match('/\b(vs|versus)\b/', $t)) {
            $parts = preg_split('/\b(?:vs|versus)\b/', $t);
        } elseif (preg_match('/\band\b/', $t)) {
            $parts = preg_split('/\band\b/', $t);
        } elseif (preg_match('/\bor\b/', $t)) {
            $parts = preg_split('/\bor\b/', $t);
        }

        $out = [];
        foreach ((array) $parts as $p) {
            $p = trim(preg_replace('/^(the|a|an)\s+/', '', trim($p)));
            if ($p !== '') $out[] = $p;
        }
        return $out;
    }

    /**
     * Keep only candidates that belong to the same product category as the term's strongest
     * matches — so a request for "rice" never recommends "D.rice Samosa" (a snack whose name
     * merely contains the token "rice"). Pure.
     *
     * @param array $hits matcher->search() output: [['product'=>...,'score'=>...], ...]
     * @return array list of product rows, coherent with the term
     */
    public static function coherentCandidates(string $term, array $hits): array
    {
        if (! $hits) return [];
        $m = new CatalogueMatcher();
        $tt = $m->tokens($term);
        if (! $tt) return array_map(fn ($h) => $h['product'], $hits);
        $head = $tt[0];

        // 1) the head term must appear in the product NAME (drops keyword/category-only
        //    and fuzzy noise — a yoghurt that merely lists "milk" is not a milk).
        $named = [];
        foreach ($hits as $h) {
            if (in_array($head, $m->tokens((string) ($h['product']['name'] ?? '')), true)) {
                $named[] = $h;
            }
        }
        if (! $named) $named = $hits;

        // 1b) the term must be the HEAD noun of the name, not a modifier. tokens() strips size
        //     tokens, so "Kolam Rice 5kg" -> [kolam,rice] (rice is head) but "D.rice Samosa" ->
        //     [rice,samosa] and "Rice Crisps" -> [rice,crisps] (rice modifies a snack). This
        //     drops rice-flavoured snacks from a rice recommendation regardless of category.
        $headed = [];
        foreach ($named as $h) {
            $toks = $m->tokens((string) ($h['product']['name'] ?? ''));
            $idx  = array_search($head, $toks, true);
            if ($idx !== false && $idx === count($toks) - 1) $headed[] = $h;
        }
        if ($headed) $named = $headed;   // keep the modifier-only set only if nothing is head

        // 2) dominant category by score mass; when one category clearly leads, keep only it.
        $catScore = []; $total = 0.0;
        foreach ($named as $h) {
            $c = mb_strtolower(trim((string) ($h['product']['category'] ?? '')));
            if ($c === '') continue;
            $catScore[$c] = ($catScore[$c] ?? 0) + (float) $h['score'];
            $total += (float) $h['score'];
        }
        if ($catScore && $total > 0) {
            arsort($catScore);
            $domCat  = (string) array_key_first($catScore);
            $domConf = $catScore[$domCat] / $total;
            if ($domConf >= 0.5) {
                $kept = [];
                foreach ($named as $h) {
                    if (mb_strtolower(trim((string) ($h['product']['category'] ?? ''))) === $domCat) {
                        $kept[] = $h;
                    }
                }
                if ($kept) $named = $kept;
            }
        }

        return array_map(fn ($h) => $h['product'], $named);
    }

    /**
     * The DISCOVERY portion of a compound message: everything up to the first downstream
     * command (add / checkout / deliver / location / pay). So a message that runs a whole
     * conversation together — "Need rice ... family of 5 ... ok add 2 ... checkout" — yields
     * just "Need rice ... family of 5", and the recommendation is built on that, not the lot.
     * Pure.
     */
    public static function discoverySegment(string $text): string
    {
        $t = ' ' . mb_strtolower(preg_replace('/[\r\n]+/', ' ', $text)) . ' ';
        $cut = mb_strlen($t);
        foreach (['\badd\b', '\bcheck ?out\b', '\bdeliver\w*\b', '\blocation\b', '\bpin\b',
                  '\bconfirm\b', '\bpay\b', '\bplace order\b'] as $cmd) {
            if (preg_match('/' . $cmd . '/', $t, $m, PREG_OFFSET_CAPTURE)) {
                $cut = min($cut, $m[0][1]);
            }
        }
        $seg = trim(mb_substr($t, 0, $cut));
        return $seg !== '' ? $seg : trim(mb_strtolower(preg_replace('/[\r\n]+/', ' ', $text)));
    }

    /**
     * The head PRODUCT noun of a discovery segment — the catalogue token that appears in the
     * most product NAMES (the generic head like "rice"/"oil"), not a brand/qualifier that
     * merely collides ("family" in "Family Rice Flour"). Negated terms ("not basmati") are
     * excluded. Returns '' when no product noun is present. Pure (given the catalogue).
     */
    /** Terms the customer ruled out in a discovery message ("not basmati", "no brown"). Pure. */
    public static function excludedTerms(string $segment): array
    {
        $m = new CatalogueMatcher();
        $out = [];
        if (preg_match_all('/\b(?:not|no|without|except|other than|don\'?t want)\s+([a-z]+)/', mb_strtolower($segment), $mm)) {
            foreach ($mm[1] as $w) foreach ($m->tokens($w) as $tk) $out[$tk] = true;
        }
        return $out;
    }

    public static function subjectTerm(string $segment, array $catalogue): string
    {
        $m = new CatalogueMatcher();

        $excluded = self::excludedTerms($segment);

        // document frequency of each token across product NAMES
        $nameFreq = [];
        foreach ($catalogue as $p) {
            foreach (array_unique($m->tokens((string) ($p['name'] ?? ''))) as $tk) {
                $nameFreq[$tk] = ($nameFreq[$tk] ?? 0) + 1;
            }
        }

        $best = ''; $bestFreq = 0; $rank = 0; $bestRank = PHP_INT_MAX;
        foreach ($m->tokens($segment) as $tk) {
            $rank++;
            if (isset($excluded[$tk])) continue;
            $f = $nameFreq[$tk] ?? 0;
            if ($f <= 0) continue;                 // not a product noun at all
            // prefer the most generic head; break ties by first appearance
            if ($f > $bestFreq || ($f === $bestFreq && $rank < $bestRank)) {
                $best = $tk; $bestFreq = $f; $bestRank = $rank;
            }
        }
        return $best;
    }

    /** Strip opinion/question filler to leave the product term. Pure. */
    public static function stripCues(string $text): string
    {
        $t = mb_strtolower($text);
        $t = preg_replace('/[^a-z0-9\s]+/', ' ', $t);
        $stop = ['which','one','is','are','the','a','an','good','best','nice','better','recommend',
            'recommendation','suggest','suggestion','do','you','u','what','whats','should','i','me',
            'for','most','popular','any','your','pick','favorite','favourite','give','tell','can',
            'please','pls','show','of','to','buy','get','take','takes','choose','about','think','and','or',
            'sells','sell','selling','sold','moves','moving','move','fast','fastest','more','people',
            'prefer','usually','well','hot','trending','really','worth','order'];
        $words = array_values(array_filter(preg_split('/\s+/', trim($t)), fn ($w) => $w !== '' && ! in_array($w, $stop, true)));
        return implode(' ', $words);
    }

    // ---------------------------------------------------------------- private wiring

    /** Resolve [term, candidate products] from the message and the active context. */
    private function subject(string $text, array $catalogue, array $st): array
    {
        // 1) a live numbered list -> those products
        $opts = $st['options'] ?? null;
        if (is_array($opts) && $opts) {
            $byId = [];
            foreach ($catalogue as $p) $byId[(int) ($p['id'] ?? 0)] = $p;
            $prods = [];
            foreach ($opts as $o) {
                $id = (int) ($o['product_id'] ?? 0);
                if ($id && isset($byId[$id])) $prods[] = $byId[$id];
            }
            if ($prods) {
                return [(string) ($st['last_query'] ?? ''), $prods];
            }
        }

        // 2) the lead product noun of the DISCOVERY part of the message (robust to a whole
        //    conversation crammed into one message: "Need rice ... family of 5 ... add 2 ...
        //    checkout" -> "rice", never the word-soup that matched "Family Rice Flour").
        $m       = new CatalogueMatcher();
        $segment = self::discoverySegment($text);
        $term    = self::subjectTerm($segment, $catalogue);
        if ($term === '') $term = self::stripCues($segment);   // fallback for odd phrasings
        if ($term !== '') {
            $hits = $m->search($term, $catalogue);
            if ($hits) {
                $cands = self::coherentCandidates($term, $hits);
                // honour stated exclusions ("not basmati") by dropping those products,
                // but never drop everything — if exclusion empties the set, keep the original.
                $excluded = self::excludedTerms($segment);
                if ($excluded) {
                    $filtered = [];
                    foreach ($cands as $p) {
                        $nameTokens = $m->tokens((string) ($p['name'] ?? ''));
                        if (! array_intersect($nameTokens, array_keys($excluded))) $filtered[] = $p;
                    }
                    if ($filtered) $cands = $filtered;
                }
                return [$term, array_slice($cands, 0, 6)];
            }
        }

        // 3) the last thing we searched for
        $last = (string) ($st['last_query'] ?? '');
        if ($last !== '') {
            $hits = $m->search($last, $catalogue);
            if ($hits) {
                return [$last, array_slice(self::coherentCandidates($last, $hits), 0, 6)];
            }
        }

        return [$term, []];
    }

    /** product_id => total ordered qty, for the given candidate ids (this tenant). */
    private function bestSellerQty(Tenant $tenant, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (! $ids) return [];

        return OrderItem::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('product_id', $ids)
            ->selectRaw('product_id, SUM(qty) as q')
            ->groupBy('product_id')
            ->pluck('q', 'product_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    private function idsOf(array $candidates, ?array $defaultProduct): array
    {
        $ids = array_map(fn ($p) => (int) ($p['id'] ?? 0), $candidates);
        if ($defaultProduct && ! empty($defaultProduct['id'])) $ids[] = (int) $defaultProduct['id'];
        return $ids;
    }

    private function cheaperAlternative(array $picked, array $candidates): ?array
    {
        $alt = null;
        foreach ($candidates as $p) {
            if ((int) ($p['id'] ?? 0) === (int) ($picked['id'] ?? -1)) continue;
            if (($p['stock'] ?? 1) <= 0) continue;
            if ((float) $p['price'] >= (float) $picked['price']) continue;
            if ($alt === null || (float) $p['price'] < (float) $alt['price']) $alt = $p;
        }
        return $alt;
    }

    private function persist(Conversation $convo, array $st, array $flat, string $term, ?array $recommended): void
    {
        $st['options']   = $flat;
        $st['last_kind'] = 'search';
        if ($term !== '') $st['last_query'] = $term;
        if ($recommended !== null) $st['last_recommended'] = $recommended;
        $st['last_activity'] = time();
        $convo->state = $st;
        $convo->save();
    }
}
