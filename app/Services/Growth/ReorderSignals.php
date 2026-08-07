<?php declare(strict_types=1);

namespace App\Services\Growth;

/**
 * v4.55 — who has gone quiet, and who is about to.
 *
 * PURE. No models, no database, no tenant, no clock of its own. Hand it the days on which a
 * customer ordered and what they spent, and it answers three questions: how often do they normally
 * buy, how far past that are they, and how much should anyone trust the answer.
 *
 * The third question is the one that makes this worth building rather than a WHERE clause.
 * "Last ordered 43 days ago" is a fact anybody can query. Whether 43 days is alarming depends
 * entirely on the customer: for a restaurant buying weekly it is a crisis, for a hotel buying
 * quarterly it is Tuesday. This class exists to tell those two apart, and — just as importantly —
 * to refuse to guess for the customers where it cannot.
 *
 * Three rules it will not break:
 *
 *   1. MEDIAN, never mean. One Christmas gap or one bulk pre-order would drag an average far enough
 *      to make every other customer look wrong.
 *   2. NO CADENCE FROM FEWER THAN THREE ORDERS. Two orders give one interval, and one interval is an
 *      anecdote. Those customers come back as `too_new` with no prediction attached.
 *   3. CONFIDENCE IS REPORTED, NOT BURIED. A customer who orders every 14 days ± 2 is predictable.
 *      One who ordered after 3, then 40, then 9, then 60 days has no cadence worth the name, and
 *      saying "overdue by 12 days" about them would be inventing precision — the same failure as a
 *      94% purchase probability or a USD 54,000 estimated opportunity.
 */
final class ReorderSignals
{
    /** Below three orders there are fewer than two intervals, and two intervals is not a pattern. */
    public const MIN_ORDERS = 3;

    /** Past this multiple of their own cadence, a customer has probably gone rather than slipped. */
    public const LAPSED_AT = 3.0;

    /** …but never call anyone lapsed inside this many days, however fast they normally buy. */
    public const LAPSED_FLOOR_DAYS = 45;

    /** Flag as due slightly before the cadence lands, so the call happens before the gap does. */
    public const DUE_AT = 0.85;

    /**
     * @param  array<int,int>  $dayNumbers  the day each order was placed, as a day number (any epoch,
     *                                      so long as it is consistent). Order does not matter.
     * @param  float  $value                what those orders were worth in total, base currency
     * @param  int    $today                today, in the same day numbering
     *
     * @return array{
     *   state:string, cadenceDays:?float, daysSince:?int, overdueDays:?int, overdueRatio:?float,
     *   orders:int, confidence:float, value:float, reason:string
     * }
     */
    public static function forCustomer(array $dayNumbers, float $value, int $today): array
    {
        $days = array_values(array_unique(array_map('intval', $dayNumbers)));
        sort($days);
        $n = count($days);

        $base = [
            'state' => 'unknown', 'cadenceDays' => null, 'daysSince' => null,
            'overdueDays' => null, 'overdueRatio' => null, 'orders' => $n,
            'confidence' => 0.0, 'value' => round($value, 2), 'reason' => '',
        ];

        if ($n === 0) {
            return ['reason' => 'Never ordered.'] + $base;
        }

        $last      = $days[$n - 1];
        $daysSince = max(0, $today - $last);

        // Not enough history to claim a rhythm. Say so plainly rather than predicting from one gap.
        if ($n < self::MIN_ORDERS) {
            return [
                'state' => 'too_new', 'daysSince' => $daysSince,
                'reason' => $n === 1
                    ? 'Ordered once, ' . $daysSince . ' days ago — no pattern yet.'
                    : 'Only two orders so far — not enough to know their rhythm.',
            ] + $base;
        }

        $gaps = [];
        for ($i = 1; $i < $n; $i++) {
            $gaps[] = $days[$i] - $days[$i - 1];
        }

        $cadence = self::median($gaps);
        if ($cadence < 1.0) {
            $cadence = 1.0;                  // several orders in one day is one buying occasion
        }

        $confidence = self::confidence($gaps, $cadence);
        $ratio      = round($daysSince / $cadence, 2);
        $overdue    = (int) round($daysSince - $cadence);

        $state = 'healthy';
        if ($ratio >= self::LAPSED_AT && $daysSince >= self::LAPSED_FLOOR_DAYS) {
            $state = 'lapsed';
        } elseif ($ratio > 1.0) {
            $state = 'overdue';
        } elseif ($ratio >= self::DUE_AT) {
            $state = 'due';
        }

        return [
            'state' => $state,
            'cadenceDays' => round($cadence, 1),
            'daysSince' => $daysSince,
            'overdueDays' => $overdue > 0 ? $overdue : 0,
            'overdueRatio' => $ratio,
            'orders' => $n,
            'confidence' => $confidence,
            'value' => round($value, 2),
            'reason' => self::reason($state, $cadence, $daysSince, $overdue, $confidence),
        ];
    }

