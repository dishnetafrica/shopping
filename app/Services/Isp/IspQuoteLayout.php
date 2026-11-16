<?php

namespace App\Services\Isp;

/**
 * PURE. The Internet-provider quotation page (HTML for dompdf, A4), after DishNet South Sudan's
 * UISP "Smart Quote Template v5": dark header with a red rule and a STARLINK / FIBER badge, bill-to
 * and from panels, the charges, validity, terms, and a prepared-by / accepted-by signature row.
 *
 * Differences from the UISP template, on purpose:
 *   - One-time charges and monthly charges are two tables with their own totals, never one sum.
 *   - The validity line prints the quotation's real expiry date, not a fixed "7 days".
 *   - "Prepared by" is the salesperson who made the quotation (with their phone), not a default name.
 *   - Contact details, tagline and terms all come from data; nothing about the business is fixed here.
 *   - Terms print only when the caller passes PUBLISHED terms; with none, the section is left out
 *     rather than filled with defaults (the platform's general defaults are for another market).
 *
 * All text is escaped. Tables + inline styles only, so dompdf renders it as designed.
 */
final class IspQuoteLayout
{
    private const INK = '#141414';

    private const RED = '#D41C1C';

    /**
     * @param array $d {
     *   company: string, address_lines: string[], phone?: string, email?: string, website?: string, tagline?: string,
     *   quote_no: string, date: string, valid_until: string, currency: string,
     *   customer: {name: string, company?: string, address_lines?: string[], phone?: string, email?: string},
     *   service_lines: string[], split: (IspQuote::split result), vat_label?: string,
     *   terms: array<int,{title:string,items:array<int,{label:string,text:string}>}>,
     *   prepared_by: {name?: string, phone?: string}, notes?: string
     * }
     */
    public static function html(array $d): string
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $money = fn ($n) => number_format((float) $n, 2);
        $cur = $e($d['currency'] ?? '');
        $ink = self::INK;
        $red = self::RED;

        // Badges for the service(s) on this quotation.
        $badges = '';
        foreach ((array) ($d['service_lines'] ?? []) as $svc) {
            $bg = $svc === 'fiber' ? "background:{$red};" : 'background:#333;border:1px solid #555;';
            $badges .= '<span style="display:inline-block;' . $bg . 'color:#fff;font-size:8px;font-weight:800;letter-spacing:1.5px;padding:3px 8px 2px;border-radius:3px;margin-right:4px;">'
                . ($svc === 'fiber' ? 'FIBER' : 'STARLINK') . '</span>';
        }

        $lines = fn (array $xs) => implode('<br>', array_map($e, array_values(array_filter(array_map('trim', $xs), fn ($x) => $x !== ''))));
        $c = (array) ($d['customer'] ?? []);
        $custLines = $lines(array_merge((array) ($c['address_lines'] ?? []), [(string) ($c['phone'] ?? ''), (string) ($c['email'] ?? '')]));
        $fromLines = $lines(array_merge((array) ($d['address_lines'] ?? []), [(string) ($d['phone'] ?? ''), (string) ($d['email'] ?? ''), (string) ($d['website'] ?? '')]));

