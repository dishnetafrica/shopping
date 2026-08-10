<?php declare(strict_types=1);

namespace App\Services\Sales;

/**
 * v4.88 (ADR-019) — the rules of a partial return, as pure functions.
 *
 * An issued invoice is never edited: a return raises a numbered CREDIT NOTE for the returned
 * lines only, exactly as Amazon and Flipkart do (and as tax law requires — a post-issue
 * reduction must be its own document referencing the original invoice). This class holds the
 * arithmetic and the refusals so the till (v4.0) and the panel (v4.88) cannot drift apart:
 * ONE key rule, ONE cap, ONE list of money treatments.
 *
 * The cap is the whole safety story. A line may be returned only up to
 *      what was SOLD on that order  −  what was ALREADY returned against it
 * and that subtraction is computed server-side from stored records, never from the browser.
 */
final class ReturnPolicy
{
    /** How the money comes back. Staff choose per return (owner's call, 10 Aug). */
    public const METHODS = ['invoice', 'credit', 'cash', 'momo', 'replace'];

    /* v4.89 — the life of a return. Wholesale goods travel back later, so a panel return is
       RECORDED first and only settles when the goods are physically confirmed. */
    public const PENDING = 'pending';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';
    public const STATUSES = [self::PENDING, self::COMPLETED, self::CANCELLED];

    /** Small tolerance so a 3-decimal weight line isn't refused by float noise. */
    public const EPS = 0.0001;

    /**
     * The ONE identity rule for a sale line: a real product id when there is one, otherwise the
     * casefolded name. Both the sold map, the returned map and the submitted lines must agree on
     * this or a cap silently stops matching — which is how a customer returns 60 of 50 cartons.
     *
     * @return int|string
     */
    public static function key(array $line)
    {
        $pid = (int) ($line['product_id'] ?? 0);

        return $pid > 0 ? $pid : ('n:' . mb_strtolower(trim((string) ($line['name'] ?? ''))));
    }

    /** Quantities SOLD on this order, keyed by key(). */
    public static function soldMap(array $lines): array
    {
        $map = [];
        foreach ($lines as $ln) {
            $k = self::key((array) $ln);
            $map[$k] = (float) ($map[$k] ?? 0) + (float) ($ln['qty'] ?? 0);
        }

        return $map;
    }

    /**
     * Quantities ALREADY returned, from the items_json of prior return records.
     * Legacy rows without items_json simply don't restrict — they are unstructured, and refusing
     * every return because an old record is unreadable would be worse than allowing one.
     *
     * v4.89 — a PENDING return counts too. It has not been credited or restocked yet, but those
     * cartons are spoken for: without this, staff record "2 coming back" three times over and the
     * same 2 cartons get returned 6 times. Only CANCELLED rows release their hold on the cap.
     * A pending row is read from requested_json (what was asked for); a settled one from
     * items_json (what actually arrived).
     *
     * @param iterable<array|object> $returnRows rows carrying items_json / requested_json / status
     */
    public static function returnedMap(iterable $returnRows): array
    {
        $map = [];
        foreach ($returnRows as $r) {
            $status = (string) (is_array($r) ? ($r['status'] ?? '') : ($r->status ?? ''));
            if ($status === self::CANCELLED) {
                continue;                       // the goods never came — the cap is free again
            }
            $items = is_array($r) ? ($r['items_json'] ?? []) : ($r->items_json ?? []);
            if ($status === self::PENDING) {
                $req = is_array($r) ? ($r['requested_json'] ?? null) : ($r->requested_json ?? null);
                if (! empty($req)) {
                    $items = $req;              // nothing has arrived yet; hold what was promised
                }
            }
            foreach ((array) $items as $l) {
                if (! is_array($l)) {
                    continue;
                }
                $k = self::key($l);
                $map[$k] = (float) ($map[$k] ?? 0) + (float) ($l['qty'] ?? 0);
            }
        }

        return $map;
    }

    /** What is still returnable per key — never negative. */
    public static function remaining(array $sold, array $returned): array
    {
        $out = [];
        foreach ($sold as $k => $qty) {
            $out[$k] = max(0.0, round((float) $qty - (float) ($returned[$k] ?? 0), 3));
        }

        return $out;
    }

