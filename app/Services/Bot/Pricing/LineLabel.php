<?php

namespace App\Services\Bot\Pricing;

/**
 * Phase-0 Fix #2 — the ONE canonical human label for a basket / quotation / order line.
 *
 * A line's variant lives in three places that must never disagree: the product name, any chosen
 * modifiers, and (for a sold-by-weight line) its weight. Before this, the chat basket appended the
 * weight but the quotation and order line did not — so the customer saw "Fafda (250g)" in chat and
 * "Fafda" on the quotation. Routing every renderer through this one function keeps them identical.
 *
 * Label only: the price and weight_grams are already correct on the line; this changes the displayed
 * string, never an amount. Pure (no DB, no framework) so it is exhaustively unit-testable.
 */
final class LineLabel
{
    /** name (+ " + modifier, modifier") (+ " (250g)") */
    public static function make(array $line): string
    {
        $name = trim((string) ($line['name'] ?? ''));

        if (! empty($line['modifiers']) && is_array($line['modifiers'])) {
            $mods = array_values(array_filter(array_map(
                fn ($m) => trim((string) ($m['name'] ?? '')),
                $line['modifiers']
            )));
            if ($mods) {
                $name .= ' + ' . implode(', ', $mods);
            }
        }

        if (! empty($line['weight_grams'])) {
            $name .= ' (' . WeightParser::label((int) $line['weight_grams']) . ')';
        }

        return $name;
    }
}
