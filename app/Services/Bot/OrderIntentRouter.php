<?php
namespace App\Services\Bot;

/**
 * Order Intent Router (Phase 1 — deterministic, no LLM).
 *
 * Classifies an inbound customer message into ONE shopping intent, in a fixed priority order,
 * BEFORE any catalogue matching runs. Built + tuned from the Pal's Snacks NLU failure audit and
 * a 100-message live replay: most inbound messages are greetings, quantities, confirmations,
 * cancellations and price/delivery/logistics chatter in Gujlish/Hindi — not product names.
 * Catalogue matching (and the only "we don't stock" reply) is reached solely via PRODUCT_SEARCH.
 *
 * Distinct from IntentRouter (lead/ticket/shopping diversion for the sales side). Pure
 * (no framework); unit-tested in qa/intent_router.php and replayed in qa/replay_pals_phase1.php.
 *
 * Priority (highest first):
 *   1 HUMAN  2 CONFIRM  3 REMOVAL  4 ADDITION  5 QUANTITY  6 PRICE
 *   7 DELIVERY  8 MENU  9 GREETING  10 SOCIAL  11 PRODUCT_SEARCH  12 UNKNOWN
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
    public const VISIT          = 'visit';
    public const PRODUCT_SEARCH = 'product_search';
    public const UNKNOWN        = 'unknown';

    private const RE_LEAD_GREET = '/^\s*(jai\s*swaminarayan|jsk|jai\s*shri?\s*krishna|kem\s*ch?o|kaise\s*ho|kese\s*ho|namaste|namaskar|ram\s*ram|salaam|salam|good\s*(?:morning|afternoon|evening|night)|gud\s*(?:mrng|morning)|hi+|hello+|helo+|hey+|hii+|hlo|heloo+|hola)\b[\s,!.]*/iu';

    private const RE_HUMAN = '/\b(call\s*me|call\s*back|ring\s*me|talk\s*to|speak\s*to|customer\s*care|human|agent|representative)\b|\bnumber\s*(nikal|do|dedo|send|aapo|chahiye)\b|\bgroup\s*me(se|n)\s*number\b|\bnumber\b[^.]*\bnikal\s*do\b|\bphone\s*number\b|\bcontact\s*(no|number)\b|\bthis\s*number\b|\bhis\s*number\b|\bmaro\s*number\b|\bnumber\b[^.]*\b(whats?\s*app|app|dedo|send|aapo)\b|\bgive\s*(you|him|his|u)\b[^.]*\bnumber\b/iu';

    private const RE_CONFIRM = '/\b(confirm\w*|confimed|confirmd|cnfirm\w*|finalize|finalise)\b|\b(take|give|place)\s*(my\s*)?order\b|\border\w*\s*(karo|karu|kari|le|lelo|lai\s*lo|done)?\b|\bi\s*made\s*my\s*list\b|\bmy\s*list\b|\bbook\s*(kar|karo)\b|\bdone\s*with\s*(my\s*)?(list|order)\b|\bcheck\s*out\b|\bcheckout\b/iu';

    private const RE_REMOVAL = '/\bnathi\s*lav\w*\b|\bnathi\s*joi\w*\b|\bnai\s*joi\w*\b|\bnahi\s*chahiy\w*\b|\bmat\s*la(o|na)?\b|\bhata(o|do|vo)\b|\bcancel\b|\bremove\b|\bdelete\b|\bkaadi\s*(nakh|do)\w*\b|\bkadi\s*nakh\w*\b|\bnathi\s*levanu\b|\bna\s*joiye\b/iu';

    private const RE_ADDITION = '/\badd\s*(kar\w*|karna|karo|it|this|also|me|kari)?\b|\balso\s*add\b|\bbring\s+.*\balso\b|\b\w+\s+also\b|\bane\s+\w+|\baur\s+\w+|\b\w+\s+(bhi|saathe|sathe)\b|\bjab\s*aavu\s*tab\s*add\b|\bwith\s*this\b|\bema\s*umer\w*\b/iu';

    private const RE_QTY = '/\b(\d+(?:[.,\/]\d+)?)\s*(kgs?|kilos?|gms?|grams?|gm|pcs?|pieces?|pkts?|packets?|dish(?:es)?|plates?|thalis?|nos?|boxes?|box|dozen)\b|\b(\d{3,4})\s*$|\b(\d+)\s*x\b|^\s*(one|two|three|four|five|six|seven|eight|nine|ten|ek|be|tran|char|paanch)\s*$/iu';

    private const RE_PRICE = '/\b(price|prices|amount|total|cost|charges?|rate|bill|paisa|paise)\b|\bketla\s*(thay|thse|na|aave|che|hoy|nu)\b|\bketlo\b|\bketli\b|\bkitna\b|\bkitne\s*(ka|ke)\b|\bhow\s*much\b|\bshu\s*bhav\b|\bbhav\b|\bper\s*kg\b|\b\d+\s*k\s*(per|\/)\s*kg\b/iu';

    private const RE_DELIVERY = '/\bketla\s*vage\b|\bkitne\s*baje\b|\bwhat\s*time\b|\bwhen\s*(will|can|you|are)\b|\bwill\s*you\s*be\s*coming\b|\bare\s*you\s*coming\b|\bcoming\b|\bi\W*m\s*coming\b|\bdeliver\w*\b|\bmokl\w*\b|\bmokal\w*\b|\baavse\b|\baavso\b|\bave\b|\bajao\b|\bkab\s*(aa|tak|aavse)\b|\bleva\s*av\w*\b|\bpick\s*up\b|\bpickup\b|\bcollect\b|\bbhej\s*diy\w*\b|\bbhej\s*do\b|\bdelivery\b|\baddress\b|\blocation\b|\btransport\b|\btomorrow\b|\btommorow\b|\btmrw\b|\btonight\b|\b(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b|\bo?clock\b|\b\d+\s*mnts?\b|\baround\s*\d+\b|\bkoi\s*update\b|\bcan\s*(u|you)\s*send\b|\bat\s+[a-z]+\s*$/iu';

    private const RE_MENU = '/\bmenu\b|\bmanue\b|\bmanu\b|\bmenue\b|\blist\b|\bsu\s*che\b|\bshu\s*che\b|\bkya\s*hai\b|\bwhat\s*do\s*you\s*have\b|\baaj\s*(nu|na|ni)\b|\baje\s*(su|shu|dinner|lunch|nasto|nu|tiffin)\b|\btiffin\b|\bhoi\s*che\w*\b|\bdinner\s*che\b|\blunch\s*che\b|\bleft\s*\?|\bleft\b\s*$|\btoday\W*s\s*(menu|special)\b/iu';

    private const RE_GREETING = '/\bjai\s*swaminarayan\b|\bjsk\b|\bjai\s*shri?\s*krishna\b|\bkem\s*ch?o\b|\bkaise\s*ho\b|\bkese\s*ho\b|\bnamaste\b|\bnamaskar\b|\bram\s*ram\b|\bsalaam\b|\bsalam\b|\bgood\s*(morning|afternoon|evening|night)\b/iu';

    private const RE_SOCIAL = '/\bbhabhi\b|\bbhabi\b|\bbhaiya\b|\bbhai\b|\bsir\b|\bmadam\b|\baa\s*tam\w*\s*chh?e\b|\btam\w*\s*chh?e\b|\bthank\w*\b|\btnx\b|\bthnx\b|\bwelcome\b|\bcongratulat\w*\b|\bnice\b|\bsorry\b|\bhaan?\b|\bji\b|\bok+\b|\bokay+\b|\bnp\b|\bbarabar\b|\bbaraber\b|\bthik\b|\bwahi\b|\bhmm+\b|\byes\b|\bno\b/iu';

    public static function classify(string $text, array $ctx = []): array
    {
        $raw = trim($text);
        $s   = mb_strtolower($raw);

        // Empty / pure-emoji / media-or-file share => social (never product).
        if ($s === '' || preg_match('/^[\p{So}\p{Sk}\p{P}\s]*$/u', $s)
            || preg_match('/[\x{1F4F7}\x{1F4C4}\x{1F4CE}\x{1F3A5}\x{1F3A4}\x{1F5BC}]/u', $raw)
            || preg_match('/\b(photo|image|video|sticker|document)\b/iu', $s)
            || preg_match('/\.(csv|pdf|jpe?g|png|docx?|xlsx?|mp4|ogg)\b/iu', $s)
            || in_array($s, ['🎤 voice message', 'voice message', 'photo', 'image', 'video', 'sticker'], true)) {
            return self::pack(self::SOCIAL);
        }
        if (preg_match('#https?://\S*(mycloudbss|palssnack)#i', $s)) {
            return self::pack(self::UNKNOWN);
        }

        // Gujlish Intent Dictionary V1 — consulted BEFORE regex lexicons and catalogue matching.
        // Known phrases ("kale aavishu" -> visit, "ha barabar che" -> confirm) win here so they
        // are never searched as product names.
        $dict = GujlishDictionary::lookup($raw);
        if ($dict !== null) {
            if (in_array($dict, [self::REMOVAL, self::ADDITION, self::PRICE], true)) {
                return self::pack($dict, self::product($s));
            }
            return self::pack($dict);
        }

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

    /**
     * Extract a candidate product term: strip intent cue-words, quantities, units, conversational
     * fillers and social/Gujlish chatter; alias-resolve the remainder. Returns null when nothing
     * product-shaped remains, so chit-chat ("okkk", "ha baraber che", "sorry i forgot to pay")
     * does NOT leak into product search.
     */
    private static function product(string $s): ?string
    {
        $t = $s;
        $t = preg_replace('/\b\d+(?:[.,\/]\d+)?\s*(kgs?|kilos?|gms?|grams?|gm|pcs?|pieces?|pkts?|packets?|dish(?:es)?|plates?|thalis?|nos?|boxes?|box|dozen)\b/iu', ' ', $t);
        $t = preg_replace('/\b\d+\s*x\b|\b\d+\b/u', ' ', $t);
        $fillers = '\b(please|pls|kindly|i|we|need|want|give|me|some|the|a|an|of|do|you|u|have|got|any|'
            . 'add|karna|karo|also|bring|and|ane|aur|bhi|with|this|that|saathe|sathe|jab|aavu|tab|'
            . 'confirm|confimed|order|my|list|take|good|morning|evening|afternoon|night|today|tomorrow|at|for|to|delay|late|'
            . 'price|amount|total|cost|rate|bill|how|much|menu|ok|okk|okkk|okay|okkk|np|then|hmm|just|keep|'
            . 'kem|cho|kaise|ho|namaste|namaskar|ram|jai|swaminarayan|shri|krishna|salaam|salam|'
            . 'bhai|bhabhi|bhabi|bhaiya|sir|madam|nice|sorry|welcome|congratulations|ji|haan|haa|ha|'
            . 'baraber|barabar|thik|wahi|dedo|koi|update|hope|kaam|hojaaye|forgot|pay|let|his|only|upto|link|asked|what|kg|gm|yes|no|ma|man|plz|s|app|maro|'
            . 'aa|tamaru|tamru|tamaro|che|chhe|chey|kya|nathi|lavanu|levanu|joiye|remove|cancel|hi|hii|hello|hey|hlo)\b';
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
