<?php

namespace App\Services\Bot;

/**
 * Deterministic intent classification that runs BEFORE any catalogue search.
 * The whole point: the bot should behave like a shop assistant, not a search
 * engine — conversational messages (greeting / thanks / feedback / question)
 * must never trigger a product search.
 *
 * classify() returns one of the intent constants. SHOPPING is only returned when
 * there is a genuine shopping signal (an exact catalogue word, a quantity/size, a
 * shopping verb, or a short bare term that is plausibly a product/typo) — never
 * just because a word partially matches a product.
 */
final class IntentClassifier
{
    public const SHOPPING    = 'shopping';
    public const GREETING    = 'greeting';
    public const FEEDBACK    = 'feedback';
    public const THANKS      = 'thanks';
    public const QUESTION    = 'question';
    public const PRICE       = 'price';
    public const CANCEL      = 'cancel';
    public const DECLINE     = 'decline';
    public const HUMAN_AGENT = 'human_agent';
    public const CHECKOUT    = 'checkout';
    public const CART        = 'cart';
    public const CATALOG     = 'catalog';
    public const CATEGORY    = 'category';
    public const BUSINESS    = 'business';
    public const LOCATION    = 'location';
    public const UNKNOWN     = 'unknown';

    private const GREETINGS = ['hi','hii','hey','hello','helo','yo','hiya','start','menu','hola',
        'good morning','good afternoon','good evening','good day','jsk','jai shree krishna',
        'namaste','namaskar','salaam','salam','assalam','how are you','how r u','how are u','hru',
        'whats up','what\'s up','sup','wassup','good day to you'];

    private const THANKS_WORDS = ['thanks','thank you','thank u','thanx','thankyou','thx','ty','asante',
        'asante sana','dhanyavaad','dhanyavad','shukran','appreciate it','appreciated','much appreciated',
        'thank you so much','thanks a lot','many thanks','webale','webale nyo','webale ko','mwebale'];

    private const PRAISE = ['good','great','nice','better','best','improved','improving','improvement',
        'fast','faster','quick','quicker','speedy','perfect','excellent','awesome','amazing','smooth',
        'love it','loved','impressive','well done','works','working','wonderful','fantastic','superb',
        'cool','brilliant','helpful'];

    private const SHOP_VERBS = ['add','buy','order','purchase','want to buy','looking for','do you have',
        'do you sell','i need','i want','gimme','give me','get me','send me','bring me'];

    public static function classify(string $text, array $catalogueTokenSet = []): string
    {
        $lc = self::norm($text);
        if ($lc === '') return self::UNKNOWN;

        // Explicit commands / exits win first.
        if (self::isDecline($lc))    return self::DECLINE;
        if (self::isHumanAgent($lc)) return self::HUMAN_AGENT;
        if (self::isCheckout($lc))   return self::CHECKOUT;
        if (self::isCart($lc))       return self::CART;

        // Business questions ("are you open?", "what time do you close?", "delivering today?")
        // -> a business answer, never a product search.
        if (self::isBusiness($lc))   return self::BUSINESS;

        // "menu" / "catalog" / "price list" -> show the catalogue, never product-search.
        if (self::isCatalog($lc))    return self::CATALOG;

        // A bare category term ("spirits", "snacks") -> category listing, not a raw search.
        if (CategoryDictionary::isCategory($text)) return self::CATEGORY;

        // Multilingual greeting / small-talk (whole-message) -> GREETING, never a product search.
        // Must run before the strong-signal check so "Habari" isn't matched to "Habari Salt".
        if (GreetingDictionary::isGreeting($text)) return self::GREETING;

        // "how much is X" / "price of X" -> answer the price, never silently add to cart.
        if (self::priceQuery($lc) !== null) return self::PRICE;

        // A STRONG shopping signal (catalogue word / qty+unit / shop verb) wins outright.
        if (self::hasStrongProductSignal($text, $lc, $catalogueTokenSet)) return self::SHOPPING;

        // A delivery location (known Kampala/Juba area, or a cue + landmark) must be
        // recognised as a LOCATION and never product-searched — even a bare area name.
        if (LocationDictionary::looksLikeLocation($text)) return self::LOCATION;

        // A short bare term that isn't conversational -> likely a product or a typo;
        // let the engine try (its fuzzy match is a typo-fallback, returns nothing if no hit).
        if (self::hasWeakProductSignal($lc, $text)) return self::SHOPPING;

        // Otherwise it's conversational — never search.
        if (self::isThanks($lc))   return self::THANKS;
        if (self::isGreeting($lc)) return self::GREETING;
        if (self::isFeedback($lc)) return self::FEEDBACK;
        if (self::isQuestion($lc)) return self::QUESTION;

        return self::UNKNOWN;
    }

