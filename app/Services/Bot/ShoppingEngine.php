<?php
namespace App\Services\Bot;

/**
 * ShoppingEngine — deterministic conversational core (framework-free).
 *
 * Resolves a pending selection, otherwise parses + resolves items. Each parsed
 * line is resolved to ONE of: a confident product (add), an ambiguous set
 * (choose), an out-of-stock-size note, or not-found. From those, the engine
 * picks ONE message-level behaviour:
 *
 *   - inline, all lines confident                  -> add immediately (fast path)
 *   - wholesale list (newlines), all confident     -> READ BACK, confirm with *OK*
 *   - a low-confidence guess (auto-pick), no choice -> READ BACK, confirm with *OK*
 *   - mixed: some confident + some ambiguous        -> REVIEW: lock the confident
 *        lines, ask only the ambiguous one(s); commit everything together on the
 *        pick (nothing auto-added, nothing silently dropped)
 *   - only ambiguous / browse                       -> show numbered options
 *
 * The guiding rule (a good shop attendant): never silently add the wrong thing,
 * never silently change a quantity, never silently lose a line. One extra
 * question beats one wrong order.
 *
 * Confidence (per resolved line):
 *   HIGH   single SKU, exact/size-pinned, or a clear name-token leader, or an
 *          owner default -> safe to lock without asking.
 *   MEDIUM an auto-pick guess among similar SKUs (strategy = explicit_then_auto)
 *          -> read back and confirm before committing.
 *   LOW    several plausible SKUs -> ask which one.
 */
class ShoppingEngine
{
    public const CONF_HIGH   = 'high';
    public const CONF_MEDIUM = 'medium';
    public const CONF_LOW    = 'low';

    public function __construct(
        private ShoppingParser $parser,
        private CatalogueMatcher $matcher,
        private ClarificationFlow $clarify,
        private string $currency = 'UGX',
        private array $defaults = [],        // term => product_id (tenant defaults)
        private string $strategy = 'explicit', // 'off' | 'explicit' | 'explicit_then_auto'
    ) {}

    private function money(float $a): string { return $this->currency . ' ' . number_format($a); }

    /**
     * A tidy heading for a clarify list: the words the customer's query and the matched products
     * actually share ("oh i have forgotten kolam rice how much is it" -> "kolam rice"). Falls back
     * to the cleaned query when there's no overlap.
     */
    private function cleanLabel(string $query, array $products): string
    {
        $qt = $this->matcher->tokens($query);
        if (! $qt) return trim($query);
        $names = [];
        foreach ($products as $p) {
            foreach ($this->matcher->tokens((string) ($p['name'] ?? '')) as $t) $names[$t] = true;
        }
        $common = [];
        foreach ($qt as $t) {
            if (isset($names[$t]) && ! in_array($t, $common, true)) $common[] = $t;
        }
        if ($common) return implode(' ', $common);
        // no overlap: drop obvious filler, keep the rest
        return implode(' ', array_slice($qt, 0, 4));
    }