    /**
     * How much to trust the cadence, in [0,1].
     *
     * Two things earn trust: a lot of orders, and intervals that agree with each other. The second
     * matters more. Twelve orders at wildly different spacings tell you less than four orders a
     * fortnight apart, so consistency is weighted above volume.
     */
    public static function confidence(array $gaps, float $cadence): float
    {
        $k = count($gaps);
        if ($k < 2 || $cadence <= 0) {
            return 0.0;
        }

        // Coefficient of variation, but measured against the MEDIAN — a single wild gap should cost
        // some confidence, not all of it, and the mean would let it dominate.
        $spread = 0.0;
        foreach ($gaps as $g) {
            $spread += abs($g - $cadence);
        }
        $cv = ($spread / $k) / $cadence;

        $consistency = max(0.0, 1.0 - min(1.0, $cv));            // 1 = metronome, 0 = chaos
        $volume      = min(1.0, ($k - 1) / 7);                   // 8 intervals is as good as it gets

        return round(min(1.0, 0.7 * $consistency + 0.3 * $volume), 2);
    }

    /** The middle value — robust to the one enormous gap that every real customer has somewhere. */
    public static function median(array $xs): float
    {
        $xs = array_values(array_filter(array_map('floatval', $xs), fn ($x) => $x >= 0));
        sort($xs);
        $c = count($xs);
        if ($c === 0) {
            return 0.0;
        }

        return $c % 2 === 1
            ? $xs[intdiv($c, 2)]
            : ($xs[$c / 2 - 1] + $xs[$c / 2]) / 2;
    }

    /**
     * What to chase first: value at stake weighted by how sure we are and how far gone they are.
     *
     * Deliberately NOT just "most overdue". A customer worth USD 40 who is 400% overdue matters less
     * than one worth USD 4,000 who is 30% overdue, and a rep with an afternoon should be sent to the
     * second. Confidence multiplies rather than adds, so a guess never outranks a certainty.
     */
    public static function priority(array $signal): float
    {
        $state = (string) ($signal['state'] ?? '');
        if (! in_array($state, ['due', 'overdue', 'lapsed'], true)) {
            return 0.0;
        }
        $value = max(0.0, (float) ($signal['value'] ?? 0));
        $conf  = max(0.0, min(1.0, (float) ($signal['confidence'] ?? 0)));
        $ratio = max(0.0, (float) ($signal['overdueRatio'] ?? 0));

        // Urgency rises with lateness then flattens: past about three cadences the horse has bolted
        // and a bigger number does not make it more worth chasing than a live account slipping now.
        $urgency = min(2.0, $ratio);

        return round($value * $conf * $urgency, 2);
    }

    private static function reason(string $state, float $cadence, int $daysSince, int $overdue, float $conf): string
    {
        $every = 'usually every ' . self::human($cadence);
        $soft  = $conf < 0.4 ? ' (their timing varies a lot — treat as a hint)' : '';

        return match ($state) {
            'lapsed'  => 'No order in ' . $daysSince . ' days, ' . $every . '.' . $soft,
            'overdue' => $overdue . ' days late — ' . $every . '.' . $soft,
            'due'     => 'Due about now — ' . $every . '.' . $soft,
            default   => 'On schedule — ' . $every . '.' . $soft,
        };
    }

    /**
     * "2 weeks" rather than "13.5 days". Deliberately returns no leading "about" — the caller
     * supplies the hedging it wants, and "usually every about 2 weeks" is how that goes wrong.
     */
    private static function human(float $days): string
    {
        if ($days >= 60) {
            return (int) round($days / 30) . ' months';
        }
        if ($days >= 14) {
            return (int) round($days / 7) . ' weeks';
        }

        return (int) round($days) . ' days';
    }
}