    /** Build a set of catalogue word-tokens (for the product-signal check). */
    public static function tokenSetFromProducts(array $products): array
    {
        $set = [];
        foreach ($products as $p) {
            $blob = ($p['name'] ?? '') . ' ' . ($p['keywords'] ?? '') . ' ' . ($p['category'] ?? '');
            foreach (self::words($blob) as $t) $set[$t] = true;
        }
        return $set;
    }

    // ---- signal detection -------------------------------------------------

    private static function hasStrongProductSignal(string $raw, string $lc, array $tokenSet): bool
    {
        $content = self::contentWords($raw);

        // 1) any word matches a catalogue word exactly (singular/plural)
        foreach ($content as $w) {
            if (isset($tokenSet[$w])) return true;
            if (mb_strlen($w) > 3 && str_ends_with($w, 's') && isset($tokenSet[rtrim($w, 's')])) return true;
        }
        // 2) a quantity + unit (e.g. "2kg", "500 ml") — a real size, not "10 sec"
        if (preg_match('/\b\d+\s*(kgs?|gms?|grams?|g|mg|ml|cl|ltrs?|lt|litres?|liters?|l|pcs?|pkts?|packs?|packets?|dozen|btls?|bottles?|tins?|jars?)\b/', $lc)) {
            return true;
        }
        // 3) an explicit shopping verb together with some content
        if ($content && self::matchesAny($lc, self::SHOP_VERBS)) return true;

        return false;
    }

    private static function hasWeakProductSignal(string $lc, string $raw): bool
    {
        $content = self::contentWords($raw);
        // 4) a SHORT bare term that isn't conversational — likely a product or a typo;
        //    let the engine try (its fuzzy match is a typo-fallback, returns nothing if no hit).
        return $content && count($content) <= 3 && ! self::isConversational($lc);
    }

    private static function isConversational(string $lc): bool
    {
        return self::isGreeting($lc) || self::isThanks($lc) || self::isFeedback($lc)
            || self::isQuestion($lc) || self::isDecline($lc) || self::isHumanAgent($lc);
    }

    // ---- conversational detectors ----------------------------------------

    private static function isGreeting(string $lc): bool
    {
        if (in_array($lc, self::GREETINGS, true)) return true;
        $words = preg_split('/\s+/', $lc) ?: [];
        // a short opener like "hi there", "hello team" — but not a long sentence beginning with "hi"
        if (count($words) <= 4 && in_array($words[0], ['hi','hii','hey','hello','helo','hiya','yo','hola','namaste','salaam','salam'], true)) {
            return true;
        }
        return self::matchesAny($lc, ['good morning','good afternoon','good evening','how are you','how r u','how are u','whats up','what\'s up']);
    }

    private static function isThanks(string $lc): bool
    {
        if (in_array($lc, self::THANKS_WORDS, true)) return true;
        return (bool) preg_match('/\b(thanks|thankyou|thank you|thank u|thanx|shukran|asante|dhanyav)/', $lc);
    }

    private static function isFeedback(string $lc): bool
    {
        return self::matchesAny($lc, self::PRAISE);
    }

    /**
     * If this is a price question ("how much is X", "price of X", "X price"),
     * returns the product part; otherwise null. Delivery-price questions are excluded
     * (those are handled as a business inquiry).
     */
    public static function priceQuery(string $lc): ?string
    {
        $lc = trim(preg_replace('/\s+/', ' ', $lc));
        $pats = [
            '/^how much (?:is|are|for|does|do)\s+(?:a |an |the )?(.+?)(?:\s+cost)?\??$/',
            '/^how much\s+(.+?)\??$/',
            '/^(?:what(?:\'s| is)\s+)?(?:the\s+)?price (?:of|for)\s+(.+?)\??$/',
            '/^(?:what(?:\'s| is)\s+)?(?:the\s+)?cost (?:of|for)\s+(.+?)\??$/',
            '/^(?:what(?:\'s| is)\s+)?(?:the\s+)?rate (?:of|for)\s+(.+?)\??$/',
            '/^(.+?)\s+price\??$/',
        ];
        $reject = ['', 'delivery', 'the delivery', 'delivery fee', 'shipping', 'transport',
            'what', 'whats', 'how', 'much', 'is', 'are', 'the', 'a', 'an', 'it', 'this', 'that', 'them'];
        foreach ($pats as $re) {
            if (preg_match($re, $lc, $m)) {
                $prod = trim($m[1]);
                if (in_array($prod, $reject, true)) return null;
                if (preg_match('/^(what|whats|how|much|is|are|the|please|tell me)\b/', $prod)) return null;
                if (preg_match('/\bdelivery\b/', $prod)) return null;
                return $prod;
            }
        }
        return null;
    }

