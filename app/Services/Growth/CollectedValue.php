<?php declare(strict_types=1);

namespace App\Services\Growth;

/**
 * v4.61 — what a customer has actually paid you, in one currency.
 *
 * PURE. Receipts in, per-customer totals out. No models, no database, no clock.
 *
 * The Growth screen used to rank customers by what they had ORDERED in the trailing year. Those are
 * two different numbers and only one of them is a fact: a customer who took USD 7,836 of stock and
 * paid USD 3,000 has given the shop USD 3,000, and the rest is a receivable. Ranking a call list by
 * what was ordered puts the customer who does not pay at the top of it.
 *
 * Two rules do all the work here, and both exist to stop a confident wrong number:
 *
 *   ONE CURRENCY   A receipt whose base-currency value was never stamped is real money that cannot
 *                  be added to a USD total. It is counted separately and said out loud, never
 *                  folded in — adding an SSP figure to a USD column inflates every ranking on the
 *                  page and nothing on screen would show why.
 *
 *   ZERO IS A      A customer who ordered this year with no receipt recorded against them shows
 *   CLAIM          USD 0, and that claim is only safe if the shop really does record its receipts
 *                  here. Those customers are counted so the screen can say how many there are: a
 *                  handful is credit, and a hundred means the money is being banked somewhere this
 *                  system cannot see and the whole column is an understatement.
 */
final class CollectedValue
{
    /** Below this, a total is rounding dust rather than money. */
    private const EPS = 0.005;

    /**
     * One receipt in base currency, or null when it cannot be stated in one.
     *
     * @param  ?float   $stamped   usd_amount, as recorded at the time the money was taken
     * @param  ?string  $currency  what the customer actually handed over
     * @param  ?float   $amount    how much of it
     */
    public static function usd(?float $stamped, ?string $currency, ?float $amount): ?float
    {
        if ($stamped !== null && $stamped > 0) {
            return $stamped;
        }
        // Not stamped: safe only when the money was in base currency to begin with. Anything else
        // needs a rate nobody recorded, and inventing one here would be a guess wearing a total.
        $cur = strtoupper(trim((string) $currency));
        if ($cur === '' || $cur === 'USD') {
            return (float) $amount;
        }

        return null;
    }

    /**
     * Add receipts to per-customer tallies.
     *
     * A receipt from somebody with no tally is ignored rather than creating one: this screen is
     * about a buying rhythm, and a payer with no orders in two years has no rhythm to be late on.
     *
     * @param  array<string,array{value:float, unpriced:int, ordered:bool}>  $byPhone  keyed by normalised digits
     * @param  array<int,array{phone:string, stamped:?float, currency:?string, amount:float, day:int}>  $receipts
     * @param  int  $valFrom  day number the trailing window opens on, inclusive
     * @return array{0: array<string,array>, 1: int}  the tallies, and how many receipts had no base-currency value
     */
    public static function apply(array $byPhone, array $receipts, int $valFrom): array
    {
        $unpriced = 0;

        foreach ($receipts as $r) {
            $phone = (string) ($r['phone'] ?? '');
            if ($phone === '' || ! isset($byPhone[$phone])) {
                continue;
            }
            if ((int) ($r['day'] ?? 0) < $valFrom) {
                continue;
            }

            $usd = self::usd(
                isset($r['stamped']) ? (float) $r['stamped'] : null,
                $r['currency'] ?? null,
                (float) ($r['amount'] ?? 0)
            );

            if ($usd === null) {
                $unpriced++;
                $byPhone[$phone]['unpriced'] = (int) ($byPhone[$phone]['unpriced'] ?? 0) + 1;
                continue;
            }
            $byPhone[$phone]['value'] = round((float) ($byPhone[$phone]['value'] ?? 0) + $usd, 2);
        }

        return [$byPhone, $unpriced];
    }

    /**
     * How many customers ordered inside the window with nothing recorded against them at all.
     *
     * A customer with an UNPRICED receipt is deliberately not counted. They did pay; the shop simply
     * cannot state it in one currency, which is a different problem with its own number. Counting
     * them here would make a currency gap look like a collection failure.
     */
    public static function unpaid(array $byPhone): int
    {
        $n = 0;
        foreach ($byPhone as $c) {
            if (! empty($c['ordered'])
                && (float) ($c['value'] ?? 0) <= self::EPS
                && (int) ($c['unpriced'] ?? 0) === 0) {
                $n++;
            }
        }

        return $n;
    }
}
