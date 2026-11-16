<?php

namespace App\Services\Isp;

use App\Services\Travel\Conversation\ReplyGuard;

/**
 * Last check on the AI model's own words before an Internet-provider customer sees them. PURE.
 *
 * Enforced here instead of trusted to the prompt (DishNet: "do not invent missing prices; only
 * approved and published information reaches the customer"):
 *   1. A sentence stating a money amount that is not a catalogue price, not in the PUBLISHED sales
 *      knowledge and not written by the customer is removed (the money reader is the travel guard's).
 *   2. A sentence claiming we cover a place is removed unless it names a place management published
 *      as covered. Questions, hedges ("we'll confirm", "a survey is needed") and "not covered" stay.
 * A short note replaces what was removed. Hidden `<<…>>` marker lines are never touched.
 */
final class IspReplyGuard
{
    public const PRICE_NOTE = 'Your salesperson will confirm the exact price in a written quotation.';

    public const COVERAGE_NOTE = 'We will confirm coverage at your exact location; a site survey may be needed.';

    private const COVERAGE_CLAIM = '/\b(we (?:do |also |already )?(?:cover|serve|reach)|(?:is|are) (?:fully |already )?(?:covered|within (?:our )?(?:coverage|network|range))|(?:we )?have (?:coverage|fib(?:er|re)|network|service) (?:in|at|there|around)|(?:fib(?:er|re)|starlink|service|internet|connection|coverage) is (?:available|active) (?:in|at|there|around|where)|available (?:in|at) your (?:area|location|place)|in our coverage)\b/u';

    private const HEDGE = '/\b(confirm|check|checking|survey|not sure|may|might|depends?|let me|will let you know|get back|cannot|can\'t|unable|not|don\'t|do not|isn\'t|aren\'t|no coverage|if )\b/u';

    /**
     * @param float[]  $allowedAmounts  catalogue prices, amounts in published knowledge, amounts the customer wrote
     * @param string[] $coveredPlaces   lower-case names and place names of areas published as covered
     * @return array{reply:string,price_hits:int,coverage_hits:int}
     */
    public static function clean(string $reply, array $allowedAmounts, array $coveredPlaces): array
    {
        $price = 0;
        $coverage = 0;
        $parts = preg_split('/(<<[A-Z_]+.*?>>)/s', $reply, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$reply];
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                continue;                                   // a marker
            }
            $lines = explode("\n", $part);
            foreach ($lines as $li => $line) {
                $kept = [];
                foreach (self::sentences($line) as $sentence) {
                    if (self::unknownAmount($sentence, $allowedAmounts)) {
                        $price++;
                        continue;
                    }
                    if (self::claimsCoverage($sentence, $coveredPlaces)) {
                        $coverage++;
                        continue;
                    }
                    $kept[] = $sentence;
                }
                $lines[$li] = implode(' ', $kept);
            }
            $parts[$i] = implode("\n", $lines);
        }
        $out = trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+\n/', "\n", implode('', $parts)) ?? '') ?? '');
        if ($price > 0) {
            $out = trim($out . "\n\n" . self::PRICE_NOTE);
        }
        if ($coverage > 0) {
            $out = trim($out . "\n\n" . self::COVERAGE_NOTE);
        }

        return ['reply' => $out, 'price_hits' => $price, 'coverage_hits' => $coverage];
    }

    /**
     * The same rules as clean(), reporting instead of removing — used to review what people sent
     * (quality monitoring). @return list<array{kind:string,sentence:string}> kind: price | coverage
     */
    public static function findings(string $text, array $allowedAmounts, array $coveredPlaces): array
    {
        $out = [];
        $parts = preg_split('/(<<[A-Z_]+.*?>>)/s', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                continue;
            }
            foreach (explode("\n", $part) as $line) {
                foreach (self::sentences($line) as $sentence) {
                    if (trim($sentence) === '') {
                        continue;
                    }
                    if (self::unknownAmount($sentence, $allowedAmounts)) {
                        $out[] = ['kind' => 'price', 'sentence' => trim($sentence)];
                    } elseif (self::claimsCoverage($sentence, $coveredPlaces)) {
                        $out[] = ['kind' => 'coverage', 'sentence' => trim($sentence)];
                    }
                }
            }
        }

        return $out;
    }

    /** A statement (not a question, not hedged) that we cover a place not published as covered. */
    public static function claimsCoverage(string $sentence, array $coveredPlaces): bool
    {
        $s = mb_strtolower($sentence);
        if (! preg_match(self::COVERAGE_CLAIM, $s) || str_ends_with(rtrim($s), '?') || preg_match(self::HEDGE, $s)) {
            return false;
        }
        foreach ($coveredPlaces as $place) {
            if ($place !== '' && str_contains($s, mb_strtolower($place))) {
                return false;                               // names a place management published as covered
            }
        }

        return true;
    }

    private static function unknownAmount(string $sentence, array $allowed): bool
    {
        foreach (ReplyGuard::amounts($sentence) as $n) {
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
        $bits = preg_split('/(?<=[.!?])\s+(?=[^\s])/u', $line) ?: [$line];
        $out = [];
        foreach ($bits as $b) {
            if ($out && preg_match('/^\s*\d{1,2}[.)]$/', (string) end($out))) {
                $out[count($out) - 1] .= ' ' . $b;          // keep "1." with its list item
            } else {
                $out[] = $b;
            }
        }

        return $out;
    }
}