    private static function isQuestion(string $lc): bool
    {
        if (str_ends_with(trim($lc), '?')) return true;
        return (bool) preg_match('/^(do|does|are|is|can|could|will|would|what|when|where|why|which|how)\b/', $lc)
            || self::matchesAny($lc, ['are you open','do you deliver','what time','opening hours','where are you','how long','how much is delivery']);
    }

    private static function isDecline(string $lc): bool
    {
        $exact = ['no','nope','nah','cancel','stop','nothing','none','no thanks','no thank you',
            'not interested','forget it','never mind','nevermind','thats all','that\'s all',
            'im good','i\'m good','nahi','kuch nahi','no more','that is all'];
        if (in_array($lc, $exact, true)) return true;
        $stripped = preg_replace('/[^a-z\s]/', '', $lc);
        return (bool) preg_match('/\b(dont|do not|not)\s+want\b/', $stripped)
            || str_contains($lc, 'not interested')
            || str_contains($lc, 'forget it')
            || str_contains($lc, 'never mind');
    }

    private static function isHumanAgent(string $lc): bool
    {
        return (bool) preg_match('/\b(human|agent|representative|operator|customer (care|service)|real person)\b/', $lc)
            || (bool) preg_match('/\b(talk|speak|chat)\s+(to|with)\s+(a |an |the )?(person|someone|human|agent|staff|manager|somebody)\b/', $lc)
            || (bool) preg_match('/\bcall me\b/', $lc);
    }

    private static function isCheckout(string $lc): bool
    {
        return in_array($lc, ['checkout','check out','done','confirm','order','place order','proceed','proceed to checkout','finish','complete order'], true);
    }

    private static function isCart(string $lc): bool
    {
        return in_array($lc, ['cart','basket','my cart','my order','my basket','view cart','show cart'], true);
    }

    private static function isCatalog(string $lc): bool
    {
        return (bool) preg_match('/\b(menu|catalog|catalogue|price\s?list|product\s?list|products\s?list)\b/', $lc)
            || self::matchesAny($lc, ['what do you have', 'what do you sell', 'what all do you have',
                'everything you have', 'show me everything', 'list of products', 'show all products',
                'all your products', 'see all products']);
    }

    /** Returns 'open' | 'delivery' | 'location' | 'general' for a business question, else ''. */
    public static function businessKind(string $lc): string
    {
        $lc = ' ' . trim($lc) . ' ';
        if (preg_match('/\b(are you|you|do you|are u|u)\s+deliver(?:ing|y)?\b/', $lc)
            || preg_match('/\bdeliver(?:y|ing)?\s+(?:today|now|available)\b/', $lc)
            || preg_match('/\bdo you do delivery\b/', $lc)
            || preg_match('/\bdelivery\s+(fee|charge|cost|price|rate)\b/', $lc)
            || preg_match('/\bhow much.{0,15}\bdelivery\b/', $lc)) {
            return 'delivery';
        }
        if (preg_match('/\b(are you|you|r u|are u)\s+(open|closed|working|available)\b/', $lc)
            || preg_match('/\bopen (?:for orders|today|now)\b/', $lc)
            || preg_match('/\b(still open|opening hours|business hours|working hours|closing time|opening time)\b/', $lc)
            || preg_match('/\bwhat time (?:do you )?(?:open|close)\b/', $lc)
            || preg_match('/\bwhat (?:are|r) your hours\b/', $lc)) {
            return 'open';
        }
        if (preg_match('/\bwhere (?:are you|is your shop|is the shop|are u)\b/', $lc)
            || preg_match('/\byour (?:shop )?location\b/', $lc)) {
            return 'location';
        }
        return '';
    }

    private static function isBusiness(string $lc): bool
    {
        return self::businessKind($lc) !== '';
    }

    // ---- helpers ----------------------------------------------------------

    private static function norm(string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower($s)));
    }

    private static function matchesAny(string $lc, array $needles): bool
    {
        foreach ($needles as $n) {
            if ($n === '') continue;
            // word-ish containment
            if (preg_match('/(^|\W)' . preg_quote($n, '/') . '($|\W)/', $lc)) return true;
        }
        return false;
    }

    /** All alnum words, lowercased. */
    private static function words(string $s): array
    {
        $s = preg_replace('/[^a-z0-9 ]+/', ' ', mb_strtolower($s));
        $out = [];
        foreach (preg_split('/\s+/', $s, -1, PREG_SPLIT_NO_EMPTY) as $t) {
            if (mb_strlen($t) < 2) continue;
            if (in_array($t, CatalogueMatcher::UNITS, true)) continue;
            if (preg_match('/^\d+$/', $t)) continue;
            $out[] = CatalogueMatcher::SYN[$t] ?? $t;
        }
        return $out;
    }

    /** Content words = words minus stop-words (the bits that could name a product). */
    private static function contentWords(string $s): array
    {
        return array_values(array_filter(self::words($s), fn ($t) => ! in_array($t, CatalogueMatcher::STOP, true)));
    }
}
