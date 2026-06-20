<?php
namespace App\Services\Bot;

/**
 * Order Intent Router (Phase 1 — deterministic, no LLM).
 *
 * Classifies an inbound customer message into ONE shopping intent, in a fixed priority order,
 * BEFORE any catalogue matching runs. Built from the Pal's Snacks NLU failure audit: most
 * inbound messages are greetings, quantities, confirmations, cancellations and price/delivery
 * questions in Gujlish/Hindi — not product names. Catalogue matching (and the only
 * "we don't stock" reply) is reached solely via the PRODUCT_SEARCH fall-through.
 *
 * Distinct from IntentRouter (lead/ticket/shopping diversion for the sales side). Pure
 * (no framework); unit-tested in qa/intent_router.php against every audit fallback example.
 *
 * Priority (highest first):
 *   1 HUMAN  2 CONFIRM  3 REMOVAL  4 ADDITION  5 QUANTITY  6 PRICE
 *   7 DELIVERY  8 MENU  9 GREETING  10 SOCIAL  11 PRODUCT_SEARCH  12 UNKNOWN
 *
 * Greeting precedence is realised by peeling a leading greeting prefix and re-reading the
 * remainder: a pure greeting → GREETING, a greeting that prefixes an order → the order intent.
 * Greeting/Social only "win" the tail when nothing is actually being ordered (no product slot),
 * so a real product is never starved by a stray "hello"/"nice".
 *
 * classify() returns: ['intent' => <const>, 'product' => ?string, 'qty' => ?int]
 */
class OrderIntentRouter
{
    public const HUMAN          = 'human';
    public const CONFIRM        = 'confirm';
    public const REMOVAL        = 'removal';
    public const ADDITION       = 'addition';
    public const QUANTITY       = 'quantity';
    public const PRICE          = 'price';
    public const DELIVERY       = 'delivery';
    public const MENU           = 'menu';
    public const GREETING       = 'greeting';
    public const SOCIAL         = 'social';
    public const PRODUCT_SEARCH = 'product_search';
    public const UNKNOWN        = 'unknown';

    private const RE_LEAD_GREET = '/^\s*(jai\s*swaminarayan|jsk|jai\s*shri?\s*krishna|kem\s*ch?o|kaise\s*ho|kese\s*ho|namaste|namaskar|ram\s*ram|salaam|salam|good\s*(?:morning|afternoon|evening|night)|gud\s*(?:mrng|morning)|hi+|hello+|helo+|hey+|hii+|hlo|heloo+|hola)\b[\s,!.]*/iu';

    private const RE_HUMAN = '/\b(call\s*me|call\s*back|ring\s*me|talk\s*to|speak\s*to|customer\s*care|human|agent|representative)\b|\bnumber\s*(nikal|do|dedo|send|aapo|chahiye)\b|\bgroup\s*me(se|n)\s*number\b|\bnumber\b[^.]*\bnikal\s*do\b|\bphone\s*number\b|\bcontact\s*(no|number)\b/iu';

    private const RE_CONFIRM = '/\b(confirm|confirmed|finalize|finalise)\b|\b(take|give|place)\s*(my\s*)?order\b|\border\s*(karo|karu|kari|le|lelo|lai\s*lo|done)\b|\bi\s*made\s*my\s*list\b|\bmy\s*list\b|\bbook\s*(kar|karo)\b|\bdone\s*with\s*(my\s*)?(list|order)\b|\bcheck\s*out\b|\bcheckout\b/iu';

    private const RE_REMOVAL = '/\bnathi\s*lav\w*\b|\bnathi\s*joi\w*\b|\bnai\s*joi\w*\b|\bnahi\s*chahiy\w*\b|\bmat\s*la(o|na)?\b|\bhata(o|do|vo)\b|\bcancel\b|\bremove\b|\bdelete\b|\bkaadi\s*(nakh|do)\w*\b|\bkadi\s*nakh\w*\b|\bnathi\s*levanu\b|\bna\s*joiye\b/iu';

    private const RE_ADDITION = '/\badd\s*(kar\w*|karna|karo|it|this|also|me|kari)?\b|\balso\s*add\b|\bbring\s+.*\balso\b|\b\w+\s+also\b|\bane\s+\w+|\baur\s+\w+|\b\w+\s+(bhi|saathe|sathe)\b|\bjab\s*aavu\s*tab\s*add\b|\bwith\s*this\b|\bema\s*umer\w*\b/iu';

