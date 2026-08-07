<?php declare(strict_types=1);

namespace App\Services\Growth;

/**
 * v4.57 — a quotation is not won when it becomes an order. It is won when the money arrives.
 *
 * PURE. Facts in, stage out. No models, no database.
 *
 * The Quotations screen has always shown a "Conversion rate", and it counts quotations that became
 * ORDERS. That is a real number and worth keeping, but it answers the easier question. A quote that
 * became an order nobody invoiced, or an invoice nobody collected, counts as a win on that screen
 * and as nothing at all in the bank.
 *
 * So this walks the whole chain — quoted → sent → seen → agreed → ordered → invoiced → paid — and
 * names the two places value gets stuck, because those are the ones somebody can act on today:
 *
 *   WON BUT NOT INVOICED   the customer agreed, an order exists, and no invoice was ever raised.
 *                          Nobody is chasing it because on every screen it already looks finished.
 *   INVOICED BUT NOT PAID  the ordinary receivable, but reached from the quote rather than the
 *                          ledger, so a rep sees the outcome of their own quotation.
 *
 * The first one is the quiet one. It cannot appear in Collections, because Collections reads
 * invoices and there is no invoice.
 */
final class QuoteToCash
{
    /** Ordered worst-to-best, so a funnel drawn from this reads top to bottom. */
    public const STAGES = [
        'draft', 'sent', 'viewed', 'accepted',
        'won_uninvoiced', 'invoiced_unpaid', 'part_paid', 'paid',
        'lost', 'expired',
    ];

    public const LABEL = [
        'draft'           => 'Draft',
        'sent'            => 'Sent, no reply',
        'viewed'          => 'Opened, no reply',
        'accepted'        => 'Agreed, no order yet',
        'won_uninvoiced'  => 'Ordered, never invoiced',
        'invoiced_unpaid' => 'Invoiced, unpaid',
        'part_paid'       => 'Part paid',
        'paid'            => 'Paid',
        'lost'            => 'Declined',
        'expired'         => 'Expired',
    ];

    /** Money that has arrived. Nothing else counts as won. */
    public const BANKED = ['paid'];

    /** Agreed business that has not turned into money, and can still be rescued. */
    public const STUCK = ['won_uninvoiced', 'invoiced_unpaid', 'part_paid'];

    /**
     * Where one quotation actually stands.
     *
     * @param  array{
     *   status:string, sent:bool, viewed:bool, accepted:bool,
     *   orderId:?int, invoiced:bool, invoiceTotal:float, invoicePaid:float
     * } $q
     */
    public static function stage(array $q): string
    {
        $status = strtolower(trim((string) ($q['status'] ?? '')));

        // A dead quote is dead whatever else is true of it.
        if (in_array($status, ['declined', 'rejected', 'lost'], true)) {
            return 'lost';
        }
        if (in_array($status, ['expired', 'archived'], true)) {
            return 'expired';
        }

        // Past the order line, the money decides — not the quotation's own status field, which stops
        // being maintained the moment an order exists.
        if (! empty($q['orderId'])) {
            if (empty($q['invoiced'])) {
                return 'won_uninvoiced';
            }
            $total = round((float) ($q['invoiceTotal'] ?? 0), 2);
            $paid  = round((float) ($q['invoicePaid'] ?? 0), 2);

            if ($total > 0 && $paid >= $total - 0.5) {
                return 'paid';
            }

            return $paid > 0.005 ? 'part_paid' : 'invoiced_unpaid';
        }

        if (! empty($q['accepted']) || $status === 'accepted') {
            return 'accepted';
        }
        if (! empty($q['viewed'])) {
            return 'viewed';
        }

        return ! empty($q['sent']) || in_array($status, ['sent', 'revised', 'customer_revised'], true)
            ? 'sent'
            : 'draft';
    }

    /**
     * Roll a set of staged quotations into counts and value per stage.
     *
     * @param  array<int,array{stage:string, value:float}>  $rows
     * @return array<string,array{count:int, value:float}>
     */
    public static function funnel(array $rows): array
    {
        $out = [];
        foreach (self::STAGES as $s) {
            $out[$s] = ['count' => 0, 'value' => 0.0];
        }
        foreach ($rows as $r) {
            $s = (string) ($r['stage'] ?? '');
            if (! isset($out[$s])) {
                continue;
            }
            $out[$s]['count']++;
            $out[$s]['value'] = round($out[$s]['value'] + (float) ($r['value'] ?? 0), 2);
        }

        return $out;
    }

    /**
     * Of everything quoted, what share actually turned into money.
     *
     * Deliberately measured in VALUE, not count. Winning nine quotes of USD 50 and losing one of
     * USD 5,000 is a 90% win rate and a bad month, and only one of those two numbers tells you so.
     *
     * @return array{quoted:float, banked:float, stuck:float, rate:float}
     */
    public static function collected(array $funnel): array
    {
        $quoted = 0.0; $banked = 0.0; $stuck = 0.0;
        foreach ($funnel as $stage => $f) {
            $v = (float) ($f['value'] ?? 0);
            $quoted += $v;
            if (in_array($stage, self::BANKED, true)) {
                $banked += $v;
            }
            if (in_array($stage, self::STUCK, true)) {
                $stuck += $v;
            }
        }

        return [
            'quoted' => round($quoted, 2),
            'banked' => round($banked, 2),
            'stuck'  => round($stuck, 2),
            'rate'   => $quoted > 0 ? round($banked / $quoted, 4) : 0.0,
        ];
    }

    /** True when this stage is agreed business that has not been collected — the actionable list. */
    public static function isStuck(string $stage): bool
    {
        return in_array($stage, self::STUCK, true);
    }

    /** What to do about it, in the words a rep would use. */
    public static function nextStep(string $stage): string
    {
        return match ($stage) {
            'won_uninvoiced'  => 'Order placed but never invoiced — raise the invoice.',
            'invoiced_unpaid' => 'Invoiced and unpaid — chase the payment.',
            'part_paid'       => 'Part paid — collect the balance.',
            'accepted'        => 'Agreed but no order yet — turn it into an order.',
            'viewed'          => 'They opened it and went quiet — follow up.',
            'sent'            => 'Sent with no reply — follow up.',
            default           => '',
        };
    }
}
