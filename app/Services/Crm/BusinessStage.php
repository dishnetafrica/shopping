<?php declare(strict_types=1);

namespace App\Services\Crm;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

/**
 * v4.60 — the line between a business you found and a customer you have.
 *
 * PURE, apart from two query helpers that add one WHERE clause and nothing else.
 *
 * ADR-016 puts discovered businesses into `customer_profiles` rather than a second table, which is
 * the right call — one record for an organisation, and the whole existing CRM applies to it on the
 * day it arrives. The price is stated plainly in the ADR's own Consequences: **`customer_profiles`
 * will contain rows that are not customers**, and every screen and count that says "customers"
 * silently changes meaning. Getting that wrong makes the owner's headline numbers wrong, which is
 * worse than any discovery bug, because a wrong number is believed.
 *
 * This class is the whole mitigation. One definition of "not a customer", used by every count, list,
 * export and — most importantly — every audience.
 *
 * THREE THINGS IT MUST GET RIGHT, each of which is a way this has gone wrong elsewhere:
 *
 *   NULL     The existing 676 profiles have whatever `stage` they already have, and some have none.
 *            Written the obvious way, `stage <> 'DISCOVERED'` evaluates to NULL for a NULL stage and
 *            SQL drops the row — so the fix for over-counting would quietly under-count instead, and
 *            real customers would vanish from the directory. Every comparison here coalesces first.
 *
 *   CASE     `relationship:stages` runs nightly and rewrites `stage` through `strtoupper()`. A
 *            lowercase 'discovered' would be silently rewritten to 'DISCOVERED' on the first night,
 *            so a filter matching the lowercase spelling would work on deploy day and fail the next
 *            morning. The stored value is UPPERCASE, and every comparison uppercases anyway.
 *
 *   RAW      The Communication Center builds its audience with `DB::table('customer_profiles')`.
 *            No Eloquent scope, global or otherwise, can reach it — so the guard also exists as a
 *            SQL fragment, and the audience query uses that.
 */
final class BusinessStage
{
    /** A business somebody found. Not a customer, not a lead, and never a message recipient. */
    public const DISCOVERED = 'DISCOVERED';

    /**
     * The vocabulary `relationship:stages` already maintains, plus the one this ADR adds.
     *
     * ADR-016 decision 2 proposed replacing this with `discovered → lead → contacted → quoted →
     * customer → lapsed`. That was written without reading the nightly command, which has been
     * maintaining the list below for months and drives `health`, `VIP`, `AT_RISK` and `WINBACK`.
     * Replacing a working vocabulary to match a document is how working behaviour gets deleted; the
     * ADR is amended instead, and DISCOVERED joins the front of the existing list.
     */
    public const ALL = [
        'DISCOVERED', 'NEW', 'INTERESTED', 'QUOTED', 'NEGOTIATING', 'WAITING_BUDGET',
        'PURCHASED', 'REPEAT', 'VIP', 'DORMANT', 'LOST',
    ];

    public const LABEL = [
        'DISCOVERED'     => 'Discovered',
        'NEW'            => 'New',
        'INTERESTED'     => 'Interested',
        'QUOTED'         => 'Quoted',
        'NEGOTIATING'    => 'Negotiating',
        'WAITING_BUDGET' => 'Waiting on budget',
        'PURCHASED'      => 'Bought once',
        'REPEAT'         => 'Repeat buyer',
        'VIP'            => 'VIP',
        'DORMANT'        => 'Gone quiet',
        'LOST'           => 'Lost',
    ];

    /** Uppercase, trimmed, never null. A missing stage is '' — which is a customer, not a business. */
    public static function norm(?string $stage): string
    {
        return strtoupper(trim((string) $stage));
    }

    public static function isDiscovered(?string $stage): bool
    {
        return self::norm($stage) === self::DISCOVERED;
    }

    /**
     * Everything that is not DISCOVERED — including a blank stage.
     *
     * Deliberately defined as "not discovered" rather than "one of the known customer stages". A
     * stage this class has never heard of belongs to somebody's older code and its rows are real
     * customers; excluding them because the vocabulary moved on would be the same bug as the NULL
     * one, arrived at from the other direction.
     */
    public static function isCustomer(?string $stage): bool
    {
        return ! self::isDiscovered($stage);
    }

    public static function label(?string $stage): string
    {
        $s = self::norm($stage);

        return self::LABEL[$s] ?? ($s === '' ? '—' : ucfirst(strtolower(str_replace('_', ' ', $s))));
    }

    /**
     * The guard, as SQL. For `DB::table()` audiences and for any join that names the table.
     *
     * `coalesce` outermost, so a NULL stage becomes '' and compares cleanly instead of poisoning the
     * whole predicate. `trim` and `upper` inside it, in the same order as `norm()` above — the two
     * definitions have to agree exactly, or a padded value counts one way in a screen and the other
     * way in the badge above it, which is the hardest kind of wrong number to chase.
     */
    public static function sql(string $table = 'customer_profiles'): string
    {
        return "coalesce(upper(trim({$table}.stage)), '') <> '" . self::DISCOVERED . "'";
    }

    /** The guard, for an Eloquent query. */
    public static function scope(EloquentBuilder $q, string $table = 'customer_profiles'): EloquentBuilder
    {
        return $q->whereRaw(self::sql($table));
    }

    /**
     * What a discovered business becomes on the nightly pass.
     *
     * **Money promotes it. Nothing else does.** One order makes it a customer; two make it a repeat
     * buyer; no orders leaves it exactly where it is, for ever.
     *
     * The "for ever" is the part that matters. Left in the ordinary stage chain, a DISCOVERED row
     * with no orders and no conversation would fall to DORMANT after 45 days of silence — and
     * DORMANT counts in every customer total on the system, so several thousand strangers would walk
     * into the owner's headline number six weeks after the first import, long after anyone would
     * think to connect the two. You cannot go quiet on a relationship that never started.
     *
     * Any other stage is returned untouched: this class rules on discovered businesses only.
     */
    public static function promote(?string $stage, int $orders): string
    {
        $s = self::norm($stage);
        if ($s !== self::DISCOVERED) {
            return $s;
        }
        if ($orders >= 2) {
            return 'REPEAT';
        }

        return $orders === 1 ? 'PURCHASED' : self::DISCOVERED;
    }
}