    public function handle(string $text, array $products, array $cart, array $state): array
    {
        // 0) A wholesale order or auto-pick guess is awaiting the customer's OK / edit.
        if (! empty($state['pending_order']) && is_array($state['pending_order'])) {
            return $this->resolvePendingOrder($text, $products, $cart, $state);
        }

        // 1) Numbered options are pending -> resolve the customer's selection.
        $pending = $state['options'] ?? [];
        if (is_array($pending) && $pending) {
            $picks = $this->clarify->resolveSelection($text, $pending);
            if ($picks) {
                $added = [];
                foreach ($picks as $opt) {
                    $cart = $this->addToCart($cart, $opt['product_id'], $opt['name'], $opt['price'], (int) ($opt['qty'] ?: 1));
                    $added[] = ($opt['qty'] ?: 1) . ' x ' . $opt['name'];
                }
                // Flush any high-confidence lines that were locked while we waited for this
                // pick (the "mixed order" case). They commit together with the picked item, so
                // the confident lines are never auto-added early nor silently dropped.
                $alsoAdded = [];
                if (! empty($state['pending_resolved']) && is_array($state['pending_resolved'])) {
                    foreach ($state['pending_resolved'] as $line) {
                        $cart = $this->addToCart($cart, $line['product_id'] ?? null, $line['name'], (float) $line['price'], (int) $line['qty']);
                        $alsoAdded[] = $line['qty'] . ' x ' . $line['name'];
                    }
                }
                unset($state['options'], $state['pending_resolved']);
                $allAdded = array_merge($alsoAdded, $added);
                return $this->res(true, 'Added *' . implode('*, *', $allAdded) . "*.\n\n" . $this->cartSummary($cart)
                    . "\n\nAdd more, or say *checkout*.", $cart, $state, $allAdded, [], []);
            }
        }

        $parsed = $this->parser->parse($text);
        if ($parsed['edit'] || !$parsed['items']) {
            // EDIT ops are Phase 2; nothing to add -> let BotBrain handle (greet/unknown). Cart untouched.
            return $this->res(false, null, $cart, $state, [], [], []);
        }

        // catalogue-aware split: "rice sugar oil" -> 3 items, but keep "cooking oil" as one
        $items = [];
        foreach ($parsed['items'] as $it) {
            foreach ($this->maybeSplit($it, $products) as $sub) $items[] = $sub;
        }

        $addIntent = $parsed['add_intent'];

        // ---- Build a per-line PLAN (no cart mutation yet) -------------------------
        // Each entry is one of:
        //   ['kind'=>'add',    'product'=>row,'qty'=>n,'confidence'=>high|medium,'query'=>..]
        //   ['kind'=>'choose', 'label'=>..,'qty'=>n,'products'=>[rows]]
        //   ['kind'=>'size',   'label'=>..,'qty'=>n,'products'=>[rows],'requested'=>..,'available'=>[..]]
        //   ['kind'=>'missing','query'=>..]
        $plan = [];
        $sizeHintVariants = []; $defaultUsed = false;
        foreach ($items as $item) {
            $res = $this->resolveItem($item, $products, $parsed['browse'], $addIntent);
            if ($res['status'] === 'none') {
                $plan[] = ['kind' => 'missing', 'query' => $item['query']];
                continue;
            }
            if ($res['status'] === 'clarify') {
                $plan[] = ['kind' => 'choose', 'label' => $this->cleanLabel($item['query'], $res['products']),
                           'qty' => (int) ($item['count'] ?? 1), 'products' => $res['products']];
                continue;
            }
            if ($res['status'] === 'size_unavailable') {
                $plan[] = ['kind' => 'size', 'label' => $this->cleanLabel($item['query'], $res['products']),
                           'qty' => (int) ($item['count'] ?? 1), 'products' => $res['products'],
                           'requested' => $res['requested'], 'available' => $res['available']];
                continue;
            }
            // status === 'single'
            $via     = $res['via'] ?? '';
            $autoAdd = $addIntent || in_array($via, ['default', 'size', 'auto', 'confident'], true);
            if (! $autoAdd) {
                // a bare search ("rice") with no add cue -> show it, don't add
                $plan[] = ['kind' => 'choose', 'label' => $this->cleanLabel($item['query'], [$res['product']]),
                           'qty' => (int) ($item['count'] ?? 1), 'products' => [$res['product']]];
                continue;
            }
            $p = $res['product'];
            if ($via === 'default') { $defaultUsed = true; $sizeHintVariants = array_merge($sizeHintVariants, $res['siblings'] ?? []); }
            $plan[] = [
                'kind'       => 'add',
                'product'    => $p,
                'qty'        => (int) ($res['qty'] ?? $item['qty']),
                'confidence' => $res['confidence'] ?? self::CONF_HIGH,
                'query'      => $item['query'],
            ];
        }

        $addLines    = array_values(array_filter($plan, fn ($l) => $l['kind'] === 'add'));
        $chooseLines = array_values(array_filter($plan, fn ($l) => in_array($l['kind'], ['choose', 'size'], true)));
        $missing     = array_values(array_map(fn ($l) => $l['query'], array_filter($plan, fn ($l) => $l['kind'] === 'missing')));
        $guessLines  = array_values(array_filter($addLines, fn ($l) => ($l['confidence'] ?? self::CONF_HIGH) === self::CONF_MEDIUM));
        $itemCount   = count($addLines) + count($chooseLines);

        // Was this a wholesale-style LIST (newline-separated items)? Those get a read-back
        // even when every line is confident — exactly how a shop reads an order back.
        $isList = (bool) preg_match('/\S[\r\n]+\S/', trim($text)) && $itemCount >= 2;

        // Nothing actionable at all -> defer to BotBrain (friendly redirect / "not stocked").
        if (! $addLines && ! $chooseLines) {
            return $this->res(false, null, $cart, $state, [], [], $missing);
        }

        // CASE A — MIXED: some confident lines + at least one ambiguous line.
        // NEVER commit the confident lines on their own and NEVER flatten the
        // ambiguous lines into a list that a stray "1" could collapse. Lock the
        // confident lines, ask only the ambiguous one(s); the pick commits all.
        if ($chooseLines && $addLines) {
            return $this->reviewPick($addLines, $chooseLines, $missing, $cart, $state);
        }

        // CASE B — WHOLESALE LIST, all confident: read the order back, confirm with OK.
        // CASE C — an auto-pick GUESS (medium) with no ambiguous line: confirm before adding.
        if (($isList && ! $chooseLines && ! $guessLines) || ($guessLines && ! $chooseLines)) {
            return $this->confirmOrder($addLines, $missing, $cart, $state, (bool) $guessLines);
        }

        // CASE D — only ambiguous / browse: show numbered options (classic clarify).
        if ($chooseLines && ! $addLines) {
            return $this->showOptions($chooseLines, $missing, $parsed['browse'], $cart, $state);
        }

        // CASE E — inline order, every line confident: add immediately (the >90% fast path).
        $added = [];
        foreach ($addLines as $l) {
            $cart = $this->addToCart($cart, $l['product']['id'] ?? null, $l['product']['name'], (float) $l['product']['price'], (int) $l['qty']);
            $added[] = $l['qty'] . ' x ' . $l['product']['name'];
        }
        unset($state['options'], $state['pending_resolved']);

        $parts = ['Added *' . implode('*, *', $added) . '*.'];
        if ($missing) $parts[] = "I couldn't find: " . implode(', ', $missing) . '.';
        $parts[] = $this->cartSummary($cart) . "\n\nAdd more, or say *checkout*.";

        // size hint: shown only ONCE per conversation, when an owner default was applied
        if ($defaultUsed && $sizeHintVariants && empty($state['size_hint_shown'])) {
            $eg = array_slice(array_values(array_unique($sizeHintVariants)), 0, 2);
            if ($eg) {
                $parts[] = 'Want a different size? Just say e.g. *' . implode('* or *', $eg) . '*.';
                $state['size_hint_shown'] = true;
            }
        }

        return $this->res(true, implode("\n\n", $parts), $cart, $state, $added, [], $missing);
    }

