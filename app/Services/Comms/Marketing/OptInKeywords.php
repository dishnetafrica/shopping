<?php declare(strict_types=1);

namespace App\Services\Comms\Marketing;

/**
 * Inbound marketing OPT-IN detection — the mirror of OptOutKeywords.
 *
 * EXACT-match only (uppercased, trimmed, whitespace-collapsed, trailing punctuation stripped). The
 * phrases are deliberately marketing-specific — SUBSCRIBE, JOIN, OPT IN — and NEVER a bare "YES" or
 * "START", because those appear constantly in ordinary ordering chatter ("yes 2 kg", "start with rice")
 * and must not be misread as marketing consent. Consent has to be unambiguous.
 */
final class OptInKeywords
{
    /** @var string[] Exact phrases that mean "yes, send me offers". */
    private const PHRASES = [
        'SUBSCRIBE', 'SUBSCRIBE ME',
        'OPT IN', 'OPTIN', 'OPT-IN',
        'JOIN', 'JOIN LIST',
        'YES OFFERS', 'SEND OFFERS', 'SEND ME OFFERS',
        'START OFFERS',
    ];

    public static function isOptIn(string $message): bool
    {
        $norm = strtoupper(trim($message));
        $norm = preg_replace('/\s+/', ' ', $norm) ?? $norm;
        $norm = rtrim($norm, " \t.!,;:");

        return in_array($norm, self::PHRASES, true);
    }
}
