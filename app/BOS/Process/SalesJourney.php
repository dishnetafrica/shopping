<?php

namespace App\BOS\Process;

use App\BOS\Event;
use App\Models\CustomerPayment;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Quotation;

/**
 * Order-to-Cash, read as a process (BOS doc 3). Given a customer, it reports where their latest deal
 * stands across the five stages — Quote → Accept → Invoice → Pay → Receipt — and the single next best
 * action to advance it. Pure read: every fact comes from a live object (Quotation/Invoice/Payment) or
 * the event spine; it NEVER writes and NEVER mutates. The next action only ever deep-links to the
 * existing screen where the verb already lives, so the rule engine (R3/R4) stays the sole gate.
 */
final class SalesJourney
{
    public function __construct(private int $tenantId, private int $customerId) {}

    /** @return array{stage:string,steps:array<int,array>,next:?array,outstanding:float} */
    public function build(): array
    {
        $empty = ['stage' => 'none', 'steps' => [], 'next' => null, 'outstanding' => 0.0];

        $c = CustomerProfile::withoutGlobalScopes()->where('tenant_id', $this->tenantId)->find($this->customerId);
        if (! $c) return $empty;

        // Match the customer across documents by phone, tolerating both stored forms (raw + digits-only).
        $digits = preg_replace('/\D/', '', (string) $c->phone);
        $phones = array_values(array_unique(array_filter([(string) $c->phone, (string) $digits])));
        if (! $phones) return $empty;

        $quote = Quotation::withoutGlobalScopes()->where('tenant_id', $this->tenantId)
            ->whereIn('customer_phone', $phones)->latest('id')->first();
        $anyInv = Invoice::withoutGlobalScopes()->where('tenant_id', $this->tenantId)
            ->whereIn('customer_phone', $phones)->where('status', '!=', 'void')->latest('id')->first();
        $openInv = Invoice::withoutGlobalScopes()->where('tenant_id', $this->tenantId)
            ->whereIn('customer_phone', $phones)->whereIn('status', ['issued', 'part_paid'])
            ->where('balance', '>', 0.5)->orderByDesc('balance')->first();
        $pay = CustomerPayment::withoutGlobalScopes()->where('tenant_id', $this->tenantId)
            ->whereIn('customer_phone', $phones)->latest('id')->first();

        if (! $quote && ! $anyInv && ! $pay) return $empty;

        // Has a receipt been sent since the latest payment? (ReceiptSent lands on the customer subject.)
        $receiptSent = false;
        if ($pay) {
            $q = Event::withoutGlobalScopes()->where('tenant_id', $this->tenantId)
                ->where('subject_type', 'customer')->where('subject_id', $this->customerId)
                ->where('name', 'ReceiptSent');
            if ($pay->created_at) $q->where('recorded_at', '>=', $pay->created_at);
            try { $receiptSent = $q->exists(); } catch (\Throwable $e) { $receiptSent = false; }
        }

        $quoteStatus = strtolower((string) ($quote->status ?? ''));
        $invPaid = $anyInv && strtolower((string) $anyInv->status) === 'paid';

        // Stage done-flags (the rail). "Paid" is done only when NOTHING is outstanding (no open invoice)
        // — NOT merely because the latest invoice is paid. A customer can hold several invoices; if any
        // still has a balance, Paid stays the CURRENT step so the rail agrees with the "Collect" card
        // instead of showing a paid tick right next to money still due.
        $done = [
            'quote'   => (bool) ($quote || $anyInv),
            'accept'  => (bool) (($quote && in_array($quoteStatus, ['accepted', 'converted'], true)) || $anyInv),
            'invoice' => (bool) $anyInv,
            'pay'     => (bool) (($anyInv && ! $openInv) || (! $anyInv && $pay)),
            'receipt' => (bool) $receiptSent,
        ];

        // Next best action — highest-value first. current = the step it targets.
        $next = null; $current = null;
        if ($openInv) {
            $cur = strtoupper((string) $openInv->currency);
            $next = ['label' => 'Collect ' . $cur . ' ' . $this->money($openInv->balance, $cur),
                'hint' => trim(((string) $openInv->invoice_no) . ' · balance due'),
                'route' => ['key' => 'invoices', 'id' => (int) $openInv->id], 'tone' => 'warn'];
            // Slice 6 — offline collect needs an order to tie to (PaymentService requires order_no). Only
            // when we can resolve one do we expose the inline collect form; otherwise it stays a deep-link.
            $orderNo = null;
            if ($openInv->order_id) {
                try { $orderNo = (string) (\App\Models\Order::withoutGlobalScopes()
                    ->where('tenant_id', $this->tenantId)->where('id', (int) $openInv->order_id)->value('order_no') ?: ''); }
                catch (\Throwable $e) { $orderNo = null; }
            }
            if ($orderNo) {
                $next['collect'] = [
                    'order_no'   => $orderNo,
                    'invoice_no' => (string) $openInv->invoice_no,
                    'currency'   => $cur,
                    'amount'     => round((float) $openInv->balance, 2),
                ];
            }
            $current = 'pay';
        } elseif ($quote && $quoteStatus === 'accepted' && ! $quote->order_id) {
            $next = ['label' => 'Convert the accepted quote',
                'hint' => trim(((string) $quote->quote_no) . ' · ready to order'),
                'route' => ['key' => 'quotations', 'id' => (int) $quote->id], 'tone' => 'brand'];
            $current = 'invoice';
        } elseif ($invPaid && $pay && ! $receiptSent) {
            $next = ['label' => 'Send the receipt',
                'hint' => trim(((string) $pay->receipt_no) . ' · not sent yet'),
                'route' => ['key' => 'payments', 'id' => (int) $pay->id], 'tone' => 'brand'];
            $current = 'receipt';
        } elseif ($quote && $quoteStatus === 'sent') {
            $next = ['label' => 'Follow up on the quote',
                'hint' => trim(((string) $quote->quote_no) . ' · awaiting reply'),
                'route' => ['key' => 'quotations', 'id' => (int) $quote->id], 'tone' => 'default'];
            $current = 'accept';
        }

        $labels = ['quote' => 'Quoted', 'accept' => 'Accepted', 'invoice' => 'Invoiced', 'pay' => 'Paid', 'receipt' => 'Receipt'];
        $steps = [];
        foreach ($labels as $key => $label) {
            $state = $done[$key] ? 'done' : ($key === $current ? 'current' : 'todo');
            $steps[] = ['key' => $key, 'label' => $label, 'state' => $state];
        }

        $outstanding = (float) Invoice::withoutGlobalScopes()->where('tenant_id', $this->tenantId)
            ->whereIn('customer_phone', $phones)->where('status', '!=', 'void')->sum('balance');

        return ['stage' => $current ?: 'done', 'steps' => $steps, 'next' => $next, 'outstanding' => round($outstanding, 2)];
    }

    private function money(float $v, string $cur): string
    {
        return number_format($v, $cur === 'USD' ? 2 : 0);
    }
}
