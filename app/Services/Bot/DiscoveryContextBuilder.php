<?php

namespace App\Services\Bot;

/**
 * DiscoveryContextBuilder — turns a free-text discovery message into a structured shopping
 * context the recommender can reason over, instead of a blob of words.
 *
 *   "Need rice / not basmati / daily use / family of 5 / not expensive"
 *      -> [ 'product' => 'rice', 'exclude' => ['basmati'], 'usage' => 'daily',
 *           'family_size' => 5, 'budget' => 'low' ]
 *
 * How each field is USED (kept honest — no field claims more than the catalogue supports):
 *   product      -> the search subject (head noun, not a colliding token).
 *   exclude      -> products carrying these tokens are dropped ("not basmati").
 *   budget       -> biases the pick toward the cheaper ('low') or pricier ('high') end.
 *   family_size  -> a soft nudge toward a larger pack when sizes are present.
 *   usage        -> shapes the WORDING ("for daily use ..."); it only changes the PICK when
 *                   products are tagged with that usage in keywords, otherwise it is echo only.
 *
 * Pure & static. Reuses SalesAssistantBrain's token/exclusion helpers so extraction stays
 * consistent with the matcher.
 */
class DiscoveryContextBuilder
{
    public static function build(string $segment, array $catalogue): array
    {
        return [
            'product'     => SalesAssistantBrain::subjectTerm($segment, $catalogue),
            'exclude'     => array_keys(SalesAssistantBrain::excludedTerms($segment)),
            'usage'       => self::usage($segment),
            'family_size' => self::familySize($segment),
            'budget'      => self::budget($segment),
            'size'        => SalesAssistantBrain::parseSize($segment),
        ];
    }

    // ============================== persistent discovery ==============================
    // A discovery conversation is rarely one message. These helpers let BotBrain keep a
    // single, growing context in state.discovery so that "Need rice" then "Not basmati" then
    // "Family of 5" enrich ONE object instead of each being parsed (and searched) in isolation.

    /** Discovery signals carried by a single message, keyed as state.discovery is stored. */
    public static function fromMessage(string $text, array $catalogue): array
    {
        $b = self::build($text, $catalogue);
        return [
            'category'    => (string) ($b['product'] ?? ''),
            'exclude'     => $b['exclude'] ?? [],
            'budget'      => $b['budget'] ?? null,
            'usage'       => $b['usage'] ?? null,
            'family_size' => $b['family_size'] ?? null,
            'size'        => $b['size'] ?? null,
        ];
    }

    /** Merge a new message's signals into the running context. Scalars overwrite only when the
     *  new value is non-empty (so "Not basmati" never wipes category); excludes accumulate. */
    public static function merge(array $base, array $incoming): array
    {
        $out = $base;
        foreach (['category', 'budget', 'usage', 'family_size', 'size'] as $k) {
            if (! empty($incoming[$k])) $out[$k] = $incoming[$k];
        }
        $out['exclude'] = array_values(array_unique(array_merge(
            is_array($base['exclude'] ?? null) ? $base['exclude'] : [],
            is_array($incoming['exclude'] ?? null) ? $incoming['exclude'] : []
        )));
        return $out;
    }

    /** True when the message contributed ANY discovery signal worth keeping. */
    public static function hasSignal(array $c): bool
    {
        return ! empty($c['category']) || ! empty($c['exclude']) || ! empty($c['budget'])
            || ! empty($c['usage']) || ! empty($c['family_size']) || ! empty($c['size']);
    }

    /** True when the message is really a concrete order line ("5 coke", "rice 5kg"), which must
     *  NOT be swallowed by discovery — it's a buy, not a question. */
    public static function looksLikeConcreteAdd(string $text, array $catalogue): bool
    {
        $lc = mb_strtolower($text);
        if (preg_match('/\b\d+\s*(x|pcs?|pkts?|packs?|units?)\b/', $lc)) return true;
        if (preg_match('/\b\d+(\.\d+)?\s*(kg|kgs|g|gm|gms|grams?|ml|l|ltr|litres?|liters?)\b/', $lc)) return true;
        // a number directly followed by a product word is an order line ("5 coke", "2 rice"),
        // UNLESS the following word belongs to a household-size or qualifier phrase
        // ("family of 5", "5 people", "5 not expensive").
        $stop = array_flip(['people', 'persons', 'person', 'members', 'member', 'of', 'us', 'heads',
            'head', 'kids', 'adults', 'family', 'pax', 'not', 'no', 'non', 'only', 'just',
            'and', 'or', 'but', 'more', 'other', 'some', 'any', 'for']);
        if (preg_match_all('/\b\d+\s+([a-z]{2,})/', $lc, $m)) {
            foreach ($m[1] as $w) if (! isset($stop[$w])) return true;
        }
        return false;
    }

