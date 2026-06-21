<?php
namespace App\Services\Bot\Merchant;

/**
 * Pure typo guard for price changes. Returns a human warning string when a new price
 * looks like a mistake (big jump, or a digit added/removed e.g. 9000 vs 90000). The
 * change is still allowed on YES — this only annotates the confirmation summary.
 */
class PriceGuard
{
    public static function warn(?int $old, int $new): ?string
    {
        if ($new <= 0) return 'price looks invalid';
        if ($old === null || $old <= 0) return null;
        if (strlen((string) $new) !== strlen((string) $old)) {
            return 'digit count changed (' . number_format($old) . ' → ' . number_format($new) . ') — check for a typo';
        }
        $ratio = $new / $old;
        if ($ratio >= 2.0 || $ratio <= 0.5) {
            return 'big change (' . number_format($old) . ' → ' . number_format($new) . ') — please double-check';
        }
        return null;
    }
}
