<?php

namespace App\Services\Sales;

use App\Models\Order;
use App\Models\Quotation;
use App\Models\Tenant;
use App\Services\Bot\Pricing\CurrencyPricer;
use App\Services\Money\Fx;

/**
 * Converts a quotation into an order. Mirrors PanelApiController::quotationConvert EXACTLY, including
 * the subtle currency rule: on a CONVERTED basis (catalogue in UGX, USD only a display layer) the
 * USD quote is written back as marked-up UGX at the rate captured on the quote; on a NATIVE basis
 * (prices really are USD) values are kept as-is. The OrderObserver assigns order_no on save.
 *
 * Returns ['order' => Order, 'already' => bool].
 * @throws \RuntimeException('no_items') when the quote has no priced/matched lines.
 */
final class QuotationConvertService
{
    public function convert(Tenant $t, Quotation $q): array
    {
        $q->loadMissing('items');

        if ($q->order_id) {
            return ['order' => Order::withoutGlobalScopes()->find($q->order_id), 'already' => true];
        }

        $usd     = strtoupper((string) $q->currency) === 'USD';
        $native  = (bool) data_get($q->meta, 'native')
                   || app(CurrencyPricer::class)->isNativeBasis($t);
        $convert = $usd && ! $native;
        $rate    = $convert ? (float) (data_get($q->meta, 'usd_rate') ?: $t->setting('usdUgx', Fx::forTenant($t)->ratePerUsd('UGX'))) : 1.0;
        if ($convert && $rate <= 0) {
            $rate = (float) $t->setting('usdUgx', Fx::forTenant($t)->ratePerUsd('UGX'));
        }

        $items = []; $textParts = [];
        foreach ($q->items as $it) {
            if (! $it->matched) {
                continue;
            }
            $unit = $convert ? round(((float) $it->unit_price) * $rate) : (float) $it->unit_price;
            // A loose line is labelled "<product> (per piece)" on the quote. Strip that suffix for the
            // ORDER line so the product resolves by name (COGS needs the product_id) and stamp the
            // piece unit so OrderLedgerService costs it per piece, not per carton. The quote PDF and
            // the text summary keep the customer-facing "(per piece)" label.
            $nm = (string) $it->name; $loosePiece = false;
            if (preg_match('/\s*\(per piece\)\s*$/i', $nm)) {
                $loosePiece = true;
                $nm = trim((string) preg_replace('/\s*\(per piece\)\s*$/i', '', $nm));
            }
            $line = ['name' => $nm, 'qty' => (int) $it->qty, 'price' => $unit];
            if ($loosePiece) { $line['unit'] = 'piece'; }
            $items[] = $line;
            $textParts[] = ((int) $it->qty) . 'x ' . $it->name;
        }
        if (! $items) {
            throw new \RuntimeException('no_items');
        }

        $o = new Order();
        $o->tenant_id      = $t->id;
        $o->status         = 'New';
        $o->channel        = 'quote';
        $o->customer_name  = (string) ($q->customer_name ?? '');
        $o->customer_phone = (string) $q->customer_phone;
        $o->items_json     = $items;
        $o->items_text     = implode(', ', $textParts);
        $o->total          = $convert ? round(((float) $q->total) * $rate) : (float) $q->total;
        $o->save();

        $q->order_id = (int) $o->id;
        $q->status   = 'converted';
        $q->save();

        // Mirror the lines into order_items so the V3 order screen / margin / analytics see them
        // (v4.6.4 — converted orders used to show "No line items recorded"). Best-effort.
        try {
            app(OrderItemSync::class)->sync($t, $o);
        } catch (\Throwable $e) { /* never break the conversion */ }

        // BOS spine (Slice 4): record QuoteConverted for the customer timeline. Guarded + never-throws.
        \App\BOS\Emit::customerEvent($t->id, 'QuoteConverted', (string) $q->customer_phone, [
            'quote_no' => (string) $q->quote_no, 'order_no' => (string) $o->order_no,
        ], ['actor' => ['type' => 'system', 'name' => 'convert']]);

        // v4.43 — a converted quotation IS a confirmed order, but it lands on status 'New', which
        // is not in SendOrderStatusNotification's map at all: until now this path told the customer
        // NOTHING. They agreed to a quotation, we turned it into an order at whatever the current
        // rate and catalogue said, and the first they heard of the real figures was the invoice on
        // delivery. Send them the order they are actually going to receive.
        try {
            \App\Jobs\NotifyOrderConfirmation::dispatch($t->id, (int) $o->id, 'quote_converted');
        } catch (\Throwable $e) { /* never break the conversion over a message */ }

        return ['order' => $o, 'already' => false];
    }
}
