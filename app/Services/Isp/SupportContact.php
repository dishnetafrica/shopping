<?php

namespace App\Services\Isp;

use App\Services\Sales\Brain\Knowledge;

/**
 * PURE. An existing customer with a problem gets the Technical Support number at once (DishNet, 21 Nov 2026:
 * "the bot is making a mistake: if someone reports a problem, give them the support number immediately").
 *
 * Live: "The remaining wifi is not working" → "I recommend contacting our technical support team. Since it's
 * currently outside business hours, they will be available tomorrow at 9:00 AM. Would you like me to note your
 * request?" — no number, and the SALES office hours applied to a support line that is open every day.
 *
 * The number and its hours are read from the PUBLISHED sales knowledge ("Technical Support +211 … (Mon–Sun
 * 7 AM – 10 PM)") — approved information only, nothing typed into code. The bot is told to give it in its
 * first reply; and when its reply sends the customer to support without the number, the number is added.
 */
final class SupportContact
{
    /**
     * PURE. The Technical Support number (and its hours, when written) in the published knowledge, or null.
     *
     * @param list<array{topic:string,body:?string}> $published  SalesBrain::published()
     * @return array{phone:string,hours:string}|null
     */
    public static function fromKnowledge(array $published): ?array
    {
        foreach ($published as $p) {
            if (in_array($p['topic'] ?? '', Knowledge::INTERNAL, true)) {
                continue;
            }
            if (preg_match('/\b(?:technical|tech)\s+support\b[^+\d\n]{0,20}(\+?\d[\d \-]{7,}\d)\s*(?:\(([^)\n]{3,60})\))?/iu', (string) ($p['body'] ?? ''), $m)) {
                return ['phone' => trim($m[1]), 'hours' => trim($m[2] ?? '')];
            }
        }

        return null;
    }

    /** PURE. "Technical Support: +211 921 443 002 (Mon–Sun 7 AM – 10 PM)". */
    public static function line(array $c): string
    {
        return 'Technical Support: ' . $c['phone'] . ($c['hours'] !== '' ? ' (' . $c['hours'] . ')' : '');
    }

    /** PURE. The rule the model reads. With no published number it is told where to find one, never to invent it. */
    public static function rule(?array $c): string
    {
        $give = $c !== null
            ? 'give them the number now: ' . self::line($c) . ', and tell them to call or WhatsApp it'
            : 'give them the Technical Support contact written in APPROVED SALES KNOWLEDGE (never make one up)';

        return "- SUPPORT: an existing customer reporting a problem (internet down or slow, Wi-Fi or router not working, red light, password, account, renewal) needs Technical Support, not sales. In your FIRST reply {$give}. "
            // No "available tomorrow" ever: support's next opening is not the sales office's (Codex on #176).
            . "Support keeps its own hours: the OFFICE HOURS rule is for sales only. Give the support hours exactly as written with the number, and never say yourself when support is open, closed, \"outside business hours\" or available tomorrow. "
            . "Do not troubleshoot, do not ask for their location or account details, and do not offer to connect them, pass it on or note their request. Then ask if there is anything else you can help with.\n";
    }

    /** PURE. Does the reply send the customer to (technical) support? */
    public static function sendsToSupport(string $reply): bool
    {
        return (bool) preg_match('/\b(technical support|tech(?:nical)? team|support team|support department|support line|customer support|technical department)\b/iu', $reply);
    }

    /**
     * PURE. The reply, with the support number added when it sends the customer to support without it.
     * Unchanged when no number is published or the number is already there.
     */
    public static function ensure(string $reply, ?array $c): string
    {
        if ($c === null || ! self::sendsToSupport($reply)) {
            return $reply;
        }
        $digits = fn (string $s) => preg_replace('/\D/', '', $s) ?? '';
        $want = $digits($c['phone']);
        if ($want === '' || str_contains($digits($reply), substr($want, -9))) {
            return $reply;
        }
        // Before any hidden <<…>> marker line, which must stay last.
        $pos = strpos($reply, '<<');
        $add = "\u{1F4DE} " . self::line($c);

        return $pos === false
            ? rtrim($reply) . "\n\n" . $add
            : rtrim(substr($reply, 0, $pos)) . "\n\n" . $add . "\n" . substr($reply, $pos);
    }
}