        $split = (array) ($d['split'] ?? []);
        $vatLabel = $e($d['vat_label'] ?? 'Tax');
        $section = function (string $title, array $rows, array $t, string $totalLabel) use ($e, $money, $cur, $ink, $vatLabel): string {
            if (! $rows) {
                return '';
            }
            $th = 'background:' . $ink . ';color:#fff;font-size:9px;text-transform:uppercase;letter-spacing:0.8px;padding:8px 10px;font-weight:700;';
            $h = '<div style="font-size:9px;text-transform:uppercase;letter-spacing:2px;color:#666;font-weight:700;margin:4px 0 5px;">' . $e($title) . '</div>'
                . '<table style="width:100%;border-collapse:collapse;margin-bottom:4px;"><thead><tr>'
                . '<th style="' . $th . 'text-align:left;width:5%;">#</th><th style="' . $th . 'text-align:left;width:47%;">Description</th>'
                . '<th style="' . $th . 'text-align:right;width:10%;">Qty</th><th style="' . $th . 'text-align:right;width:18%;">Unit price</th>'
                . '<th style="' . $th . 'text-align:right;width:20%;">Amount (' . $cur . ')</th></tr></thead><tbody>';
            foreach (array_values($rows) as $i => $l) {
                $bg = $i % 2 === 1 ? 'background:#fafafa;' : '';
                $td = 'padding:9px 10px;font-size:11px;border-bottom:1px solid #f0f0f0;';
                $unit = trim((string) ($l['unit'] ?? ''));
                $h .= '<tr style="' . $bg . '"><td style="' . $td . 'color:#888;font-weight:600;">' . ($i + 1) . '</td>'
                    . '<td style="' . $td . '"><strong style="font-size:12px;">' . $e($l['name'] ?? '') . '</strong>'
                    . ($unit !== '' ? '<br><span style="font-size:8px;color:#9B9B9B;text-transform:uppercase;letter-spacing:0.5px;">' . $e($unit) . '</span>' : '') . '</td>'
                    . '<td style="' . $td . 'text-align:right;">' . $e((string) ($l['qty'] ?? '')) . '</td>'
                    . '<td style="' . $td . 'text-align:right;">' . $money($l['_unit'] ?? $l['price'] ?? 0) . '</td>'
                    . '<td style="' . $td . 'text-align:right;font-weight:700;">' . $money($l['_sub'] ?? $l['sum'] ?? 0) . '</td></tr>';
            }
            $h .= '</tbody></table>';
            $row = fn ($label, $value, $extra = '') => '<tr><td style="padding:6px 14px;font-size:11px;color:#6B6B6B;border-bottom:1px solid #eee;' . $extra . '">' . $label . '</td>'
                . '<td style="padding:6px 14px;font-size:11px;font-weight:700;text-align:right;border-bottom:1px solid #eee;' . $extra . '">' . $value . '</td></tr>';
            $tot = $row('Subtotal', $money($t['before'] ?? 0));
            if ((float) ($t['discount'] ?? 0) > 0.004) {
                $tot .= $row('Discount', '&minus;' . $money($t['discount']), 'color:#059669;');
            }
            if ((float) ($t['vat'] ?? 0) > 0.004) {
                $tot .= $row($vatLabel, $money($t['vat']));
            }
            $tot .= '<tr><td style="padding:9px 14px;font-size:14px;font-weight:800;color:#fff;background:' . $ink . ';">' . $e($totalLabel) . '</td>'
                . '<td style="padding:9px 14px;font-size:14px;font-weight:800;text-align:right;color:#fff;background:' . $ink . ';">' . $cur . ' ' . $money($t['total'] ?? 0) . '</td></tr>';

            return $h . '<table style="width:100%;border-collapse:collapse;margin-bottom:14px;"><tr><td style="width:56%;"></td><td style="width:44%;padding:0;">'
                . '<table style="width:100%;border-collapse:collapse;background:#f8f8f8;">' . $tot . '</table></td></tr></table>';
        };
        $charges = $section('One-time charges', (array) ($split['one_off'] ?? []), (array) ($split['one_off_total'] ?? []), 'One-time total')
            . $section('Monthly charges', (array) ($split['monthly'] ?? []), (array) ($split['monthly_total'] ?? []), 'Monthly total');