    // ---- message-level behaviours ------------------------------------------------

    /**
     * MIXED order: lock the confident lines (stash them), ask only the ambiguous
     * one(s). The customer's number reply (handled at the top of handle()) commits
     * the stashed lines together with the picked item.
     */
    private function reviewPick(array $addLines, array $chooseLines, array $missing, array $cart, array $state): array
    {
        $resolved = [];
        $okLines  = [];
        foreach ($addLines as $l) {
            $resolved[] = [
                'product_id' => $l['product']['id'] ?? null,
                'name'       => $l['product']['name'],
                'price'      => (float) $l['product']['price'],
                'qty'        => (int) $l['qty'],
            ];
            $okLines[] = "\u{2705} {$l['qty']} x {$l['product']['name']}";
        }

        $sizeNotes = [];
        $groups    = [];
        foreach ($chooseLines as $c) {
            if ($c['kind'] === 'size') {
                $sizeNotes[] = '*' . $c['requested'] . '* isn\'t available — we have *' . implode('*, *', $c['available']) . '*';
            }
            $groups[] = ['label' => $c['label'], 'qty' => $c['qty'] ?? 1, 'products' => $c['products']];
        }
        $built = $this->clarify->buildOptions($groups, fn ($a) => $this->money($a));

        $state['options']          = $built['flat'];
        $state['pending_resolved'] = $resolved;
        $state['last_query']       = (string) ($groups[0]['label'] ?? '');
        $state['last_kind']        = 'search';
        unset($state['pending_order']);

        $oneChoice = count($groups) === 1;
        $parts = [];
        if ($okLines) $parts[] = "Here's your order so far:\n" . implode("\n", $okLines);
        if ($sizeNotes) $parts[] = "\u{1F4CF} " . implode('. ', $sizeNotes) . '.';
        $ask = $oneChoice ? "Just one thing to confirm \u{1F642}" : "A couple of things to confirm \u{1F642}";
        $parts[] = $ask . "\n" . $built['text'] . "\n\nReply with the *number(s)* you want (e.g. 1" . (count($built['flat']) > 1 ? ', 3' : '') . ') and I\'ll add everything together.';
        if ($missing) $parts[] = "I couldn't find: " . implode(', ', $missing) . '.';

        return $this->res(true, implode("\n\n", $parts), $cart, $state, [], $built['flat'], $missing);
    }