    private const RE_QTY = '/\b(\d+(?:[.,\/]\d+)?)\s*(kgs?|kilos?|gms?|grams?|gm|pcs?|pieces?|pkts?|packets?|dish(?:es)?|plates?|thalis?|nos?|boxes?|box|dozen)\b|\b(\d{3,4})\s*$|\b(\d+)\s*x\b|^\s*(one|two|three|four|five|six|seven|eight|nine|ten|ek|be|tran|char|paanch)\s*$/iu';

    private const RE_PRICE = '/\b(price|prices|amount|total|cost|charges?|rate|bill|paisa|paise)\b|\bketla\s*(thay|thse|na|aave|che|hoy|nu)\b|\bketlo\b|\bketli\b|\bkitna\b|\bkitne\s*(ka|ke)\b|\bhow\s*much\b|\bshu\s*bhav\b|\bbhav\b/iu';

    private const RE_DELIVERY = '/\bketla\s*vage\b|\bkitne\s*baje\b|\bwhat\s*time\b|\bwhen\s*(will|can|you|are)\b|\bwill\s*you\s*be\s*coming\b|\bare\s*you\s*coming\b|\bcoming\s*(today|tomorrow)\b|\bdeliver\w*\b|\bmokl\w*\b|\bmokal\w*\b|\baavse\b|\baavso\b|\bkab\s*(aa|tak|aavse)\b|\bleva\s*av\w*\b|\bpick\s*up\b|\bpickup\b|\bcollect\b|\bbhej\s*diy\w*\b|\bbhej\s*do\b|\bdelivery\b|\baddress\b|\bat\s+[a-z]+\s*$/iu';

    private const RE_MENU = '/\bmenu\b|\bmanue\b|\bmanu\b|\bmenue\b|\bsu\s*che\b|\bshu\s*che\b|\bkya\s*hai\b|\bwhat\s*do\s*you\s*have\b|\baaj\s*(nu|na|ni)\b|\baje\s*(su|shu|dinner|lunch|nasto|nu)\b|\bdinner\s*che\b|\blunch\s*che\b|\btoday\W*s\s*(menu|special)\b/iu';

    private const RE_GREETING = '/\bjai\s*swaminarayan\b|\bjsk\b|\bjai\s*shri?\s*krishna\b|\bkem\s*ch?o\b|\bkaise\s*ho\b|\bkese\s*ho\b|\bnamaste\b|\bnamaskar\b|\bram\s*ram\b|\bsalaam\b|\bsalam\b|\bgood\s*(morning|afternoon|evening|night)\b/iu';

    private const RE_SOCIAL = '/\bbhabhi\b|\bbhabi\b|\bbhaiya\b|\bbhai\b|\bsir\b|\bmadam\b|\baa\s*tam\w*\s*chh?e\b|\btam\w*\s*chh?e\b|\bthank\w*\b|\btnx\b|\bthnx\b|\bwelcome\b|\bcongratulat\w*\b|\bnice\b|\bsorry\b|\bhaan?\b|\bji\b|\bok+\b|\bokay\b|\bhmm+\b|\byes\b|\bno\b/iu';