        // Terms: only what the caller passes (published terms). Three columns, numbered as given.
        $terms = '';
        foreach ((array) ($d['terms'] ?? []) as $block) {
            $items = array_values((array) ($block['items'] ?? []));
            if (! $items) {
                continue;
            }
            $cols = array_chunk($items, (int) ceil(count($items) / 3));
            $cells = '';
            $n = 1;
            foreach ($cols as $ci => $col) {
                $pad = $ci === 0 ? '0 5px 0 0' : ($ci === count($cols) - 1 ? '0 0 0 5px' : '0 5px');
                $cells .= '<td style="vertical-align:top;padding:' . $pad . ';width:33%;font-size:9px;color:#444;line-height:1.45;">';
                foreach ($col as $it) {
                    $cells .= '<div style="padding:2px 0;"><b style="color:' . $red . ';">*</b> <b style="color:' . $ink . ';">' . $n++ . '. ' . $e($it['label'] ?? '') . ':</b> ' . $e($it['text'] ?? '') . '</div>';
                }
                $cells .= '</td>';
            }
            $terms .= '<div style="font-size:9px;text-transform:uppercase;letter-spacing:2px;color:#666;font-weight:700;margin:6px 0 5px;">' . $e($block['title'] ?? 'Terms & conditions') . '</div>'
                . '<table style="width:100%;border-collapse:collapse;"><tr>' . $cells . '</tr></table>';
        }
        if ($terms !== '') {
            $terms .= '<div style="font-size:8px;font-weight:700;color:' . $ink . ';margin-top:5px;">By accepting this quotation, you agree to the above terms and conditions.</div>';
        }

        $notes = trim((string) ($d['notes'] ?? ''));
        $notesHtml = $notes === '' ? '' : '<table style="width:100%;border-collapse:collapse;margin-bottom:12px;"><tr><td style="background:#fffbeb;border:1px solid #fde68a;padding:9px 14px;">'
            . '<div style="font-size:8px;text-transform:uppercase;letter-spacing:1px;color:#92400e;font-weight:700;margin-bottom:3px;">Notes</div>'
            . '<div style="font-size:10px;color:#78350f;line-height:1.5;">' . nl2br($e($notes)) . '</div></td></tr></table>';

        $p = (array) ($d['prepared_by'] ?? []);
        $prep = trim((string) ($p['name'] ?? ''));
        $prepPhone = trim((string) ($p['phone'] ?? ''));
        $company = $e($d['company'] ?? '');
        $footerMid = trim((string) ($d['tagline'] ?? ''));
        $footerRight = implode(' | ', array_filter([
            $prepPhone !== '' ? 'Sales: ' . $prepPhone : (trim((string) ($d['phone'] ?? '')) !== '' ? 'Sales: ' . trim((string) $d['phone']) : ''),
            trim((string) ($d['website'] ?? '')),
        ]));
        $city = trim((string) (($d['address_lines'] ?? [])[count((array) ($d['address_lines'] ?? [])) - 1] ?? ''));

