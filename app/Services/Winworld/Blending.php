<?php
namespace App\Services\Winworld;

/**
 * Blending (BOM / recipe) engine. From the Order Indent Form's Blending
 * section: materials are mixed across up to three extruders (Ext-A/B/C)
 * by percentage of the mixing quantity. "Enter once" -> quantities are
 * computed, never re-typed.
 *
 *   qty_x = pct_x / 100 * mixingQty   (per extruder column)
 *
 * Returns computed lines, per-extruder totals, a grand total, and a
 * validation flag per active column (percentages should sum to 100).
 */
final class Blending
{
    public const COLS = ['a', 'b', 'c'];
    private const EPS = 0.01;

    /**
     * @param float $mixingQty kg per extruder batch
     * @param array<int,array{material:string,pct_a?:float,pct_b?:float,pct_c?:float}> $lines
     * @return array{lines:array,totals:array,total_kgs:float,balanced:array,ok:bool}
     */
    public static function compute(float $mixingQty, array $lines): array
    {
        $mixingQty = max(0.0, $mixingQty);
        $out = [];
        $totals = ['a' => 0.0, 'b' => 0.0, 'c' => 0.0];
        $pctSum = ['a' => 0.0, 'b' => 0.0, 'c' => 0.0];
        $used   = ['a' => false, 'b' => false, 'c' => false];

        foreach ($lines as $line) {
            $row = ['material' => (string)($line['material'] ?? '')];
            foreach (self::COLS as $c) {
                $pct = (float)($line["pct_{$c}"] ?? 0);
                $qty = $pct > 0 ? ($pct / 100) * $mixingQty : 0.0;
                $row["pct_{$c}"] = $pct;
                $row["qty_{$c}"] = round($qty, 3);
                $totals[$c] += $qty;
                $pctSum[$c] += $pct;
                if ($pct > 0) $used[$c] = true;
            }
            $out[] = $row;
        }

        $balanced = [];
        foreach (self::COLS as $c) {
            $balanced[$c] = $used[$c] ? (abs($pctSum[$c] - 100) <= self::EPS) : true;
            $totals[$c] = round($totals[$c], 3);
        }

        return [
            'lines'     => $out,
            'totals'    => $totals,
            'total_kgs' => round($totals['a'] + $totals['b'] + $totals['c'], 3),
            'balanced'  => $balanced, // false on a column whose % != 100
            'ok'        => !in_array(false, $balanced, true),
        ];
    }
}