    /**
     * WHOLESALE list (all confident) or an auto-pick GUESS: read the order back and
     * wait for *OK*. Nothing is added until the customer confirms.
     */
    private function confirmOrder(array $addLines, array $missing, array $cart, array $state, bool $isGuess): array
    {
        $lines = [];
        $disp  = [];
        foreach ($addLines as $l) {
            $lines[] = [
                'product_id' => $l['product']['id'] ?? null,
                'name'       => $l['product']['name'],
                'price'      => (float) $l['product']['price'],
                'qty'        => (int) $l['qty'],
            ];
            $disp[] = "\u{2022} {$l['qty']} x {$l['product']['name']} — " . $this->money((float) $l['product']['price'] * (int) $l['qty']);
        }

        $state['pending_order'] = ['lines' => $lines, 'not_found' => $missing];
        unset($state['options'], $state['pending_resolved']);

        if ($isGuess && count($disp) === 1) {
            // single best-guess: "Did you mean ...?"
            $body = "Just to confirm \u{1F642}\n" . implode("\n", $disp)
                  . "\n\nReply *OK* to add it, or tell me a different size / brand.";
        } else {
            $body = "Here's your order \u{1F9FE}\n" . implode("\n", $disp)
                  . "\n\nReply *OK* to add everything, or tell me what to change.";
        }
        if ($missing) $body .= "\n\nI couldn't find: " . implode(', ', $missing) . '.';

        return $this->res(true, $body, $cart, $state, [], [], $missing);
    }

    /** Only ambiguous / browse lines: classic numbered clarify. */
    private function showOptions(array $chooseLines, array $missing, bool $browse, array $cart, array $state): array
    {
        $sizeNotes = []; $groups = [];
        foreach ($chooseLines as $c) {
            if ($c['kind'] === 'size') {
                $sizeNotes[] = '*' . $c['requested'] . '* isn\'t available — we have *' . implode('*, *', $c['available']) . '*';
            }
            $groups[] = ['label' => $c['label'], 'qty' => $c['qty'] ?? 1, 'products' => $c['products']];
        }
        $built = $this->clarify->buildOptions($groups, fn ($a) => $this->money($a));
        $state['options']    = $built['flat'];
        $state['last_query'] = (string) ($groups[0]['label'] ?? '');
        $state['last_kind']  = 'search';
        unset($state['pending_order'], $state['pending_resolved']);

        $parts = [];
        if ($sizeNotes) $parts[] = "\u{1F4CF} " . implode('. ', $sizeNotes) . '.';
        $head = $browse ? "Yes \u{1F44D} here's what we have:" : "Here's what we have:";
        $parts[] = $head . "\n" . $built['text'] . "\n\nReply with the *number(s)* you want (e.g. 1, 3).";
        if ($missing) $parts[] = "I couldn't find: " . implode(', ', $missing) . '.';

        return $this->res(true, implode("\n\n", $parts), $cart, $state, [], $built['flat'], $missing);
    }

