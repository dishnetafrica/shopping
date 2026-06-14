<?php

namespace App\Services\Bot;

/**
 * Multilingual greeting + small-talk recognition. Pure & static.
 *
 * Matches the WHOLE message (after light filler stripping) so a bare greeting like "Habari"
 * is recognised, while "Habari salt 1kg" still falls through to product search.
 *
 * detect() returns ['lang' => en|sw|lg|ar|in, 'kind' => greet|smalltalk] or null.
 * Language drives a localised reply; the caller never product-searches a matched greeting.
 *
 * Buckets are deliberately data-only so they can later be made configurable per tenant/country
 * (Uganda, Kenya, Tanzania, South Sudan, Rwanda, …).
 */
class GreetingDictionary
{
    private const SETS = [
        // Swahili (Kenya / Tanzania / Uganda)
        'sw' => ['habari', 'habari yako', 'habari gani', 'habari za leo', 'habari ya leo', 'habari zako',
            'mambo', 'mambo vipi', 'poa', 'poa sana', 'sasa', 'sasa za', 'hujambo', 'sijambo',
            'shikamoo', 'marahaba', 'karibu', 'niaje', 'vipi', 'salama', 'jambo', 'habari za asubuhi',
            'umeze ute', 'umeze', 'umezeute', 'umeamkaje', 'umeshindaje', 'mzima'],

        // Luganda (Uganda)
        'lg' => ['oli otya', 'oli otya nno', 'oli otya nnyo', 'oli otyano', 'wasuze otya', 'wasuze otyano',
            'gyebale', 'gyebale ko', 'gyebaleko', 'agandi', 'osiibye otya', 'osiibye otyano', 'kyokka'],

        // Juba Arabic / Arabic (South Sudan) — romanised + Arabic script
        'ar' => ['salam', 'salaam', 'salam alaikum', 'assalam alaikum', 'assalamu alaikum', 'asalaam alaikum',
            'as salam alaikum', 'salamu alaikum', 'marhaba', 'marhaban', 'keif halak', 'kef halak',
            'keif halek', 'kaif halak', 'sabah al khair', 'sabah el kheir', 'sabah alkhair',
            'masa al khair', 'masa el kheir', 'ahlan', 'ahlan wa sahlan',
            'سلام', 'السلام عليكم', 'سلام عليكم', 'مرحبا', 'مرحبا بك', 'اهلا', 'اهلا وسهلا', 'اهلا بك',
            'صباح الخير', 'مساء الخير', 'صباح النور', 'مساء النور', 'كيف حالك', 'كيفك', 'كيف الحال'],

        // India (Gujarati / Hindi — for the shop's Indian customers)
        'in' => ['namaste', 'namaskar', 'jai shree krishna', 'jai shri krishna', 'jsk', 'ram ram', 'jai shri ram'],

        // English / generic openers
        'en' => ['hi', 'hii', 'hiii', 'hey', 'heyy', 'heya', 'hello', 'helo', 'hullo', 'yo', 'hiya', 'hola',
            'start', 'greetings', 'good morning', 'good afternoon', 'good evening', 'good day',
            'gud morning', 'gud evening', 'morning', 'evening', 'hey there', 'hello there', 'hi there'],
    ];

    private const SMALLTALK = ['how are you', 'how r u', 'how are u', 'how are ya', 'hw are you', 'hw r u',
        'how is it going', 'hows it going', 'how is everything', 'how are things', 'you there',
        'are you there', 'r u there', 'u there', 'how do you do', 'are u there'];

    // honorifics / fillers stripped from the end before a second lookup
    private const TRAIL_FILLER = ['ssebo', 'sebo', 'nyabo', 'sir', 'madam', 'bwana', 'boss', 'there',
        'friend', 'mukwano', 'please', 'po', 'oli', 'nno', 'nnyo', 'ko', 'man', 'bro', 'team', 'all'];

    public static function detect(string $text): ?array
    {
        $t = self::norm($text);
        if ($t === '') return null;

        foreach (self::SETS as $lang => $set) {
            if (in_array($t, $set, true)) return ['lang' => $lang, 'kind' => 'greet'];
        }
        if (in_array($t, self::SMALLTALK, true)) return ['lang' => 'en', 'kind' => 'smalltalk'];

        // strip trailing honorifics/fillers and retry (e.g. "oli otya ssebo", "hi there", "hello sir")
        $stripped = $t;
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach (self::TRAIL_FILLER as $f) {
                $new = preg_replace('/\s+' . preg_quote($f, '/') . '$/', '', $stripped);
                if ($new !== $stripped) { $stripped = trim($new); $changed = true; }
            }
        }
        if ($stripped !== $t && $stripped !== '') {
            foreach (self::SETS as $lang => $set) {
                if (in_array($stripped, $set, true)) return ['lang' => $lang, 'kind' => 'greet'];
            }
            if (in_array($stripped, self::SMALLTALK, true)) return ['lang' => 'en', 'kind' => 'smalltalk'];
        }

        return null;
    }

    public static function isGreeting(string $text): bool
    {
        return self::detect($text) !== null;
    }

    private static function norm(string $s): string
    {
        $s = trim($s);
        // Arabic normalisation: drop harakat (diacritics) + tatweel, unify alef/ya variants
        $s = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s);
        $s = preg_replace('/[\x{0623}\x{0625}\x{0622}]/u', "\u{0627}", $s); // أ إ آ -> ا
        $s = preg_replace('/\x{0649}/u', "\u{064A}", $s);                    // ى -> ي
        $s = mb_strtolower($s);
        // keep letters/numbers of ANY script (Latin, Arabic, …) + spaces
        $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s);
        return trim(preg_replace('/\s+/u', ' ', $s));
    }
}
