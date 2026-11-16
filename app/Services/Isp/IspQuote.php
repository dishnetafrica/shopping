<?php

namespace App\Services\Isp;

/**
 * PURE. An Internet-provider quotation separates what the customer pays ONCE (kit, installation,
 * accessories) from what they pay EVERY MONTH (the Starlink or fibre plan), and says which service
 * the quotation is for.
 *
 * Works on lines the existing price calculator has ALREADY priced (QuotationService::priced: `_unit`,
 * `_sub` after discount, ex-VAT). It never sets or changes a price; it only groups and totals. A line
 * is monthly only when its product is explicitly marked monthly in the catalogue; anything else is a
 * one-time charge.
 */
final class IspQuote
{
    public const BILLING = ['one_off', 'monthly'];

    public const SERVICE_LINES = ['starlink', 'fiber'];

    /**
     * @param  array $lines     priced lines (matched ones are used)
     * @param  array $products  lower-cased product name => ['billing' => ?string, 'service_line' => ?string]
     * @param  float $vatRate   e.g. 0.18 for 18%; 0 when no tax line is printed
     * @return array{one_off:array,monthly:array,one_off_total:array,monthly_total:array,service_lines:string[]}
     */
    public static function split(array $lines, array $products, float $vatRate = 0.0): array
    {
        $groups = ['one_off' => [], 'monthly' => []];
        $services = [];
        foreach ($lines as $l) {
            if (empty($l['matched'])) {
                continue;
            }
            $info = $products[mb_strtolower(trim((string) ($l['name'] ?? '')))] ?? [];
            $billing = in_array($info['billing'] ?? null, self::BILLING, true) ? $info['billing'] : 'one_off';
            $l['billing'] = $billing;
            $groups[$billing][] = $l;
            $svc = $info['service_line'] ?? null;
            if (in_array($svc, self::SERVICE_LINES, true) && ! in_array($svc, $services, true)) {
                $services[] = $svc;
            }
        }
        sort($services);                                    // fiber before starlink: stable for tests

        return [
            'one_off'       => $groups['one_off'],
            'monthly'       => $groups['monthly'],
            'one_off_total' => self::totals($groups['one_off'], $vatRate),
            'monthly_total' => self::totals($groups['monthly'], $vatRate),
            'service_lines' => $services,
        ];
    }

    /** @return array{before:float,discount:float,subtotal:float,vat:float,total:float} */
    public static function totals(array $lines, float $vatRate): array
    {
        $before = 0.0;
        $sub = 0.0;
        foreach ($lines as $l) {
            $before += (float) ($l['qty'] ?? 0) * (float) ($l['_unit'] ?? $l['price'] ?? 0);
            $sub += (float) ($l['_sub'] ?? $l['sum'] ?? 0);
        }
        $vat = $sub * max(0.0, $vatRate);

        return [
            'before'   => round($before, 2),
            'discount' => round($before - $sub, 2),
            'subtotal' => round($sub, 2),
            'vat'      => round($vat, 2),
            'total'    => round($sub + $vat, 2),
        ];
    }
}
