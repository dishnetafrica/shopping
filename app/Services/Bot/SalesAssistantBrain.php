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
        $intent = self::detect($text);
        if ($intent === null) {
            return null;
        }

        $st    = is_array($convo->state) ? $convo->state : [];
        $byId  = [];
        foreach ($catalogue as $p) {
            $byId[(int) ($p['id'] ?? 0)] = $p;
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
        $pick      = self::pickRecommendation($term, $candidates, $defaultProduct, $sellerQty);
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

        switch ($pick['tag']) {
            case 'popular':
                $lead = "For {$label}, most customers choose *{$product['name']}* ({$price}) — it's our most popular.";
                break;
            case 'pick':
                $lead = "For {$label} I'd recommend *{$product['name']}* ({$price})"
                      . ($isTopSeller ? " — and it's what most customers buy." : ".");
                break;
            case 'value':
                $lead = "For {$label}, *{$product['name']}* ({$price}) is the best value for money.";
                break;
            default:
                $lead = "For {$label} I'd go with *{$product['name']}* ({$price}).";
        }

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
    public static function pickRecommendation(string $term, array $candidates, ?array $defaultProduct, array $sellerQtyById): array
    {
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
        $value = null;
        foreach ($candidates as $p) {
            if (($p['stock'] ?? 1) <= 0) continue;
            if ($value === null || (float) $p['price'] < (float) $value['price']) $value = $p;
        }
        if ($value !== null) {
            return ['product' => $value, 'tag' => 'value', 'basis' => 'great value for the price'];
        }

        if ($candidates) {
            return ['product' => $candidates[0], 'tag' => 'first', 'basis' => 'a good choice'];
        }
        return ['product' => null, 'tag' => 'none', 'basis' => ''];
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

        // 2) a product term inside the message
        $term = self::stripCues($text);
        $m    = new CatalogueMatcher();
        if ($term !== '') {
            $hits = $m->search($term, $catalogue);
            if ($hits) {
                return [$term, array_map(fn ($c) => $c['product'], array_slice($hits, 0, 6))];
            }
        }

        // 3) the last thing we searched for
        $last = (string) ($st['last_query'] ?? '');
        if ($last !== '') {
            $hits = $m->search($last, $catalogue);
            if ($hits) {
                return [$last, array_map(fn ($c) => $c['product'], array_slice($hits, 0, 6))];
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
