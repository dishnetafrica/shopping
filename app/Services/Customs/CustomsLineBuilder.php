<?php

namespace App\Services\Customs;

use App\Models\Order;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Tenant;

/**
 * Turns an Order or a Quotation into the normalised line array the customs renderers
 * (CommercialInvoiceRenderer / PackingListRenderer) print — resolving each line's part
 * number, HS code, country of origin and net/gross weight from the matching Product.
 *
 * READ-ONLY: it only reads Order/Quotation/Product/Tenant and returns an array. It never
 * writes — a customs document is paperwork, not a financial transaction.
 *
 *   Orders    link to a product by OrderItem.product_id (exact).
 *   Quotations have no product_id on their items (QuotationItem is name + price), so lines
 *             are matched to products by case-folded name — the same approach the invoicing
 *             layer already uses to re-price quotation lines.
 *
 * Returns:
 *   [ 'doc_ref','date','buyer_name','buyer_phone','buyer_address','currency',
 *     'lines' => [ ['name','part_no','hs_code','origin','qty','unit','unit_price','amount','net_g','gross_g'], … ],
 *     'subtotal','packages','net_g','gross_g' ]
 */
final class CustomsLineBuilder
{
    public function fromOrder(Tenant $t, Order $o): array
    {
        $lines = [];
        try {
            foreach ($o->items()->get() as $it) {
                $p = $it->product_id ? Product::where('tenant_id', $t->id)->find($it->product_id) : null;
                $lines[] = $this->line($t, (string) $it->name, (float) $it->qty, (float) $it->price, $p);
            }
        } catch (\Throwable $e) {
        }
        if (! $lines) {   // legacy bot orders: items_json blob, matched by name
            $legacy = is_array($o->items_json) ? $o->items_json : (json_decode((string) $o->items_json, true) ?: []);
            $byName = $this->byName($t);
            foreach ($legacy as $it) {
                if (is_array($it) && ! empty($it['name'])) {
                    $p = $byName[mb_strtolower(trim((string) $it['name']))] ?? null;
                    $lines[] = $this->line($t, (string) $it['name'], (float) ($it['qty'] ?? 1), (float) ($it['price'] ?? 0), $p);
                }
            }
        }

        return $this->pack($lines, [
            'doc_ref'       => (string) ($o->order_no ?: ('#' . $o->id)),
            'date'          => optional($o->created_at)->format('d M Y') ?: now()->format('d M Y'),
            'buyer_name'    => (string) ($o->customer_name ?? ''),
            'buyer_phone'   => (string) ($o->customer_phone ?? ''),
            'buyer_address' => (string) ($o->location ?? ''),
            'currency'      => $o->sellCurrency(),
        ]);
    }

    public function fromQuotation(Tenant $t, Quotation $q): array
    {
        $byName = $this->byName($t);
        $lines = [];
        try {
            foreach ($q->items()->get() as $it) {
                $p = $byName[mb_strtolower(trim((string) $it->name))] ?? null;
                $lines[] = $this->line($t, (string) $it->name, (float) $it->qty, (float) $it->unit_price, $p);
            }
        } catch (\Throwable $e) {
        }

        return $this->pack($lines, [
            'doc_ref'       => (string) ($q->quote_no ?: ('#' . $q->id)),
            'date'          => optional($q->created_at)->format('d M Y') ?: now()->format('d M Y'),
            'buyer_name'    => (string) ($q->customer_name ?? ''),
            'buyer_phone'   => (string) ($q->customer_phone ?? ''),
            'buyer_address' => (string) ($q->delivery_address ?? ''),
            'currency'      => (string) ($q->currency ?: 'USD'),
        ]);
    }

    /** @return array<string,Product> products keyed by case-folded name */
    private function byName(Tenant $t): array
    {
        $m = [];
        $cols = ['name', 'oem_number', 'hs_code', 'origin_country', 'reference_weight_grams', 'gross_weight_grams', 'unit_label'];
        foreach (Product::where('tenant_id', $t->id)->whereNull('archived_at')->get($cols) as $p) {
            $m[mb_strtolower(trim((string) $p->name))] = $p;
        }

        return $m;
    }

    private function line(Tenant $t, string $name, float $qty, float $unitPrice, ?Product $p): array
    {
        // Weight precedence: the explicit gram columns win; otherwise fall back to parsing the
        // free-text unit_size ("2.89 kg", "890 g") — that is where a CSV "Net Weight" column lands,
        // so a catalogue imported with weights as text still prints them on the customs documents.
        $net   = ($p && (int) $p->reference_weight_grams > 0) ? (int) $p->reference_weight_grams
               : ($p ? $this->parseGrams((string) $p->unit_size) : 0);
        $gross = ($p && (int) $p->gross_weight_grams > 0) ? (int) $p->gross_weight_grams : 0;
        $origin = ($p && trim((string) $p->origin_country) !== '')
            ? (string) $p->origin_country
            : (string) $t->setting('customs_default_origin', '');

        return [
            'name'       => $name,
            'part_no'    => $p ? (string) ($p->oem_number ?? '') : '',
            'hs_code'    => $p ? (string) ($p->hs_code ?? '') : '',
            'origin'     => $origin,
            'qty'        => $qty,
            'unit'       => $p ? (string) ($p->unit_label ?? '') : '',
            'unit_price' => $unitPrice,
            'amount'     => round($unitPrice * $qty, 2),
            'net_g'      => $net,
            'gross_g'    => $gross,
        ];
    }

    /** Parse a free-text weight ("2.89 kg", "890 g", "2.5kg") into integer grams; 0 if none. */
    private function parseGrams(string $s): int
    {
        $s = mb_strtolower(trim($s));
        if ($s === '' || ! preg_match('/([0-9]+(?:\.[0-9]+)?)\s*(kg|kgs|g|gram|grams|gm)?/', $s, $m)) {
            return 0;
        }
        $n = (float) $m[1];
        $unit = $m[2] ?? '';
        // Default to kg when a bare number is given with no unit (catalogue weights are usually kg),
        // except an integer >= 100 with no unit reads as grams.
        if ($unit === 'g' || $unit === 'gram' || $unit === 'grams' || $unit === 'gm') {
            return (int) round($n);
        }
        if ($unit === '' && $n >= 100 && $n == (int) $n) {
            return (int) round($n);
        }

        return (int) round($n * 1000);
    }

    private function pack(array $lines, array $meta): array
    {
        $subtotal = 0.0;
        $packages = 0;
        $grossG = 0;
        $netG = 0;
        foreach ($lines as $l) {
            $subtotal += (float) $l['amount'];
            $packages += (int) round($l['qty']);
            $perGross = $l['gross_g'] ?: $l['net_g'];
            $grossG += (int) round($perGross * $l['qty']);
            $netG += (int) round($l['net_g'] * $l['qty']);
        }

        return $meta + [
            'lines'    => $lines,
            'subtotal' => round($subtotal, 2),
            'packages' => $packages,
            'net_g'    => $netG,
            'gross_g'  => $grossG,
        ];
    }
}
