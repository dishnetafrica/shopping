<?php

namespace App\Services\Bot;

/**
 * Detects a single WhatsApp message that packs CONFLICTING actions into multiple lines —
 * e.g. "Add it / Actually remove it / Add brown rice instead". A deterministic bot cannot
 * safely resolve which "it" to remove or what "instead" replaces, so rather than silently
 * processing one line (and removing the wrong product), the bot asks the customer to send
 * the changes one at a time.
 *
 * A plain multi-line ORDER ("5 Coke / 10 Rice / 2 Sugar") is NOT conflicted and passes
 * through to the normal wholesale read-back. Pure & static.
 */
class MultiLineGuard
{
    public static function lines(string $text): array
    {
        $out = [];
        foreach (preg_split('/[\r\n]+/', trim($text)) as $l) {
            $l = trim($l);
            if ($l !== '') $out[] = $l;
        }
        return $out;
    }

    /** True when the message mixes an add/order action with a removal or a correction. */
    public static function isConflicted(string $text): bool
    {
        $lines = self::lines($text);
        if (count($lines) < 2) return false;

        $hasAdd = false; $hasRemove = false; $hasCorrection = false;
        foreach ($lines as $l) {
            $lc = mb_strtolower($l);
            if (preg_match('/\b(remove|delete|cancel|take out|drop|get rid)\b/', $lc)) {
                $hasRemove = true;
            }
            if (preg_match('/\b(actually|instead|no wait|nvm|never ?mind|scratch that|change (it|that)|replace)\b/', $lc)) {
                $hasCorrection = true;
            }
            if (preg_match('/\b(add|want|need|buy|order|get|take)\b/', $lc)
                || preg_match('/^\d+\s*[a-z]/', $lc)        // "2 milk"
                || preg_match('/[a-z].*\d/', $lc)) {        // "rice 5kg"
                $hasAdd = true;
            }
        }

        // Conflicted only when a removal/correction co-exists with an add on another line.
        return ($hasRemove || $hasCorrection) && $hasAdd;
    }

    /** A short, human prompt asking the customer to send one action at a time. */
    public static function prompt(): string
    {
        return "I want to get this exactly right \u{1F642} You've got a few changes in one message. "
             . "Could you send them one at a time? Tell me first what to *add*, and I'll confirm — "
             . "then tell me anything to *remove* or *change*.";
    }
}