    /**
     * Decide what the discovery layer should do with this message. Pure & testable.
     * @return array{action:'enter'|'enrich'|'ask'|'skip', ctx:array}
     *   enter  — start a new discovery and recommend
     *   enrich — fold this message into the active discovery and re-recommend
     *   ask    — we have a qualifier but no category yet; ask what they're shopping for
     *   skip   — not a discovery message; let the normal pipeline handle it
     */
    public static function decide(?array $active, string $text, array $catalogue): array
    {
        $incoming = self::fromMessage($text, $catalogue);
        $concrete = self::looksLikeConcreteAdd($text, $catalogue);
        $lead     = ConversationStageAnalyzer::leadStage($text);

        if ($active === null) {
            // ENTRY only on an explicit discovery-verb message ("need/want/looking for ..."), so
            // plain "rice", "5 coke" and "rice 5kg" keep their existing search/add behaviour.
            if ($concrete || $lead !== ConversationStageAnalyzer::DISCOVERY) {
                return ['action' => 'skip', 'ctx' => []];
            }
            if (! empty($incoming['category'])) return ['action' => 'enter', 'ctx' => $incoming];
            if (self::hasSignal($incoming))     return ['action' => 'ask',   'ctx' => $incoming];
            return ['action' => 'skip', 'ctx' => []];
        }

        // Active discovery: a concrete order line breaks out; a no-signal message (a number, "ok",
        // "more brands") is left for the normal handlers; anything else enriches.
        if ($concrete || ! self::hasSignal($incoming)) return ['action' => 'skip', 'ctx' => []];
        $merged = self::merge($active, $incoming);
        return ['action' => empty($merged['category']) ? 'ask' : 'enrich', 'ctx' => $merged];
    }

    /**
     * Render the accumulated context as a canonical "which X is good ..." sentence so it can be
     * fed back through the existing, tested SalesAssistantBrain opinion path — the recommendation
     * is therefore produced by the SAME deterministic pick logic, just with full context.
     */
    public static function toOpinionText(array $c): string
    {
        $cat = trim((string) ($c['category'] ?? ''));
        $s   = 'which ' . ($cat !== '' ? $cat : 'product') . ' is good';
        if (($c['usage'] ?? null) === 'daily')   $s .= ' for daily use';
        if (($c['usage'] ?? null) === 'special') $s .= ' for a special occasion';
        if (($c['usage'] ?? null) === 'cooking') $s .= ' for cooking';
        if (! empty($c['family_size']))          $s .= ' family of ' . (int) $c['family_size'];
        foreach (($c['exclude'] ?? []) as $e) {
            if (! in_array($e, ['expensive', 'cheap', 'pricey', 'costly'], true)) $s .= ' not ' . $e;
        }
        if (($c['budget'] ?? null) === 'low')  $s .= ' not expensive';
        if (($c['budget'] ?? null) === 'high') $s .= ' premium quality';
        if (! empty($c['size']['value']))      $s .= ' ' . $c['size']['value'] . ($c['size']['unit'] ?? '');
        return $s;
    }

    /** 'daily' | 'special' | 'cooking' | null */
    public static function usage(string $segment): ?string
    {
        $s = mb_strtolower($segment);
        if (preg_match('/\bdaily( use| meals?)?\b|\beveryday\b|\bregular use\b|\bhome use\b/', $s)) return 'daily';
        if (preg_match('/\bbiryani\b|\bpulao\b|\bspecial( occasion)?\b|\bparty\b|\bguests?\b|\bfeast\b/', $s)) return 'special';
        if (preg_match('/\bcooking\b|\bfrying\b|\bbaking\b/', $s)) return 'cooking';
        return null;
    }

    /** Number of people in the household, or null. */
    public static function familySize(string $segment): ?int
    {
        $s = mb_strtolower($segment);
        if (preg_match('/\bfamily of (\d{1,2})\b/', $s, $m)) return (int) $m[1];
        if (preg_match('/\b(\d{1,2})\s+(people|persons?|members?|of us|heads?)\b/', $s, $m)) return (int) $m[1];
        if (preg_match('/\bfor (\d{1,2})\b/', $s, $m)) return (int) $m[1];
        return null;
    }

    /** 'low' | 'high' | null */
    public static function budget(string $segment): ?string
    {
        $s = mb_strtolower($segment);
        if (preg_match('/\b(not (too )?(expensive|costly|pricey))\b|\bcheap(est|er)?\b|\baffordable\b|\bbudget\b|\binexpensive\b|\beconomical\b|\blow ?price\b|\bvalue for money\b/', $s)) {
            return 'low';
        }
        if (preg_match('/\b(premium|best quality|high quality|top quality|finest|expensive is fine|price no|money no)\b/', $s)) {
            return 'high';
        }
        return null;
    }

    /**
     * A natural-language prefix echoing the customer's stated context, e.g.
     * "For a family of 5 looking for affordable daily-use rice, ". Empty when no context.
     */
    public static function phrase(array $ctx): string
    {
        $product = trim((string) ($ctx['product'] ?? ''));
        $bits = [];
        if (! empty($ctx['family_size'])) $bits[] = 'a family of ' . (int) $ctx['family_size'];
        $qual = [];
        if (($ctx['budget'] ?? null) === 'low')  $qual[] = 'affordable';
        if (($ctx['budget'] ?? null) === 'high') $qual[] = 'premium';
        if (($ctx['usage'] ?? null) === 'daily')   $qual[] = 'daily-use';
        if (($ctx['usage'] ?? null) === 'special') $qual[] = 'special-occasion';
        if (($ctx['usage'] ?? null) === 'cooking') $qual[] = 'cooking';

        $tail = trim(implode(' ', $qual) . ($product !== '' ? ' ' . $product : ''));
        if ($bits && $tail !== '') return 'For ' . implode(' ', $bits) . ' looking for ' . $tail . ', ';
        if ($bits)                 return 'For ' . implode(' ', $bits) . ', ';
        if ($qual && $product!=='') return 'For ' . implode(' ', $qual) . ' ' . $product . ', ';
        return '';
    }
}
