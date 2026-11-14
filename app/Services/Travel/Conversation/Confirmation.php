<?php

namespace App\Services\Travel\Conversation;

/**
 * Did the customer just say "yes, that is right"? A PURE yes/no on fixed rules.
 *
 * Used only as a safety net: when the summary has been shown and the customer plainly agrees, the
 * enquiry is submitted even if the AI model forgot to report the confirmation. It is deliberately
 * strict — a long message, or one that carries a change or a doubt, is never read as agreement.
 */
final class Confirmation
{
    public static function isYes(string $text): bool
    {
        $t = mb_strtolower(trim(preg_replace('/[\s.!,👍✅🙏]+/u', ' ', $text) ?? ''));
        if ($t === '' || mb_strlen($t) > 40) {
            return false;
        }
        if (preg_match('/\b(no|not|but|change|instead|wait|except|actually|wrong|incorrect|cancel|however|only|add|remove|make it)\b/u', $t)) {
            return false;
        }

        return (bool) preg_match('/^(yes|yes please|yeah|yep|yup|ya|ok|okay|okey|sure|correct|confirm|confirmed|i confirm|that is correct|thats correct|that\'s correct|that is right|thats right|that\'s right|right|all correct|all good|looks good|go ahead|proceed|please proceed|please do|send it|perfect|great|fine|sawa|ndiyo|kale)( thanks| thank you| please)?$/u', $t);
    }
}
