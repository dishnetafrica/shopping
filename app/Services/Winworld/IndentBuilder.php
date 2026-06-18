<?php
namespace App\Services\Winworld;

/**
 * Pure helpers for the Order Indent: clone-from-last field mapping and the
 * controlled indent number format (e.g. 003-090626 = seq-DDMMYY, matching
 * OIF WIL/MKT/OIF/001). Persistence lives in the controller; this is testable.
 */
final class IndentBuilder
{
    /** Header + spec fields carried over when cloning a previous indent. */
    public const CLONE_FIELDS = [
        'customer_id','customer_name','item_id','product_name','sales_person',
        'order_qty_pcs','mixing_qty','priority','sample_available','sdh_remarks','pdh_remarks',
        'needs_blending','needs_extrusion','needs_printing','needs_cutting',
        'ext_width','ext_gusset','ext_gauge','ext_film_colour','ext_weight_per_roll','ext_type_of_roll','ext_sample',
        'prn_specification','prn_no_colours','prn_colours','prn_single_double','prn_direction','prn_gap_top','prn_gap_bottom','prn_sample',
        'cut_type','cut_bag_size','cut_sealing','cut_bottom_gusset','cut_handle_punch','cut_handle_position','cut_hole_punch','cut_hole_positions','cut_sample',
    ];

    /**
     * Build a fresh indent payload from a previous one ("same as last order").
     * Identity, date, status and derived values are reset; specs and blend
     * lines are carried over for the user to tweak.
     *
     * @return array{indent:array,blends:array}
     */
    public static function cloneData(array $src, array $blends = []): array
    {
        $out = [];
        foreach (self::CLONE_FIELDS as $f) {
            $out[$f] = $src[$f] ?? null;
        }
        $out['status']         = 'Open';
        $out['indent_no']      = '';    // assigned on save
        $out['date_of_indent'] = null;  // set to today on save

        $cb = []; $i = 1;
        foreach ($blends as $b) {
            $cb[] = [
                'line_no'       => $i++,
                'material_id'   => $b['material_id']   ?? null,
                'material_name' => $b['material_name'] ?? '',
                'pct_a'         => (float)($b['pct_a'] ?? 0),
                'pct_b'         => (float)($b['pct_b'] ?? 0),
                'pct_c'         => (float)($b['pct_c'] ?? 0),
            ];
        }
        return ['indent' => $out, 'blends' => $cb];
    }

    /** Controlled indent number: seq (3) + '-' + DDMMYY. */
    public static function nextIndentNo(int $seq, \DateTimeInterface $date): string
    {
        return str_pad((string) max(1, $seq), 3, '0', STR_PAD_LEFT) . '-' . $date->format('dmy');
    }
}
