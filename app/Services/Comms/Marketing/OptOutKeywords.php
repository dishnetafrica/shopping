<?php declare(strict_types=1);

namespace App\Services\Comms\Marketing;

/**
 * Inbound marketing opt-out detection.
 *
 * EXACT-match only (after uppercasing, trimming, collapsing whitespace and stripping trailing
 * punctuation), so a genuine unsubscribe intent opts the customer out but an ordinary sentence that
 * merely contains the word — "stop by the shop tomorrow", "please remove the onions" — does not. An
 * opt-out is a heavy, sticky action; a false positive silences a real customer, so the bar is high.
 */
final class OptOutKeywords
{
    /** @var string[] Exact phrases that mean "stop marketing me". */
    private const PHRASES = [
        'STOP', 'STOP ALL', 'STOPALL',
        'UNSUBSCRIBE', 'UNSUB',
        'REMOVE', 'REMOVE ME',
        'NO MORE', 'NOMORE',
        'OPT OUT', 'OPTOUT', 'OPT-OUT',
        'CANCEL',
    ];

    public static function isStop(string $message): bool
    {
        $norm = strtoupper(trim($message));
        $norm = preg_replace('/\s+/', ' ', $norm) ?? $norm;   // collapse internal whitespace
        $norm = rtrim($norm, " \t.!,;:");                       // drop trailing punctuation

        return in_array($norm, self::PHRASES, true);
    }
}
