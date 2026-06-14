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
    public const SHOP_START  = 'shop_start';
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

        // "Have an order to make", "need groceries", "want to shop" -> a shopping-start prompt,
        // never a product search. Whole-message only, so "I want to order rice" still searches.
        if (self::isShopStart($lc)) return self::SHOP_START;

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

        // 1) a word matches a catalogue word — but only a STRONG signal when the message
        //    reads like a product request, not a question/sentence that merely mentions one
        //    ("how do I identify your DELIVERY guy", "how FAST will I receive my GOODS").
        //    Real availability/add requests ("do you have rice", "i want rice", "2kg sugar")
        //    still fire via the qty+unit and shop-verb rules below, so nothing real is lost.
        if (! self::isConversational($lc) && ! self::looksLikeSentence($lc)) {
            foreach ($content as $w) {
                if (isset($tokenSet[$w])) return true;
                if (mb_strlen($w) > 3 && str_ends_with($w, 's') && isset($tokenSet[rtrim($w, 's')])) return true;
            }
        }
        // 2) a quantity + unit (e.g. "2kg", "500 ml") — a real size, not "10 sec"
        if (preg_match('/\b\d+\s*(kgs?|gms?|grams?|g|mg|ml|cl|ltrs?|lt|litres?|liters?|l|pcs?|pkts?|packs?|packets?|dozen|btls?|bottles?|tins?|jars?)\b/', $lc)) {
            return true;
        }
        // 3) an explicit shopping verb together with some content
        if ($content && self::matchesAny($lc, self::SHOP_VERBS)) return true;

        // 4) a complaint about missing stock — "you don't have/stock/sell X" -> search X
        if (preg_match('/\b(you|u|ya)\s+(do(n\'?t| not)|dont)\s+(have|stock|sell|got|carry|keep)\b/', $lc)) return true;

        return false;
    }

    private static function hasWeakProductSignal(string $lc, string $raw): bool
    {
        $content = self::contentWords($raw);
        // 4) a SHORT bare term that isn't conversational — likely a product or a typo;
        //    let the engine try (its fuzzy match is a typo-fallback, returns nothing if no hit).
        return $content && count($content) <= 3 && ! self::isConversational($lc) && ! self::looksLikeSentence($lc);
    }

    private static function isConversational(string $lc): bool
    {
        return self::isGreeting($lc) || self::isThanks($lc) || self::isFeedback($lc)
            || self::isQuestion($lc) || self::isDecline($lc) || self::isHumanAgent($lc);
    }

    /**
     * A natural-language sentence (vs a product term), so a catalogue word inside it is
     * incidental — "how do I identify your DELIVERY guy" must not become a product search.
     */
    private static function looksLikeSentence(string $lc): bool
    {
        $n = count(self::words($lc));
        if ($n >= 5) return true;
        return $n >= 3 && (bool) preg_match('/\b(you|your|ur|we|our|they|them|hope|ready|serious|trust|scam|scums|scum|please|sure|okay|maybe|will|would|should|how|why|when|who|receive|received|identify|coming|arrive|arriving)\b/', $lc);
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
     * Whole-message "I want to start shopping" intent ("have an order to make", "need groceries",
     * "want to shop") — NOT a product. Returns true only when the entire message is such a phrase,
     * so "I want to order rice" still routes to a product search.
     */
    private static function isShopStart(string $lc): bool
    {
        $t = trim(preg_replace('/[^a-z\s]+/', ' ', mb_strtolower($lc)));
        $t = trim(preg_replace('/\s+/', ' ', $t));
        // strip trailing filler
        foreach (['now', 'today', 'please', 'pls', 'here', 'online', 'from you', 'with you', 'first'] as $f) {
            $t = trim(preg_replace('/\s+' . preg_quote($f, '/') . '$/', '', $t));
        }
        if ($t === '') return false;

        $phrases = [
            // have / make an order
            'have an order to make', 'i have an order to make', 'have an order', 'i have an order',
            'got an order to make', 'have order to make', 'i have order to make', 'have an order to place',
            'i want to make an order', 'want to make an order', 'i would like to make an order', 'make an order',
            'i want to make order', 'i have an order to make please',
            // place an order
            'i want to place an order', 'want to place an order', 'place an order', 'can i place an order',
            'could i place an order', 'may i place an order', 'i would like to place an order',
            'would like to place an order', 'i need to place an order', 'let me place an order',
            'i wish to place an order', 'how do i place an order', 'how can i place an order',
            // order (verb, no product)
            'can i order', 'could i order', 'may i order', 'i want to order', 'want to order',
            'i need to order', 'need to order', 'let me order', 'i would like to order',
            'would like to order', 'i wish to order', 'i want to order items', 'i want to order something',
            'i would like to order something', 'i want to order some items',
            // shop
            'want to shop', 'i want to shop', 'can i shop', 'let me shop', 'i would like to shop',
            'would like to shop', 'start shopping', 'begin shopping', 'i want to do shopping',
            'want to do shopping', 'do shopping', 'i want to do some shopping', 'lets shop', 'let s shop',
            'lets go shopping', 'i want to start shopping', 'wanna shop', 'i wanna shop', 'i want shopping',
            // buy
            'i want to buy', 'want to buy', 'i need to buy', 'need to buy', 'can i buy',
            'i would like to buy', 'i want to buy something', 'want to buy something',
            'need to buy something', 'need to buy items', 'need to buy some items', 'i want to buy items',
            'want to buy stuff', 'i need to buy stuff', 'i want to buy some items', 'i want to buy groceries',
            // groceries / supplies / stock / products
            'need groceries', 'i need groceries', 'want groceries', 'i want groceries', 'buy groceries',
            'get groceries', 'order groceries', 'need some groceries', 'i need some groceries',
            'i want to buy groceries', 'buy some groceries',
            'need supplies', 'i need supplies', 'need stock', 'i need stock', 'need provisions',
            'i need provisions', 'need some supplies',
            'i need products', 'need products', 'i need items', 'need items', 'need some items',
            'i need some items', 'need things', 'need stuff', 'i need stuff', 'i need some products',
        ];

        return in_array($t, $phrases, true);
    }

    /**
     * Extract the area from a delivery-price question: "how much to Ntinda",
     * "how much delivery to Kisaasi", "delivery to Bugolobi", "what's the fee to Mukono".
     * Returns the area string, or null.
     */
    public static function deliveryArea(string $lc): ?string
    {
        $lc = trim(preg_replace('/\s+/', ' ', mb_strtolower($lc)));
        // must carry a PRICE cue ("how much" / fee / charge / cost / rate) — a bare
        // "deliver to X" is the customer stating their location, not a price question.
        $pats = [
            '/^how much\b.*\bto\s+(.+?)\??$/',
            '/\b(?:delivery|deliver)\s+(?:fee|charge|cost|rate|price)\s+to\s+(.+?)\??$/',
            '/^(?:whats|what is|what s)\s+(?:the\s+)?(?:delivery\s+)?(?:fee|charge|cost|rate|price)\s+to\s+(.+?)\??$/',
            '/\b(?:fee|charge|cost|rate)\s+(?:of delivery\s+)?to\s+(.+?)\??$/',
        ];
        foreach ($pats as $re) {
            if (preg_match($re, $lc, $m)) {
                $area = trim($m[1]);
                $area = trim(preg_replace('/\b(today|now|please|pls|for me|area|town)\b/', '', $area));
                $area = trim(preg_replace('/\s+/', ' ', $area));
                if ($area !== '' && mb_strlen($area) >= 2 && ! preg_match('/\bdelivery\b/', $area)) return $area;
            }
        }
        return null;
    }

    /** "Can I send a location pin?", "share location", "how do I send my location". */
    public static function isLocationHelp(string $lc): bool
    {
        $t = trim(preg_replace('/[^a-z\s]/', ' ', mb_strtolower($lc)));
        $t = trim(preg_replace('/\s+/', ' ', $t));
        if (preg_match('/\b(send|share|drop|use|give|attach)\b.*\b(location|pin|gps|map)\b/', $t)) return true;
        if (preg_match('/\b(location|pin)\b.*\b(send|share|how|where)\b/', $t)) return true;
        return in_array($t, ['share location', 'location', 'my location', 'location pin', 'send location',
            'share my location', 'send my location', 'share pin', 'send pin'], true);
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
        return (bool) preg_match('/^(what|when|where|why|which|how|who)\b/', $lc)
            || (bool) preg_match('/^(do|does|did|are|is|was|were|can|could|will|would|should|have|has)\s+(i|we|you|u|ya|they|it|there|my|your|the)\b/', $lc)
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
        if (preg_match('/\b(are you|you|do you|do u|are u|u)\s+(?:do\s+|offer\s+|have\s+|doing\s+)?deliver(?:ies|ing|y)?\b/', $lc)
            || preg_match('/\bdeliver(?:ies|y|ing)?\s+(?:today|now|available)\b/', $lc)
            || preg_match('/\bdo you do deliver(?:ies|y|ing)?\b/', $lc)
            || preg_match('/\bdelivery\s+(fee|charge|cost|price|rate)\b/', $lc)
            || preg_match('/\bhow much.{0,15}\bdelivery\b/', $lc)
            || self::deliveryArea($lc) !== null) {           // "how much to Ntinda", "delivery to X"
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
        // Order status & "who's delivering / how do I identify the rider" -> a status answer,
        // never a product search. Scoped to the customer's OWN order / rider identity, so a
        // general "how long for delivery" stays a normal question.
        if (preg_match('/\b(where|how\'?s|status of|track)\b.{0,24}\b(my |the |this )?(order|delivery|parcel|package)\b/', $lc)
            || preg_match('/\b(my|the|this)\s+(order|delivery|parcel|package)\b.{0,24}\b(coming|arrive|arriving|ready|on the way|where|status)\b/', $lc)
            || preg_match('/\bwhen will (i|my|it)\b.{0,20}\b(arrive|come|get|receive|deliver)/', $lc)
            || preg_match('/\bhow (fast|long|soon)\b.{0,20}\b(i .{0,12}(receive|get)|receive my|get my|my (order|goods|delivery))\b/', $lc)
            || preg_match('/\bwho(?:\'?s| is| s)?\s+(deliver\w*|bring\w*)/', $lc)
            || preg_match('/\bidentify\b.{0,20}(deliver\w*|rider)/', $lc)
            || preg_match('/\bdelivery (guy|man|person|driver|rider|boy)\b/', $lc)) {
            return 'status';
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
