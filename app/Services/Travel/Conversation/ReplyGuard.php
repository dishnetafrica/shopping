<?php

namespace App\Services\Travel\Conversation;

/**
 * Last check on the AI model's own words before a travel customer sees them. PURE.
 *
 * Two things the model must never do on a travel tenant, enforced here instead of trusted to the
 * prompt:
 *   1. State a fare. Prices for flights and trips come from the supplier and the pricing engine
 *      (later milestones); until code supplies one, any amount the model writes is an invention.
 *      Amounts that are a real catalogue price (a fixed-fee visa service, an airport transfer) or
 *      that the customer wrote themselves (their budget) are allowed.
 *   2. Ask for passport data. Passport numbers and photos are never collected over chat.
 *
 * Works a sentence at a time and never touches the brain's hidden `<<…>>` marker lines.
 */
final class ReplyGuard
{
    public const FARE_NOTE     = 'Our travel team will confirm the fare for you.';

    public const AVAILABILITY_NOTE = 'Our travel team will confirm availability for you.';
    public const PASSPORT_NOTE = 'Please do not send passport details or photos here. Our team will collect them securely when you book.';

    private const CURRENCY = '(?:US\$|USD|UGX|UGSHS?|SHS|KSHS?|KES|TZS|RWF|SSP|EUR|GBP|AED|ZAR|\$|€|£)';
    private const UNIT     = '(?:USD|UGX|KES|TZS|RWF|SSP|EUR|GBP|AED|ZAR|dollars?|shillings?|euros?|pounds?|dirhams?|bob|\/=)';
    private const NUMBER   = '\d[\d,]*(?:\.\d+)?\s?(?:k|m|million|thousand)?';

    /**
     * @param  float[] $allowedAmounts  catalogue prices and amounts the customer wrote
     * @return array{reply:string,fare_hits:int,passport_hits:int,availability_hits:int}
     */
    public static function clean(string $reply, array $allowedAmounts = []): array
    {
        $fare = 0;
        $passport = 0;
        $availability = 0;

        // Keep hidden marker lines exactly as they are; guard only the visible text between them.
        $parts = preg_split('/(<<[A-Z_]+.*?>>)/s', $reply, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$reply];
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                continue;                                   // a marker
            }
            $lines = explode("\n", $part);
            foreach ($lines as $li => $line) {
                $kept = [];
                foreach (self::sentences($line) as $sentence) {
                    if (self::asksForPassport($sentence)) {
                        $passport++;
                        continue;
                    }
                    if (self::statesUnknownFare($sentence, $allowedAmounts)) {
                        $fare++;
                        continue;
                    }
                    if (self::claimsAvailability($sentence)) {
                        $availability++;
                        continue;
                    }
                    $kept[] = $sentence;
                }
                $lines[$li] = implode(' ', $kept);
            }
            $parts[$i] = implode("\n", $lines);
        }
        $out = trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+\n/', "\n", implode('', $parts)) ?? '') ?? '');

        if ($fare > 0) {
            $out = trim($out . "\n\n" . self::FARE_NOTE);
        }
        if ($availability > 0 && $fare === 0) {
            $out = trim($out . "\n\n" . self::AVAILABILITY_NOTE);
        }
        if ($passport > 0) {
            $out = trim($out . "\n\n" . self::PASSPORT_NOTE);
        }

        return ['reply' => $out, 'fare_hits' => $fare, 'passport_hits' => $passport, 'availability_hits' => $availability];
    }

    /** Every money amount written in a text, as numbers. Used for the customer's own message too. */
    public static function amounts(string $text): array
    {
        $out = [];
        $re = '/(?:' . self::CURRENCY . '\s?(' . self::NUMBER . '))|(?:(' . self::NUMBER . ')\s?' . self::UNIT . '(?![a-z]))/iu';
        if (preg_match_all($re, $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $raw = $hit[1] !== '' ? $hit[1] : ($hit[2] ?? '');
                $n = self::number($raw);
                if ($n !== null) {
                    $out[] = $n;
                }
            }
        }

        return $out;
    }

    /**
     * The model does not know what is available: seats, rooms, permits or dates. A sentence that
     * states it as fact is removed. Saying the team will check or confirm availability is what we
     * want said, and a question to the customer is left alone.
     */
    public static function claimsAvailability(string $sentence): bool
    {
        $s = mb_strtolower($sentence);
        if (! preg_match('/\b(availab\w*|sold out|fully booked|booked out|last (few |remaining )?(seats?|rooms?|permits?|spaces?|spots?)|(seats?|rooms?|permits?|spaces?) (are |is )?(left|remaining))\b/u', $s)) {
            return false;
        }
        if (str_ends_with(rtrim($s), '?')) {
            return false;
        }
        if (preg_match('/\b(confirm|check|checking|subject to|depend|depends|will let you know|get back|our team|the team|cannot|can\'t|unable|do not know|don\'t know|not sure|once|after)\b/u', $s)) {
            return false;
        }

        return true;
    }

    public static function asksForPassport(string $sentence): bool
    {
        if (! preg_match('/passport/i', $sentence)) {
            return false;
        }
        // Telling the customer NOT to send it, or that the team will handle it later, is what we want said.
        if (preg_match('/\b(do not|don\'t|dont|no need|never|not need|needn\'t|without|will collect|collect (it|them)|later|securely)\b/i', $sentence)) {
            return false;
        }
        if (preg_match('/\b(send|share|provide|upload|attach|forward|give|type|enter|tell|submit|drop|snap|whatsapp)\b[^.?!]{0,80}\bpassport/i', $sentence)) {
            return true;
        }

        return (bool) preg_match('/passport[^.?!]{0,60}\b(numbers?|no\.?|photo|picture|pic|copy|copies|scan|image|page|details)\b[^.!]*\?/i', $sentence);
    }

    private static function statesUnknownFare(string $sentence, array $allowed): bool
    {
        $found = self::amounts($sentence);
        if (! $found) {
            return false;
        }
        foreach ($found as $n) {
            $ok = false;
            foreach ($allowed as $a) {
                if (abs((float) $a - $n) < 0.005) {
                    $ok = true;
                    break;
                }
            }
            if (! $ok) {
                return true;
            }
        }

        return false;
    }

    /** @return string[] */
    private static function sentences(string $line): array
    {
        if (trim($line) === '') {
            return [$line];
        }
        // Split after . ! ? followed by a space and a capital/digit/emoji-ish start — but not inside "1." list numbers.
        $bits = preg_split('/(?<=[.!?])\s+(?=[^\s])/u', $line) ?: [$line];
        $out = [];
        foreach ($bits as $b) {
            // Re-join a list number ("1.") with what follows it.
            if ($out && preg_match('/^\s*\d{1,2}[.)]$/', (string) end($out))) {
                $out[count($out) - 1] .= ' ' . $b;
            } else {
                $out[] = $b;
            }
        }

        return $out;
    }

    private static function number(string $raw): ?float
    {
        $raw = strtolower(trim($raw));
        if ($raw === '') {
            return null;
        }
        $mult = 1.0;
        if (preg_match('/(k|thousand)$/', $raw)) {
            $mult = 1000.0;
        } elseif (preg_match('/(m|million)$/', $raw)) {
            $mult = 1000000.0;
        }
        $digits = preg_replace('/[^0-9.]/', '', $raw) ?? '';
        if ($digits === '' || ! is_numeric($digits)) {
            return null;
        }

        return (float) $digits * $mult;
    }
}
