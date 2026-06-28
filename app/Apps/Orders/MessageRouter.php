<?php
namespace App\Apps\Orders;

/**
 * Lightweight first-pass router (Gujlish-aware, deterministic). Classifies every customer message
 * so only ORDER / ORDER_UPDATE messages reach the Draft Order parser — short social messages
 * ("Ok", "Hello", "Aaje banawana cho?") never become false product lookups. Pure & testable.
 */
class MessageRouter
{
    public const GREETING = 'greeting';
    public const QUESTION = 'question';
    public const ORDER = 'order';
    public const ORDER_UPDATE = 'order_update';
    public const DELIVERY = 'delivery';
    public const PAYMENT = 'payment';
    public const SUPPORT = 'support';
    public const OTHER = 'other';

    private const GREET = ['hello', 'hi', 'hey', 'hii', 'helo', 'good morning', 'good evening', 'good afternoon',
        'morning', 'kem cho', 'kemcho', 'jsk', 'jai swaminarayan', 'namaste', 'ram ram', 'jay shree krishna',
        'thank', 'thanks', 'thank you', 'welcome', 'ok', 'okay', 'k', 'done', 'great', 'bye', 'good night'];
    private const PAY_KW = ['payment', 'pay', 'momo', 'mobile money', 'mtn', 'airtel money', 'cash', 'gpay',
        'online payment', 'paid', 'pmt', 'how to pay', 'paise'];
    private const DELIVERY_KW = ['deliver', 'delivery', 'address', 'location', 'moklu', 'moklo', 'lavanu', 'lavjo',
        'pickup', 'pick up', 'collect', 'kyare aavse', 'coming tomorrow', 'home delivery', 'send it', 'drop'];
    private const UPDATE = ['change', 'remove', 'cancel', 'instead', 'also add', 'add more', 'nathi joiye',
        'nathi lavanu', 'no need', 'make it', 'extra', 'one more'];
    private const SUPPORT_KW = ['problem', 'complaint', 'wrong', 'refund', 'not received', 'late', 'help', 'issue', 'bad'];
    private const UNITS = 'kg|kgs|g|gm|gms|gram|grams|pc|pcs|pkt|packet|packets|plate|dish|dozen|box';

    public function route(string $text): string
    {
        $t = ' ' . mb_strtolower(trim($text)) . ' ';
        $bare = trim($text);

        if ($bare === '') return self::OTHER;
        if ($this->isPriceQuestion($t))         return self::QUESTION;     // "Almond 250gms ketla?" is a price Q, not an order
        if ($this->hasAny($t, self::UPDATE))    return self::ORDER_UPDATE;
        if ($this->looksLikeOrder($t))          return self::ORDER;        // qty+unit or add-verb wins over greeting
        if ($this->hasAny($t, self::GREET) && mb_strlen($bare) <= 25) return self::GREETING;
        if ($this->hasAny($t, self::PAY_KW))    return self::PAYMENT;
        if ($this->hasAny($t, self::DELIVERY_KW)) return self::DELIVERY;
        if ($this->hasAny($t, self::SUPPORT_KW)) return self::SUPPORT;
        if ($this->isQuestion($t, $bare))       return self::QUESTION;
        if ($this->hasAny($t, self::GREET))     return self::GREETING;
        return self::OTHER;
    }

    /** "how much" in Gujlish/English — a price ask, even when a quantity is present. */
    private function isPriceQuestion(string $t): bool
    {
        return (bool) preg_match('/\b(ketla|kitla|ketlo|ketlu|kelu|kitne|kitna|how much|what.?s the price|price\?|rate\?|cost\?)\b/', $t);
    }

    private function looksLikeOrder(string $t): bool
    {
        if (preg_match('/\b\d+\s*(?:' . self::UNITS . ')\b/', $t)) return true;       // "250g", "2 kg", "1 plate"
        if (preg_match('/\b(add|order|want|need|joiye|joye|aapo|apo|lerso|leva|laav|lav)\b/', $t)) return true;
        return false;
    }

    private function isQuestion(string $t, string $bare): bool
    {
        if (str_contains($bare, '?')) return true;
        return (bool) preg_match('/\b(price|rate|cost|kitla|ketla|su che|che\?|available|hoy|have you|do you|kya|menu|list|platter)\b/', $t);
    }

    private function hasAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $n) { if (str_contains($haystack, $n)) return true; }
        return false;
    }
}
