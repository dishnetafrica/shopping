<?php
namespace App\Services\Bot;

/**
 * Decides whether an unmatched message is a genuine "we don't stock X" product miss,
 * or just social chatter / a greeting / an emoji message that the bot must NOT echo back
 * as a product. Pure (no framework) so it is unit-tested in qa/product_miss.php.
 *
 * Built for numbers that double as general day-to-day chat (e.g. Pal's, used in a
 * neighbours' WhatsApp group in Gujlish): a message like "Congratulations 👏👏" must
 * never produce "Sorry, we don't stock *congratulations 👏👏*".
 */
class ProductMiss
{
    /** Broad emoji / pictograph / dingbat ranges. */
    private const EMOJI = '/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}\x{2190}-\x{21FF}\x{2B00}-\x{2BFF}\x{FE0F}\x{200D}\x{2122}\x{2139}\x{203C}\x{2049}]/u';

    /** Greetings, well-wishes, smalltalk, acknowledgements — English + common Gujlish. Never a product. */
    private const SOCIAL = '/\b(hi|hii|hey|hello|helo|hiya|yo|hola|salaam|salam|namaste|namaskar|'
        . 'congratulations|congrats|congratulation|welcome|thanks|thank|thankyou|tnx|ty|'
        . 'please|pls|ok|okay|okk|kk|fine|good|morning|afternoon|evening|night|gm|gn|'
        . 'happy|birthday|anniversary|wish|wishes|blessed|blessing|blessings|mubarak|eid|diwali|holi|christmas|newyear|'
        . 'nice|cool|super|great|greatt|awesome|amazing|wonderful|excellent|lovely|beautiful|fantastic|wow|'
        . 'sorry|bye|goodbye|cheers|love|miss|take|care|well|done|congrats|'
        . 'photo|image|video|sticker|gif|forwarded|'
        . 'kem|cho|chho|majama|maja|saras|sars|barabar|sachu|saachu|haji|haa|haan|haanji|naa|na|kai|nai|nathi|che|chee|'
        . 'will|would|can|could|shall|are|is|am|was|were|you|your|u|ur|when|what|whats|why|how|who|where|'
        . 'tomorrow|today|tonight|yesterday|now|later|soon|coming|come|reach|open|closed?|'
        . 'deliver|delivery|time|hours?|there|here|available|reply|call|phone|number|whatsapp|wa|'
        . 'confirm|confirmed|dish|dishes|plate|plates|paid|pay|payment|money|balance|sent|send|received|noted|noted)\b/i';

    /**
     * @return string|null  Cleaned product term to announce as out of stock, or null if the
     *                      message is social / a greeting / emoji and must not be echoed.
     */
    public static function term(string $text): ?string
    {
        // Any emoji in the message => treat as social; never announce a stock miss.
        if (preg_match(self::EMOJI, $text)) {
            return null;
        }

        $want = mb_strtolower($text);
        // Strip common product-seeking lead-ins so "do you have paneer" -> "paneer".
        $want = preg_replace('/\b(do you (have|sell|stock)|have you got|got any|any|looking for|i (want|need)|please|pls)\b/iu', ' ', $want);
        // Drop punctuation / symbols, collapse whitespace.
        $want = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $want);
        $want = trim(preg_replace('/\s+/u', ' ', $want));

        if ($want === '') {
            return null;
        }

        $words = preg_split('/\s+/u', $want, -1, PREG_SPLIT_NO_EMPTY);

        // Announce a miss only for a short, alphabetic, non-social term (a plausible product).
        if (count($words) <= 3
            && preg_match('/[a-z]/i', $want)
            && ! preg_match(self::SOCIAL, $want)) {
            return $want;
        }

        return null;
    }
}
