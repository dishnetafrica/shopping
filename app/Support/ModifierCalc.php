<?php
namespace App\Support;

/**
 * Pure, framework-free pricing + validation for modifier groups. No DB/Eloquent so it
 * can be unit-tested directly and reused by the bot, the storefront, and the panel.
 *
 * Model: an "included" choice is simply an option with price_delta 0 (free); a premium
 * choice carries a surcharge delta; extra breads/rice are ordered as their own menu lines,
 * not modifiers. A line's unit price = product price + sum of chosen option deltas.
 */
class ModifierCalc
{
    /** Unit price for one line = base + sum(selected option deltas). $selections: [['price_delta'=>x], ...] */
    public static function unitPrice(float $base, array $selections): float
    {
        $sum = 0.0;
        foreach ($selections as $s) {
            $sum += (float) ($s['price_delta'] ?? 0);
        }
        return max(0.0, $base + $sum);
    }

    /**
     * Validate the customer's picks against a dish's groups.
     * $groups: [['id'=>, 'name'=>, 'required'=>bool, 'min_select'=>int, 'max_select'=>int], ...]
     * $picksByGroup: [groupId => count_of_selected_options]
     * Returns a list of human-readable error strings (empty array = valid).
     */
    public static function validate(array $groups, array $picksByGroup): array
    {
        $errors = [];
        foreach ($groups as $g) {
            $id    = $g['id'];
            $name  = (string) ($g['name'] ?? 'choice');
            $n     = (int) ($picksByGroup[$id] ?? 0);
            $min   = (int) ($g['min_select'] ?? ($g['required'] ?? false ? 1 : 0));
            $max   = (int) ($g['max_select'] ?? 1);
            if (! empty($g['required']) && $n < max(1, $min)) {
                $errors[] = "Please choose your {$name}.";
            } elseif ($n < $min) {
                $errors[] = "Choose at least {$min} for {$name}.";
            }
            if ($max > 0 && $n > $max) {
                $errors[] = "You can pick at most {$max} for {$name}.";
            }
        }
        return $errors;
    }
}
