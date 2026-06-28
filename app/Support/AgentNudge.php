<?php
namespace App\Support;

use Illuminate\Support\Str;

/**
 * Distinguishes a human agent's short "nudge" (typing "hi" to wake a silent bot) from a real
 * takeover (the human actually handling the conversation). A nudge should pause the bot only briefly
 * so it resumes for the customer's next question; a real takeover pauses it for the full window.
 * Pure + deterministic.
 */
class AgentNudge
{
    private const GREETINGS = ['hi', 'hii', 'hiii', 'hy', 'hey', 'heyy', 'hello', 'helo', 'hallo',
        'hai', 'yo', 'namaste', 'namaskar', 'jambo', 'habari', 'ok', 'okay', 'k', 'yes', 'sure',
        'good morning', 'good afternoon', 'good evening', 'hi there', 'hello there'];

    public static function is(string $text): bool
    {
        $t = Str::lower(trim($text));
        if ($t === '') return false;

        // Strip trailing punctuation / common emoji used in greetings.
        $clean = trim(preg_replace('/[\s!.…,👋🙏😊]+$/u', '', $t));
        if ($clean === '' || preg_match('/^[👋🙏😊\s]+$/u', $t)) return true;   // pure emoji wave
        if (in_array($clean, self::GREETINGS, true)) return true;

        // Short, no question, no digits (so prices/quantities never count as a nudge), ≤2 words,
        // and it must start with a greeting token.
        $words = preg_split('/\s+/', $clean);
        if (mb_strlen($clean) <= 16 && count($words) <= 2 && ! Str::contains($clean, '?') && ! preg_match('/\d/', $clean)) {
            foreach (self::GREETINGS as $g) {
                if ($clean === $g || Str::startsWith($clean, $g . ' ')) return true;
            }
        }
        return false;
    }
}
