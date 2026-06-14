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
    public const CANCEL      = 'cancel';
    public const DECLINE     = 'decline';
    public const HUMAN_AGENT = 'human_agent';
    public const CHECKOUT    = 'checkout';
    public const CART        = 'cart';
    public const UNKNOWN     = 'unknown';

    private const GREETINGS = ['hi','hii','hey','hello','helo','yo','hiya','start','menu','hola',
        'good morning','good afternoon','good evening','good day','jsk','jai shree krishna',
        'namaste','namaskar','salaam','salam','assalam','how are you','how r u','how are u','hru',
        'whats up','what\'s up','sup','wassup','good day to you'];

    private const THANKS_WORDS = ['thanks','thank you','thank u','thanx','thankyou','thx','ty','asante',
        'asante sana','dhanyavaad','dhanyavad','shukran','appreciate it','appreciated','much appreciated',
        'thank you so much','thanks a lot','many thanks'];

    private const PRAISE = ['good','great','nice','better','best','improved','improving','improvement',
        'fast','faster','quick','quicker','speedy','perfect','excellent','awesome','amazing','smooth',
        'love it','loved','impressive','well done','works','working','wonderful','fantastic','superb',
        'cool','brilliant','helpful'];

    private const SHOP_VERBS = ['add','buy','order','purchase','want to buy','looking for','do you have',
        'do you sell','i need','i want','gimme','give me','get me','send me','bring me','deliver'];

    public static function classify(string $text, array $catalogueTokenSet = []): string
    {
        $lc = self::norm($text);
        if ($lc === '') return self::UNKNOWN;

        // Explicit commands / exits win first.
        if (self::isDecline($lc))    return self::DECLINE;
        if (self::isHumanAgent($lc)) return self::HUMAN_AGENT;
        if (self::isCheckout($lc))   return self::CHECKOUT;
        if (self::isCart($lc))       return self::CART;

        // Genuine shopping signal -> search is allowed (incl. typo fuzzy in the engine).
        if (self::hasProductSignal($text, $lc, $catalogueTokenSet)) return self::SHOPPING;

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

    private static function hasProductSignal(string $raw, string $lc, array $tokenSet): bool
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
        // 4) a SHORT bare term that isn't conversational — likely a product or a typo;
        //    let the engine try (its fuzzy match is a typo-fallback, returns nothing if no hit).
        if ($content && count($content) <= 3 && ! self::isConversational($lc)) return true;

        return false;
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
