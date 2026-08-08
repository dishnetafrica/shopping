<?php declare(strict_types=1);

namespace App\Services\Field;

use App\Services\Crm\BusinessStage;

/**
 * v4.63 — what happened on a visit, in six words the system can count.
 *
 * PURE. An outcome in, a decision out. No models, no database.
 *
 * The vocabulary is CLOSED, and that is the whole design. Free text is easier for the rep and makes
 * this table worth nothing in a year: "he said maybe next month?" cannot be counted, cannot be
 * charted, and cannot be a training label. Six words can.
 *
 * ── the distinction that matters more than any other ────────────────────────────────────────────
 *
 * **OWNER ABSENT IS NOT A NO.** Nobody was asked, so nothing was learned. Any future scoring needs
 * negatives — businesses approached that did not buy — and the shop has none, because nobody has
 * been writing them down. The moment those start being collected, the temptation is to count every
 * unsuccessful visit as a negative, and an "owner absent" counted that way teaches a model that
 * shops with busy owners do not buy. That is not a subtle statistical point; it is the difference
 * between a model that knows something and a model that has learned the rep's calendar.
 *
 * So `informative()` exists, it is false for exactly two outcomes, and everything that ever counts
 * outcomes has to go through it.
 */
final class VisitOutcome
{
    /** The closed vocabulary. Adding one is a code change with a test, which is the point. */
    public const ALL = [
        'interested', 'needs_quotation', 'call_later',
        'buys_elsewhere', 'not_interested', 'owner_absent',
    ];

    public const LABEL = [
        'interested'      => 'Interested',
        'needs_quotation' => 'Wants a quotation',
        'call_later'      => 'Come back later',
        'buys_elsewhere'  => 'Buys from someone else',
        'not_interested'  => 'Not interested',
        'owner_absent'    => 'Owner was out',
    ];

    /** What the rep does next, in the words a rep would use. */
    public const NEXT = [
        'interested'      => 'Send them a price list and agree a first order.',
        'needs_quotation' => 'Raise the quotation today, while they still remember the visit.',
        'call_later'      => 'Call on the day they said.',
        'buys_elsewhere'  => 'Find out what they pay, and whether you can beat it.',
        'not_interested'  => '',
        'owner_absent'    => 'Go back when the owner is there — ask the staff when that is.',
    ];

    /** How long a follow-up can wait before it stops being one. 0 means none is owed. */
    private const DUE_DAYS = [
        'interested'      => 3,
        'needs_quotation' => 1,     // a quotation a week later is a different conversation
        'call_later'      => 14,
        'buys_elsewhere'  => 30,
        'not_interested'  => 0,
        'owner_absent'    => 7,
    ];

    /**
     * Outcomes where nobody was actually asked whether they would buy.
     *
     * Read the class docblock before changing this list. It is the guard on every future count.
     */
    private const UNINFORMATIVE = ['owner_absent', 'call_later'];

    public static function isValid(?string $o): bool
    {
        return in_array(self::norm($o), self::ALL, true);
    }

    public static function norm(?string $o): string
    {
        return strtolower(trim(str_replace([' ', '-'], '_', (string) $o)));
    }

    public static function label(?string $o): string
    {
        return self::LABEL[self::norm($o)] ?? '—';
    }

    /**
     * Did this visit tell us whether they will buy?
     *
     * False for "owner absent" — nobody was asked — and for "come back later", which is a deferral
     * rather than an answer. Both are real, useful visits. Neither is evidence about the business.
     */
    public static function informative(?string $o): bool
    {
        $n = self::norm($o);

        return in_array($n, self::ALL, true) && ! in_array($n, self::UNINFORMATIVE, true);
    }

    /** Of the visits that told us something, was it a yes? */
    public static function isPositive(?string $o): bool
    {
        return in_array(self::norm($o), ['interested', 'needs_quotation'], true);
    }

    public static function isNegative(?string $o): bool
    {
        return in_array(self::norm($o), ['buys_elsewhere', 'not_interested'], true);
    }

    public static function needsFollowUp(?string $o): bool
    {
        return self::followUpDays($o) > 0;
    }

    public static function followUpDays(?string $o): int
    {
        return self::DUE_DAYS[self::norm($o)] ?? 0;
    }

    public static function nextStep(?string $o): string
    {
        return self::NEXT[self::norm($o)] ?? '';
    }

    public static function tone(?string $o): string
    {
        $n = self::norm($o);
        if (self::isPositive($n)) {
            return 'ok';
        }
        if ($n === 'not_interested') {
            return 'danger';
        }

        return in_array($n, self::ALL, true) ? 'warn' : 'muted';
    }

    /**
     * Does this visit change what the business IS to the shop?
     *
     * **Only ever from DISCOVERED**, and this narrowness is deliberate. A rep standing in the shop
     * is better evidence than any provider, so a stranger who has been spoken to should stop being
     * a stranger. But moving an existing REPEAT customer because somebody logged a visit would
     * rewrite a relationship the order book already describes, and marking a real customer LOST is
     * a decision that belongs on their record rather than as a side effect of a visit note.
     *
     * v4.60's rule stands untouched underneath this: **money is what makes a business a customer.**
     * This only decides when a stranger stops being a stranger.
     *
     * @return ?string the new stage, or null to leave it exactly as it is
     */
    public static function promotesFrom(?string $outcome, ?string $stage): ?string
    {
        if (! BusinessStage::isDiscovered($stage) || ! self::isValid($outcome)) {
            return null;
        }

        return match (self::norm($outcome)) {
            // A person was spoken to and said yes, or as good as.
            'interested', 'needs_quotation' => 'INTERESTED',
            // A person was spoken to. The business is real and known, whatever they said.
            'call_later', 'buys_elsewhere'  => 'NEW',
            // A human decision, and the one stage the nightly pass never recomputes.
            'not_interested'                => 'LOST',
            // Nobody was asked. Nothing is known that was not known yesterday.
            default                         => null,
        };
    }

    /** For a picker: value => label, in the order a rep would look for them. */
    public static function options(): array
    {
        $o = [];
        foreach (self::ALL as $k) {
            $o[$k] = self::LABEL[$k];
        }

        return $o;
    }
}
