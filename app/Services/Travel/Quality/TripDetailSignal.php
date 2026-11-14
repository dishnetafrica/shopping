<?php

namespace App\Services\Travel\Quality;

/**
 * Did the customer's message contain a trip detail? A PURE yes/no, decided by fixed rules — no AI.
 *
 * It exists for one measurement: how often the model answers a message that carried a trip detail
 * without reporting it on its hidden trip line. So that the measurement needs no customer data, the
 * decision is taken at the moment of the turn and only "yes" or "no" is stored — never the words.
 *
 * It is a rule of thumb, not a parser. It will miss unusual phrasings and will sometimes say yes
 * to a message with no real detail; the rate it produces is an estimate. The trip record itself is
 * built from the model's trip line and the validator (EnquirySlots), never from this.
 */
final class TripDetailSignal
{
    private const MONTH = '(?:jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)[a-z]*';

    private const CHAT_WORDS = ['good', 'great', 'nice', 'best', 'hope', 'fine', 'sure', 'welcome', 'mobile', 'split', 'male', 'man', 'bar', 'reading'];

    private const NUM = '(?:\d{1,3}|one|two|three|four|five|six|seven|eight|nine|ten)';

    /**
     * @param callable(string):array $resolvePlace  AirportDirectory::resolve
     */
    public static function present(string $text, callable $resolvePlace): bool
    {
        $t = mb_strtolower(trim($text));
        if ($t === '') {
            return false;
        }

        $patterns = [
            // dates
            '/\b\d{1,2}(?:st|nd|rd|th)?\s*(?:of\s+)?' . self::MONTH . '\b/u',
            '/\b' . self::MONTH . '\s+\d{1,2}\b/u',
            '/\b\d{1,2}[\/.\-]\d{1,2}(?:[\/.\-]\d{2,4})?\b/',
            '/\b20\d{2}-\d{2}-\d{2}\b/',
            '/\b(?:tomorrow|tonight|next\s+(?:week|month)|this\s+weekend|(?:mon|tues|wednes|thurs|fri|satur|sun)day)\b/',
            // travellers
            '/\b' . self::NUM . '\s+(?:adults?|child(?:ren)?|kids?|infants?|bab(?:y|ies)|people|persons?|pax|passengers?|travell?ers?|of\s+us|tickets?|seats?)\b/',
            '/\bfor\s+' . self::NUM . '\b/',
            '/\bwe\s+(?:are|will\s+be)\s+' . self::NUM . '\b/',
            '/\b(?:just\s+me|travell?ing\s+alone|my\s+(?:wife|husband|family|son|daughter|parents))\b/',
            // kind of trip, cabin and preferences
            '/\b(?:one[\s\-]?way|return|round[\s\-]?trip|multi[\s\-]?city|coming\s+back|come\s+back)\b/',
            '/\b(?:economy|business(?:\s+class)?|first\s+class|premium)\b/',
            '/\b(?:direct|non[\s\-]?stop|baggage|luggage|\d+\s?kgs?|flexible|airlines?|emirates|qatar|ethiopian|rwandair|turkish|flydubai|klm)\b/',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $t)) {
                return true;
            }
        }

        // A place: the words after to / from / via, or the whole message when it is very short
        // ("Nairobi", "Entebbe to Dubai"). Checked against the same directory the trip record uses.
        $candidates = [];
        $plain = trim((string) preg_replace('/[^\p{L}\s\'\-]+/u', ' ', $text));
        $words = preg_split('/\s+/', $plain, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        // Everyday words that are also airport or town names ("Good" matches Fort Good Hope) do not
        // count when they are the whole message.
        if (count($words) >= 1 && count($words) <= 3 && ! array_intersect(array_map('mb_strtolower', $words), self::CHAT_WORDS)) {
            $candidates[] = implode(' ', $words);
        }
        foreach ($words as $i => $w) {
            if (in_array(mb_strtolower($w), ['to', 'from', 'via', 'into'], true)) {
                for ($n = 3; $n >= 1; $n--) {
                    $slice = array_slice($words, $i + 1, $n);
                    if (count($slice) === $n) {
                        $candidates[] = implode(' ', $slice);
                    }
                }
            }
        }
        foreach (array_unique($candidates) as $c) {
            try {
                $status = (string) ($resolvePlace($c)['status'] ?? '');
            } catch (\Throwable $e) {
                $status = '';
            }
            if ($status === 'ok' || $status === 'ambiguous') {
                return true;
            }
        }

        return false;
    }
}