    public static function classify(string $text, array $ctx = []): array
    {
        $raw = trim($text);
        $s   = mb_strtolower($raw);

        if ($s === '' || preg_match('/^[\p{So}\p{Sk}\p{P}\s]*$/u', $s)
            || in_array($s, ['🎤 voice message', 'voice message', 'photo', 'image', 'video', 'sticker'], true)) {
            return self::pack(self::SOCIAL);
        }
        if (preg_match('#https?://\S*(mycloudbss|palssnack)#i', $s)) {
            return self::pack(self::UNKNOWN);
        }

        // Peel a leading greeting and re-read the remainder.
        $greeted = (bool) preg_match(self::RE_LEAD_GREET, $s);
        $work = $greeted ? trim(preg_replace(self::RE_LEAD_GREET, '', $s)) : $s;
        $coreLetters = trim(preg_replace('/[^\p{L}]+/u', ' ', $work));
        if ($greeted && $coreLetters === '') {
            return self::pack(self::GREETING);
        }

        if (preg_match(self::RE_HUMAN, $work))    return self::pack(self::HUMAN);
        if (preg_match(self::RE_CONFIRM, $work))  return self::pack(self::CONFIRM);
        if (preg_match(self::RE_REMOVAL, $work))  return self::pack(self::REMOVAL, self::product($work));
        if (preg_match(self::RE_ADDITION, $work)) return self::pack(self::ADDITION, self::product($work));
        if (preg_match(self::RE_QTY, $work, $qm)) return self::pack(self::QUANTITY, self::product($work), self::parseQty($qm));
        if (preg_match(self::RE_PRICE, $work))    return self::pack(self::PRICE, self::product($work));
        if (preg_match(self::RE_DELIVERY, $work)) return self::pack(self::DELIVERY);
        if (preg_match(self::RE_MENU, $work))     return self::pack(self::MENU);

        // 9 greeting > 10 social > 11 product > 12 unknown — but only when nothing is being ordered.
        $prod = self::product($work);
        if ($prod === null) {
            if ($greeted || preg_match(self::RE_GREETING, $work)) return self::pack(self::GREETING);
            if (preg_match(self::RE_SOCIAL, $work))               return self::pack(self::SOCIAL);
            return self::pack(self::UNKNOWN);
        }
        return self::pack(self::PRODUCT_SEARCH, $prod);
    }

    private static function pack(string $intent, ?string $product = null, ?int $qty = null): array
    {
        return ['intent' => $intent, 'product' => $product, 'qty' => $qty];
    }

    private static function parseQty(array $m): ?int
    {
        $words = ['one'=>1,'two'=>2,'three'=>3,'four'=>4,'five'=>5,'six'=>6,'seven'=>7,'eight'=>8,'nine'=>9,'ten'=>10,'ek'=>1,'be'=>2,'tran'=>3,'char'=>4,'paanch'=>5];
        if (! empty($m[5]) && isset($words[mb_strtolower($m[5])])) return $words[mb_strtolower($m[5])];
        foreach ([1, 3, 4] as $g) {
            if (! empty($m[$g])) {
                $n = (int) round((float) str_replace([',', '/'], ['.', '.'], $m[$g]));
                return $n > 0 ? $n : null;
            }
        }
        return null;
    }

    private static function product(string $s): ?string
    {
        $t = $s;
        $t = preg_replace('/\b\d+(?:[.,\/]\d+)?\s*(kgs?|kilos?|gms?|grams?|gm|pcs?|pieces?|pkts?|packets?|dish(?:es)?|plates?|thalis?|nos?|boxes?|box|dozen)\b/iu', ' ', $t);
        $t = preg_replace('/\b\d+\s*x\b|\b\d+\b/u', ' ', $t);
        $fillers = '\b(please|pls|kindly|i|we|need|want|give|me|some|the|a|an|of|do|you|have|got|any|'
            . 'add|karna|karo|also|bring|and|ane|aur|bhi|with|this|that|saathe|sathe|jab|aavu|tab|'
            . 'confirm|order|my|list|take|good|morning|evening|afternoon|night|today|tomorrow|at|for|to|delay|late|'
            . 'price|amount|total|cost|rate|bill|how|much|menu|ok|okay|yes|no|thanks|thank|'
            . 'kem|cho|kaise|ho|namaste|namaskar|ram|jai|swaminarayan|shri|krishna|salaam|salam|'
            . 'bhai|bhabhi|bhabi|bhaiya|sir|madam|nice|sorry|welcome|congratulations|hmm|ji|haan|haa|'
            . 'aa|tamaru|tamru|che|chhe|nathi|lavanu|levanu|joiye|remove|cancel|hi|hii|hello|hey|hlo)\b';
        $t = preg_replace('/' . $fillers . '/iu', ' ', $t);
        $t = preg_replace('/[^\p{L}\s]/u', ' ', $t);
        $t = trim(preg_replace('/\s+/u', ' ', $t));
        if ($t === '') return null;

        if (($c = ProductAlias::canonical($t)) !== null) return $c;
        $words = preg_split('/\s+/u', $t, -1, PREG_SPLIT_NO_EMPTY);
        if (count($words) >= 2 && ($c = ProductAlias::canonical(implode(' ', array_slice($words, 0, 2)))) !== null) return $c;
        if (($c = ProductAlias::canonical($words[0])) !== null) return $c;

        if (count($words) <= 4 && preg_match('/[a-z]/i', $t)) return $t;
        return null;
    }
}