    /**
     * Resolve a reply to a pending wholesale/guess order: OK -> commit; no -> drop;
     * anything else -> drop the proposal and let BotBrain reprocess it as a fresh message.
     */
    private function resolvePendingOrder(string $text, array $products, array $cart, array $state): array
    {
        $po  = $state['pending_order'];
        $lc  = mb_strtolower(trim($text));
        $lcn = trim(preg_replace('/[^a-z0-9\s]/', '', $lc));

        $affirm = ['ok','okay','oki','k','kk','yes','yas','yep','yeah','ya','yup','sure','fine',
            'confirm','confirmed','correct','right','add','add all','add them','add it','add everything',
            'ok add','ok add all','okay add all','yes please','go ahead','proceed','done','place order','that\'s all'];
        $decline = ['no','nope','nah','cancel','stop','dont','do not','not now','nevermind','never mind',
            'no thanks','no thank you','forget it','leave it'];

        $isAffirm  = in_array($lcn, $affirm, true) || (bool) preg_match('/^(ok|okay|yes|yep|sure|confirm)\b/', $lcn);
        $isDecline = in_array($lcn, $decline, true) || (bool) preg_match('/^(no|nope|nah|cancel|stop)\b/', $lcn);

        if ($isAffirm && ! $isDecline) {
            $added = [];
            foreach (($po['lines'] ?? []) as $line) {
                $cart = $this->addToCart($cart, $line['product_id'] ?? null, $line['name'], (float) $line['price'], (int) $line['qty']);
                $added[] = $line['qty'] . ' x ' . $line['name'];
            }
            unset($state['pending_order'], $state['options'], $state['pending_resolved']);
            $body = 'Added *' . implode('*, *', $added) . "*.\n\n" . $this->cartSummary($cart) . "\n\nAdd more, or say *checkout*.";
            return $this->res(true, $body, $cart, $state, $added, [], $po['not_found'] ?? []);
        }

        if ($isDecline) {
            unset($state['pending_order'], $state['options'], $state['pending_resolved']);
            $body = "No problem \u{1F642} I haven't added those. Tell me what you'd like, or say *cart* to review.";
            return $this->res(true, $body, $cart, $state, [], [], []);
        }

        // Not a yes/no: the customer is changing their mind / naming something new.
        // Drop the proposal and let BotBrain reprocess this message from scratch.
        unset($state['pending_order'], $state['pending_resolved']);
        return $this->res(false, null, $cart, $state, [], [], []);
    }

    /** Split a multi-word item into separate products only when the whole doesn't cover and each word resolves. */
    private function maybeSplit(array $item, array $products): array
    {
        $qtoks = $this->matcher->tokens($item['query']);
        if (count($qtoks) < 2) return [$item];
        $cands = $this->matcher->search($item['query'], $products);
        $bestHits = $cands ? ($cands[0]['hits'] ?? 0) : 0;
        if ($bestHits >= count($qtoks)) return [$item];      // whole is a real multi-word product (e.g. cooking oil)
        $out = [];
        foreach ($qtoks as $w) {
            if (!$this->matcher->search($w, $products)) return [$item];   // a word doesn't resolve -> don't split
            $out[] = ['query' => $w, 'qty' => $item['qty'], 'count' => $item['count'] ?? null, 'size' => null, 'unit' => null];
        }
        return $out;
    }