        return '<!doctype html><html><head><meta charset="utf-8"><style>'
            . '@page{margin:0;size:A4}body{font-family:Helvetica,Arial,sans-serif;font-size:12px;color:' . $ink . ';line-height:1.45;margin:0;padding:0}'
            . '*{box-sizing:border-box}table{border-collapse:collapse}td,th{border:none}.dn-footer{position:fixed;bottom:0;left:0;right:0;width:100%}'
            . '</style></head><body>'
            // footer, pinned to the bottom of every page
            . '<div class="dn-footer"><div style="height:3px;background:' . $red . ';"></div><table style="width:100%;background:' . $ink . ';"><tr>'
            . '<td style="padding:8px 28px;font-size:9px;color:#aaa;">' . $company . ($city !== '' ? ' &middot; ' . $e($city) : '') . '</td>'
            . '<td style="padding:8px 28px;font-size:10px;color:#999;text-align:center;font-style:italic;font-weight:600;">' . $e($footerMid) . '</td>'
            . '<td style="padding:8px 28px;font-size:9px;color:#999;text-align:right;">' . $e($footerRight) . '</td></tr></table></div>'
            // header
            . '<table style="width:100%;background:' . $ink . ';"><tr><td style="padding:18px 28px 14px;vertical-align:middle;">'
            . '<table><tr><td style="padding:0;vertical-align:middle;"><div style="font-size:28px;font-weight:900;color:#fff;letter-spacing:-0.5px;">' . $company . '</div>'
            . '<div style="height:5px;width:120px;background:' . $red . ';margin-top:3px;"></div></td>'
            . '<td style="padding:0 0 0 10px;vertical-align:middle;">' . $badges . '</td></tr></table>'
            . '<div style="font-size:9px;color:#bbb;margin-top:4px;letter-spacing:0.3px;">Internet Service Provider' . ($city !== '' ? ' &middot; ' . $e($city) : '') . '</div></td>'
            . '<td style="padding:18px 28px 14px;text-align:right;vertical-align:middle;"><div style="font-size:10px;text-transform:uppercase;letter-spacing:2px;color:#bbb;">Quotation</div>'
            . '<div style="font-size:22px;font-weight:900;color:#fff;letter-spacing:0.5px;">' . $e($d['quote_no'] ?? '') . '</div>'
            . '<div style="font-size:9px;color:#aaa;">' . $e($d['date'] ?? '') . '</div></td></tr></table>'
            . '<div style="height:3px;background:' . $red . ';"></div>'
            // content
            . '<div style="padding:16px 28px 50px;">'
            . '<table style="width:100%;border-collapse:separate;border-spacing:10px 0;margin-bottom:16px;"><tr>'
            . '<td style="width:50%;background:#f8f8f8;padding:12px 14px;vertical-align:top;border-left:3px solid ' . $red . ';">'
            . '<div style="font-size:8px;text-transform:uppercase;letter-spacing:1.5px;color:#777;font-weight:700;margin-bottom:4px;">Bill to</div>'
            . '<div style="font-size:14px;font-weight:700;margin-bottom:3px;">' . $e($c['name'] ?? '') . '</div>'
            . (trim((string) ($c['company'] ?? '')) !== '' ? '<div style="font-size:10px;color:#6B6B6B;font-weight:600;">' . $e($c['company']) . '</div>' : '')
            . '<div style="font-size:10px;color:#6B6B6B;line-height:1.5;">' . $custLines . '</div></td>'
            . '<td style="width:50%;background:#f8f8f8;padding:12px 14px;vertical-align:top;border-left:3px solid ' . $ink . ';">'
            . '<div style="font-size:8px;text-transform:uppercase;letter-spacing:1.5px;color:#777;font-weight:700;margin-bottom:4px;">From</div>'
            . '<div style="font-size:14px;font-weight:700;margin-bottom:3px;">' . $company . '</div>'
            . '<div style="font-size:10px;color:#6B6B6B;line-height:1.5;">' . $fromLines . '</div></td></tr></table>'
            . $charges
            . '<table style="width:100%;border-collapse:collapse;margin-bottom:12px;"><tr><td style="background:#f0fdf4;border:1px solid #86efac;padding:9px 14px;font-size:10px;color:#065f46;">'
            . 'Valid until <strong>' . $e($d['valid_until'] ?? '') . '</strong>. Prices are subject to change after this date.</td></tr></table>'
            . $notesHtml . $terms
            . '<table style="width:100%;border-collapse:collapse;margin-top:14px;border-top:1px solid #eee;"><tr>'
            . '<td style="width:45%;padding:8px 0 0;vertical-align:top;"><div style="font-size:8px;text-transform:uppercase;letter-spacing:1px;color:#777;font-weight:700;">Prepared by</div>'
            . '<div style="border-top:1.5px solid ' . $ink . ';padding-top:4px;margin-top:16px;"><span style="font-size:10px;font-weight:700;">' . $e($prep !== '' ? $prep : '______________________') . '</span>'
            . '<span style="font-size:9px;color:#6B6B6B;float:right;">' . $company . '</span></div></td>'
            . '<td style="width:10%;"></td>'
            . '<td style="width:45%;padding:8px 0 0;vertical-align:top;"><div style="font-size:8px;text-transform:uppercase;letter-spacing:1px;color:#777;font-weight:700;">Accepted by (customer)</div>'
            . '<div style="border-top:1.5px solid ' . $ink . ';padding-top:4px;margin-top:16px;"><span style="font-size:10px;font-weight:700;">______________________________</span>'
            . '<span style="font-size:9px;color:#6B6B6B;float:right;">Date: ____________</span></div></td></tr></table>'
            . '</div></body></html>';
    }
}
