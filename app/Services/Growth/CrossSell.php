<?php declare(strict_types=1);

namespace App\Services\Growth;

/**
 * v4.56 — what a customer does not buy that they probably should.
 *
 * PURE. Counts in, judgement out. No models, no database.
 *
 * The whole difficulty is telling a real pattern from a popular product. "Customers who buy foil
 * also buy cling film" is worth saying. "Customers who buy foil also buy rice" is not — everybody
 * buys rice, and a screen that says it about every customer is noise wearing the costume of insight.
 *
 * So a gap has to clear four bars before it is shown, and the fourth is the one that matters:
 *
 *   SUPPORT     at least a few customers actually do both. Two is a coincidence.
 *   ANCHOR      enough people buy the anchor for a fraction of them to mean anything.
 *   CONFIDENCE  most of the anchor's buyers buy the candidate too.
 *   LIFT        …and meaningfully more than the shop's customers buy it in general.
 *
 * Without LIFT, the top suggestion for every customer in the shop is whatever sells most, and the
 * advice is "sell them your best seller" — which the owner already knew and did not need a screen
 * for. Lift is what turns a popularity chart into an observation.
 */
final class CrossSell
{
    /** Fewer than this many customers doing both is an anecdote, not a pattern. */
    public const MIN_SUPPORT = 3;

    /** A fraction of two buyers is not a fraction worth quoting. */
    public const MIN_ANCHOR = 4;

    /** Most of the anchor's buyers, not a handful of them. */
    public const MIN_CONFIDENCE = 0.5;

    /** At least this much likelier than the shop's baseline, or it is just a popular product. */
    public const MIN_LIFT = 1.25;

    /**
     * Judge one anchor → candidate pair.
     *
     * @param  int  $both             customers who buy the anchor AND the candidate
     * @param  int  $anchorBuyers     customers who buy the anchor
     * @param  int  $candidateBuyers  customers who buy the candidate, anchor or not
     * @param  int  $totalBuyers      customers who buy anything at all
     *
     * @return array{ok:bool, confidence:float, lift:float, both:int, anchorBuyers:int, why:string}
     */
    public static function judge(int $both, int $anchorBuyers, int $candidateBuyers, int $totalBuyers): array
    {
        $out = [
            'ok' => false, 'confidence' => 0.0, 'lift' => 0.0,
            'both' => max(0, $both), 'anchorBuyers' => max(0, $anchorBuyers), 'why' => '',
        ];

        if ($both < 0 || $anchorBuyers <= 0 || $totalBuyers <= 0 || $candidateBuyers <= 0) {
            return ['why' => 'not enough data'] + $out;
        }
        if ($both > $anchorBuyers || $both > $candidateBuyers || $anchorBuyers > $totalBuyers) {
            return ['why' => 'counts disagree'] + $out;   // a caller bug; never silently score it
        }

        $confidence = $both / $anchorBuyers;                    // of anchor buyers, how many take the candidate
        $baseline   = $candidateBuyers / $totalBuyers;          // how many take it anyway
        $lift       = $baseline > 0 ? $confidence / $baseline : 0.0;

        $ok = $both >= self::MIN_SUPPORT
            && $anchorBuyers >= self::MIN_ANCHOR
            && $confidence >= self::MIN_CONFIDENCE
            && $lift >= self::MIN_LIFT;

        return [
            'ok' => $ok,
            'confidence' => round($confidence, 3),
            'lift' => round($lift, 2),
            'both' => $both,
            'anchorBuyers' => $anchorBuyers,
            'why' => $ok ? '' : self::shortfall($both, $anchorBuyers, $confidence, $lift),
        ];
    }

    /**
     * The sentence a rep reads. Deliberately the raw counts rather than a percentage: "7 of the 9
     * customers who buy Aluminium Foil also buy Cling Film" can be checked and argued with, where
     * "78% confidence" invites belief without understanding.
     */
    public static function sentence(string $anchor, string $candidate, int $both, int $anchorBuyers): string
    {
        return $both . ' of the ' . $anchorBuyers . ' customers who buy ' . $anchor
            . ' also buy ' . $candidate . '.';
    }

    /**
     * Where to put a rep's breath. Confidence decides whether to mention it at all; what the product
     * is worth decides which of several true things to mention first.
     */
    public static function rank(array $gap): float
    {
        if (! ($gap['ok'] ?? false)) {
            return 0.0;
        }
        $conf  = max(0.0, min(1.0, (float) ($gap['confidence'] ?? 0)));
        $value = max(0.0, (float) ($gap['value'] ?? 0));
        // Lift is a gate, not a multiplier: past the threshold, a lift of 9 is not three times better
        // advice than a lift of 3, it usually just means a smaller denominator.
        return round($conf * $value, 2);
    }

    /** Why a pair did not qualify — for the log and for anyone asking why they see nothing. */
    private static function shortfall(int $both, int $anchorBuyers, float $confidence, float $lift): string
    {
        if ($both < self::MIN_SUPPORT) {
            return 'only ' . $both . ' customers do both';
        }
        if ($anchorBuyers < self::MIN_ANCHOR) {
            return 'too few buy the anchor';
        }
        if ($confidence < self::MIN_CONFIDENCE) {
            return 'fewer than half the anchor buyers take it';
        }

        return 'no more likely than usual (lift ' . round($lift, 2) . ')';
    }
}