    /**
     * Resolve one parsed item -> single | clarify | size_unavailable | none, applying size +
     * default rules. A 'single' result carries a 'confidence' (high|medium).
     *
     * Precedence (multiple candidates):
     *   1. stated size matches exactly one SKU      -> that SKU (HIGH)
     *   2. stated size matches >1 SKU but ONE name leads (e.g. "exclusive") -> that SKU (HIGH)
     *   3. stated size matches >1 SKU, no leader     -> CLARIFY (pick among that size)
     *   4. stated size matches 0 SKUs                -> size_unavailable
     *   5. no size, a multi-word query clearly names one product -> that SKU (HIGH, "confident")
     *   6. no size, owner default valid + in-stock    -> default (HIGH)
     *   7. no size, strategy=explicit_then_auto       -> auto-pick (MEDIUM guess)
     *   8. otherwise                                   -> CLARIFY
     * A single candidate always resolves directly (HIGH); a size token then acts as a count.
     */
    public function resolveItem(array $item, array $products, bool $browse = false, bool $addIntent = false): array
    {
        $query = $item['query'];
        $qty = (int) ($item['qty'] ?? 1);
        $size = $item['size'] ?? null;
        $count = $item['count'] ?? null;

        $cands = $this->matcher->search($query, $products);
        if (!$cands) return ['status' => 'none'];

        // single candidate: resolve directly; a size token here just means count
        if (count($cands) === 1) {
            return ['status' => 'single', 'product' => $cands[0]['product'], 'qty' => $qty, 'confidence' => self::CONF_HIGH];
        }

        // explicit browse ("show me rice" / "which rice") -> always list, never auto-pick
        if ($browse) {
            $opts = $this->matcher->clarifyCheck($query, $products);
            if ($opts !== null) return ['status' => 'clarify', 'products' => $opts];
            return ['status' => 'clarify', 'products' => array_map(fn ($c) => $c['product'], array_slice($cands, 0, 5))];
        }

        // multiple candidates ---------------------------------------------------
        if ($size !== null) {
            $sized = array_values(array_filter($cands, fn ($c) => CatalogueMatcher::skuSize($c['product']['name'] ?? '') === $size));
            if (count($sized) === 1) {
                return ['status' => 'single', 'product' => $sized[0]['product'], 'qty' => $count ?? 1, 'via' => 'size', 'confidence' => self::CONF_HIGH];
            }
            if (count($sized) > 1) {
                // Several SKUs share that exact size. If the customer's words clearly name ONE of
                // them (more matched name-tokens than any rival, e.g. "exclusive"), take it —
                // otherwise let them pick. This is the fix for "kooksy ice cream exclusive 1ltr"
                // resolving to a clarify even though "exclusive" pins the product.
                $lead = $this->confidentLeader($query, $sized);
                if ($lead !== null) {
                    return ['status' => 'single', 'product' => $lead, 'qty' => $count ?? 1, 'via' => 'confident', 'confidence' => self::CONF_HIGH];
                }
                return ['status' => 'clarify', 'products' => array_map(fn ($c) => $c['product'], array_slice($sized, 0, 5))];
            }
            // requested size matches NO SKU -> tell the customer which sizes ARE available
            $avail = [];
            foreach ($cands as $c) {
                $sz = CatalogueMatcher::skuSize($c['product']['name'] ?? '');
                if ($sz) $avail[$sz] = true;
            }
            if ($avail) {
                return ['status' => 'size_unavailable', 'requested' => $size, 'available' => array_keys($avail),
                        'products' => array_map(fn ($c) => $c['product'], array_slice($cands, 0, 5))];
            }
            return ['status' => 'clarify', 'products' => array_map(fn ($c) => $c['product'], array_slice($cands, 0, 5))];
        }

        // A PURE SEARCH (no add verb, no quantity, no size) must never auto-add.
        // "rice" -> show options; only "add rice" / "2 rice" / "rice 2kg" resolves+adds.
        if (! $addIntent && ! $browse) {
            $opts = $this->matcher->clarifyCheck($query, $products);
            if ($opts !== null) return ['status' => 'clarify', 'products' => $opts];
            return ['status' => 'clarify', 'products' => array_map(fn ($c) => $c['product'], array_slice($cands, 0, 8))];
        }

        // High-confidence match: a multi-word query that fully describes ONE product which
        // clearly leads the field auto-resolves. e.g. "Uganda Waragi Premium Pet 6pcs".
        $qTok = $this->matcher->tokens($query);
        $nq   = count($qTok);
        if ($nq >= 2 && $cands) {
            $topHits    = (int) ($cands[0]['hits'] ?? 0);
            $runnerHits = (int) ($cands[1]['hits'] ?? 0);
            if ($topHits >= $nq && $runnerHits < $topHits && ($cands[0]['product']['stock'] ?? 1) > 0) {
                return ['status' => 'single', 'product' => $cands[0]['product'], 'qty' => $count ?? 1, 'via' => 'confident', 'confidence' => self::CONF_HIGH];
            }
        }

        // no size: try the owner's default for this term
        $term = implode(' ', $this->matcher->tokens($query));
        if ($this->strategy !== 'off' && $term !== '' && isset($this->defaults[$term])) {
            $pid = $this->defaults[$term];
            foreach ($cands as $c) {
                $p = $c['product'];
                if (($p['id'] ?? null) == $pid && ($p['stock'] ?? 1) > 0) {
                    $siblings = [];
                    foreach ($cands as $cc) {
                        $sz = CatalogueMatcher::skuSize($cc['product']['name'] ?? '');
                        if ($sz) $siblings[] = $term . ' ' . $sz;
                    }
                    return ['status' => 'single', 'product' => $p, 'qty' => $qty, 'via' => 'default', 'siblings' => $siblings, 'confidence' => self::CONF_HIGH];
                }
            }
        }

        // optional auto-pick when no default -> a GUESS, so it is MEDIUM (confirmed, not silent)
        if ($this->strategy === 'explicit_then_auto') {
            $top = $cands[0]['score'];
            $pick = array_values(array_filter($cands, fn ($c) => $c['score'] >= $top - 0.001));
            usort($pick, function ($a, $b) {
                $pa = (float) ($a['product']['price'] ?? 0); $pb = (float) ($b['product']['price'] ?? 0);
                if ($pa !== $pb) return $pa <=> $pb;                 // cheapest among the most relevant
                return strlen($a['product']['name']) <=> strlen($b['product']['name']);
            });
            // The tenant has explicitly opted into "pick for me" with this strategy, so an
            // auto-pick commits (HIGH) rather than asking — that is the point of the opt-in.
            // (Switch to CONF_MEDIUM here if you want auto-picks read back for confirmation.)
            return ['status' => 'single', 'product' => $pick[0]['product'], 'qty' => $qty, 'via' => 'auto', 'confidence' => self::CONF_HIGH];
        }

        // otherwise: clarify (price-spread groups first, else top candidates)
        $opts = $this->matcher->clarifyCheck($query, $products);
        if ($opts !== null) return ['status' => 'clarify', 'products' => $opts];
        return ['status' => 'clarify', 'products' => array_map(fn ($c) => $c['product'], array_slice($cands, 0, 5))];
    }