    /**
     * Validate submitted lines against the caps and price them at the ORIGINAL sale prices.
     *
     * Prices are passed in from the order's own lines by the caller; this method never invents
     * one and never reaches for today's price list — a customer is refunded what they paid.
     *
     * @return array{ok:bool,error:string,clean:array,amount:float}
     */
    public static function check(array $lines, array $sold, array $returned): array
    {
        $clean = [];
        $amount = 0.0;
        $seen = [];

        foreach ($lines as $l) {
            $l = (array) $l;
            $name = trim((string) ($l['name'] ?? ''));
            $qty = (float) ($l['qty'] ?? 0);
            $price = (float) ($l['price'] ?? 0);

            if ($qty <= 0) {
                continue;                       // a zero row is "not returning this one", not an error
            }
            if ($name === '') {
                return self::no('A return line has no product name.');
            }
            if ($price < 0) {
                return self::no('A return line has a negative price.');
            }

            $k = self::key($l);
            if (isset($seen[$k])) {
                return self::no('The same product appears twice in one return — combine it into one line.');
            }
            $seen[$k] = true;

            if (! array_key_exists($k, $sold)) {
                return self::no($name . ' was not on this sale, so it cannot be returned against it.');
            }

            $max = (float) $sold[$k] - (float) ($returned[$k] ?? 0);
            if ($qty > $max + self::EPS) {
                return self::no('Cannot return ' . self::num($qty) . ' × ' . $name
                    . ' — only ' . self::num(max(0, $max)) . ' left to return on this sale.');
            }

            $clean[] = [
                'product_id' => (int) ($l['product_id'] ?? 0),
                'name'       => $name,
                'price'      => round($price, 2),
                'qty'        => $qty,
            ];
            $amount += $price * $qty;
        }

        if ($clean === []) {
            return self::no('Choose at least one item to return.');
        }

        $amount = round($amount, 2);
        if ($amount <= 0) {
            return self::no('Nothing to refund — the chosen lines add up to zero.');
        }

        return ['ok' => true, 'error' => '', 'clean' => $clean, 'amount' => $amount];
    }

    /** Is this method a valid one at all? Unknown methods fail closed. */
    public static function methodOk(string $m): bool
    {
        return in_array($m, self::METHODS, true);
    }

    /**
     * Does this credit note come off THIS invoice's balance?
     * Only 'invoice' does. 'credit' is held on account for a future invoice (the v4.53 advance
     * shape), and cash/momo money physically leaves — in both of those the invoice balance is
     * not what changed, so it must not move, or two screens start disagreeing again.
     */
    public static function appliesToInvoice(string $m): bool
    {
        return $m === 'invoice';
    }

    /** Does money physically leave the drawer / wallet (and so need a cash-book mirror)? */
    public static function movesCash(string $m): bool
    {
        return $m === 'cash' || $m === 'momo';
    }

    /**
     * v4.89 — does this return credit the customer at all?
     * A REPLACEMENT does not: the faulty carton comes back, a good one goes out, and nobody's
     * money moves. Issuing a credit note for it would tell the books a refund happened that
     * never did, and would double-count once the replacement is delivered.
     */
    public static function issuesCredit(string $m): bool
    {
        return $m !== 'replace';
    }

    /** A replacement leaves an obligation behind: someone still owes the customer goods. */
    public static function owesReplacement(string $m): bool
    {
        return $m === 'replace';
    }

    /** Only a pending return can be received or cancelled — settled ones are history. */
    public static function canConfirm(string $status): bool
    {
        return $status === self::PENDING;
    }

    /**
     * What actually arrived, checked against what was promised. Short deliveries are normal
     * (3 promised, 2 on the truck) and must be accepted as the truth; MORE than promised is not
     * a delivery, it is a new return that never passed the sale caps.
     *
     * @return array{ok:bool,error:string,clean:array,amount:float}
     */
    public static function checkArrival(array $lines, array $requested): array
    {
        $promised = [];
        foreach ($requested as $r) {
            $r = (array) $r;
            $promised[self::key($r)] = (float) ($promised[self::key($r)] ?? 0) + (float) ($r['qty'] ?? 0);
        }

        foreach ($lines as $l) {
            $l = (array) $l;
            $q = (float) ($l['qty'] ?? 0);
            if ($q <= 0) {
                continue;
            }
            $k = self::key($l);
            $max = (float) ($promised[$k] ?? 0);
            if ($q > $max + self::EPS) {
                return self::no('Only ' . self::num($max) . ' × ' . trim((string) ($l['name'] ?? ''))
                    . ' was recorded as coming back — receive that or less, and raise a new return for the rest.');
            }
        }

        // Reuse the ONE line-cleaning path so arrivals get the same price and shape rules.
        return self::check($lines, $promised, []);
    }

    private static function num(float $q): string
    {
        return rtrim(rtrim(number_format($q, 3, '.', ''), '0'), '.') ?: '0';
    }

    /** A store credit has to belong to someone reachable, exactly as the till requires. */
    public static function needsPhone(string $m): bool
    {
        return $m === 'credit';
    }

    /** Human label for a method, for slips and confirmations. */
    public static function methodLabel(string $m): string
    {
        return [
            'invoice' => 'Reduced from this invoice',
            'credit'  => 'Credit on account',
            'cash'    => 'Cash refunded',
            'momo'    => 'Mobile money refunded',
            'replace' => 'Replacement to be sent',
        ][$m] ?? $m;
    }

    private static function no(string $msg): array
    {
        return ['ok' => false, 'error' => $msg, 'clean' => [], 'amount' => 0.0];
    }
}