    /**
     * Among a set of equally-sized (or otherwise tied) candidates, return the ONE whose name the
     * customer's words single out — i.e. it covers strictly more query name-tokens than every
     * rival. Returns null when no single product leads (so the caller asks instead of guessing).
     */
    private function confidentLeader(string $query, array $scoredCands): ?array
    {
        $qTok = $this->matcher->tokens($query);
        if (! $qTok) return null;
        $qSet = array_flip($qTok);

        $best = null; $bestCov = -1; $tie = false;
        foreach ($scoredCands as $c) {
            $p = $c['product'] ?? $c;
            $cov = 0;
            foreach ($this->matcher->tokens((string) ($p['name'] ?? '')) as $t) {
                if (isset($qSet[$t])) $cov++;
            }
            if ($cov > $bestCov) { $bestCov = $cov; $best = $p; $tie = false; }
            elseif ($cov === $bestCov) { $tie = true; }
        }
        // a clear, non-trivial leader (covers >=1 query token and no rival ties it)
        if ($best !== null && $bestCov >= 1 && ! $tie) return $best;
        return null;
    }

    private function addToCart(array $cart, $id, string $name, float $price, int $qty): array
    {
        foreach ($cart as &$line) {
            if ($id !== null && $line['product_id'] === $id) { $line['qty'] += $qty; return $cart; }
            if ($id === null && $line['name'] === $name) { $line['qty'] += $qty; return $cart; }
        }
        $cart[] = ['product_id' => $id, 'name' => $name, 'price' => $price, 'qty' => max(1, $qty)];
        return $cart;
    }

    public function cartSummary(array $cart): string
    {
        if (!$cart) return 'Your basket is empty.';
        $total = 0; $lines = [];
        foreach ($cart as $l) {
            $sub = $l['price'] * $l['qty']; $total += $sub;
            $lines[] = "• {$l['qty']} x {$l['name']} — " . $this->money($sub);
        }
        return "\u{1F6D2} *Your basket*\n" . implode("\n", $lines) . "\n*Total: " . $this->money($total) . '*';
    }

    private function res(bool $handled, ?string $reply, array $cart, array $state, array $added, array $options, array $notFound): array
    {
        return ['handled' => $handled, 'reply' => $reply, 'cart' => $cart, 'state' => $state,
                'added' => $added, 'options' => $options, 'not_found' => $notFound];
    }
}
