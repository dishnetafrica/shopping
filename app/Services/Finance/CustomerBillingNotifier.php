<?php

namespace App\Services\Finance;

use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\Invoicing\InvoiceRenderer;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\BotTrace;
use App\Support\MessageLog;
use Illuminate\Support\Facades\Storage;

/**
 * v4.25 — tell the CUSTOMER what they owe and what they have paid.
 *
 * Everything needed already existed on the business side: numbered invoices with `amount_paid` and
 * `balance` kept in step by InvoiceService::syncPaidFromPayments, a receipt PDF per payment, and a
 * WhatsApp gateway that can send documents. What was missing was the customer's side of the loop —
 * an invoice was issued and a payment recorded, and the person who owed the money was told nothing.
 *
 * Two moments, two messages, both carrying the PDF and, more importantly, a plain-language line that
 * answers the only two questions a customer actually has:
 *
 *   invoice issued  → "this is what you are being charged for, and what is left to pay"
 *   payment taken   → "this is what I just received from you, and this is what is still outstanding"
 *
 * A partial payment is the case that matters most: the receipt says what was paid NOW, what has been
 * paid IN TOTAL against that invoice, and the balance still due — so nobody has to add up receipts.
 *
 * Never throws into the caller. An invoice must still issue and a payment must still record even if
 * WhatsApp is down, so every failure returns a reason instead of an exception.
 */
class CustomerBillingNotifier
{
    /** Send the invoice to the customer with a plain-language summary. */
    public function invoiceIssued(Tenant $t, Invoice $inv): array
    {
        try {
            if (! (bool) $t->setting('notify_customer_invoice', true)) {
                return $this->no('switched off in Settings');
            }
            if ($inv->isVoid()) {
                return $this->no('invoice is void');
            }
            // A re-save must not spam the customer with the same invoice again.
            $meta = (array) ($inv->meta ?? []);
            if (! empty($meta['customer_notified_at'])) {
                return $this->no('already sent on ' . $meta['customer_notified_at']);
            }
            $phone = $this->phoneFor($inv);
            if ($phone === '') {
                return $this->no('no customer phone on the invoice');
            }

            $doc = $this->invoicePdf($t, $inv);
            $text = $this->invoiceText($t, $inv);
            $sent = $this->send($t, $phone, $text, $doc, 'Invoice-' . $inv->invoice_no . '.pdf');

            if ($sent) {
                $meta['customer_notified_at'] = now()->toDateTimeString();
                $inv->meta = $meta;
                $inv->save();
                BotTrace::log($t->id, 'billing', $phone, 'invoice_sent', $inv->invoice_no);

                return ['sent' => true, 'reason' => ''];
            }

            return $this->no('WhatsApp send failed');
        } catch (\Throwable $e) {
            \Log::warning('CustomerBillingNotifier invoice failed: ' . $e->getMessage());

            return $this->no('error: ' . $e->getMessage());
        }
    }

    /** Send the receipt with what was paid now, in total, and what is still owed. */
    public function paymentReceived(Tenant $t, CustomerPayment $p): array
    {
        try {
            if (! (bool) $t->setting('notify_customer_receipt', true)) {
                return $this->no('switched off in Settings');
            }
            $phone = $this->digits((string) ($p->customer_phone ?? ''));
            if ($phone === '') {
                return $this->no('no customer phone on the payment');
            }

            // Read the invoice AFTER the payment has been reconciled, so paid/balance are current.
            $inv = $this->invoiceFor($t, $p);
            $doc = app(ReceiptRenderer::class)->pdf($t, $p);
            $text = $this->receiptText($t, $p, $inv);

            $sent = $this->send($t, $phone, $text,
                $doc ? ($doc['b64'] ?: $doc['url']) : null,
                $doc['fileName'] ?? ('Receipt-' . $p->receipt_no . '.pdf'));

            if ($sent) {
                BotTrace::log($t->id, 'billing', $phone, 'receipt_sent',
                    $p->receipt_no . ($inv ? ' · ' . $inv->invoice_no . ' balance ' . $inv->balance : ''));

                return ['sent' => true, 'reason' => ''];
            }

            return $this->no('WhatsApp send failed');
        } catch (\Throwable $e) {
            \Log::warning('CustomerBillingNotifier receipt failed: ' . $e->getMessage());

            return $this->no('error: ' . $e->getMessage());
        }
    }

    /**
     * The invoice message. Written for someone holding a phone, not an accountant: what it is for,
     * what it costs, what is already paid, what is left, and how to pay.
     */
    public function invoiceText(Tenant $t, Invoice $inv): string
    {
        $cur = (string) ($inv->currency ?: $t->setting('currency', 'UGX') ?: 'UGX');
        $name = trim((string) ($inv->customer_name ?? ''));
        $paid = (float) $inv->amount_paid;
        $bal = (float) $inv->balance;
        $total = (float) $inv->total;

        $lines = [];
        $lines[] = '🧾 *Invoice ' . $inv->invoice_no . '* — ' . $t->name;
        $lines[] = '';
        $lines[] = $name !== '' ? ('Hello ' . $this->firstName($name) . ',') : 'Hello,';

        $what = trim((string) ($inv->title ?: ''));
        $orderNo = (string) ($inv->order?->order_no ?? '');
        $lines[] = 'Here is your invoice' . ($what !== '' ? ' for ' . $what : '')
            . ($orderNo !== '' ? ' (order ' . $orderNo . ')' : '') . '.';
        $lines[] = '';

        $nItems = $this->itemCount($inv);
        if ($nItems > 0) {
            $lines[] = '📦 Items: ' . $nItems;
        }
        $lines[] = '💵 Invoice total: *' . $this->money($total, $cur) . '*';
        if ($paid > 0.005) {
            $lines[] = '✅ Already paid: ' . $this->money($paid, $cur);
            $lines[] = '⏳ Balance to pay: *' . $this->money($bal, $cur) . '*';
        }
        if ($inv->due_at) {
            $lines[] = '📅 Due by: ' . $inv->due_at->format('d M Y');
        }

        $how = trim((string) $t->setting('bank_details', ''));
        if ($how === '') {
            $how = trim((string) $t->setting('sf_pay_methods', ''));
        }
        if ($how !== '') {
            $lines[] = '';
            $lines[] = '💳 How to pay:';
            $lines[] = $how;
        }

        $lines[] = '';
        $lines[] = 'The full invoice is attached. Any question, just reply to this message 🙏';

        return implode("\n", $lines);
    }

    /**
     * The receipt message — the one that stops "how much do I still owe?" phone calls.
     * Partial payments spell out all three numbers: paid now, paid in total, still due.
     */
    public function receiptText(Tenant $t, CustomerPayment $p, ?Invoice $inv): string
    {
        $cur = strtoupper((string) ($p->currency ?: $t->setting('currency', 'UGX') ?: 'UGX'));
        $amount = (float) $p->amount;
        $isAdvance = strtolower((string) $p->kind) === 'advance';
        $name = trim((string) ($p->customer_name ?? ''));

        $lines = [];
        $lines[] = $isAdvance ? '✅ *Advance payment received* — thank you!' : '✅ *Payment received* — thank you!';
        $lines[] = '';
        if ($name !== '') {
            $lines[] = 'Hello ' . $this->firstName($name) . ',';
        }
        $lines[] = 'We have received *' . $this->money($amount, $cur) . '*'
            . ($p->method ? ' by ' . $this->methodName((string) $p->method) : '') . '.';
        $lines[] = '🧾 Receipt no: ' . $p->receipt_no;
        if ($p->reference) {
            $lines[] = '🔖 Reference: ' . $p->reference;
        }

        if ($inv) {
            $icur = (string) ($inv->currency ?: $cur);
            $total = (float) $inv->total;
            $paid = (float) $inv->amount_paid;
            $bal = (float) $inv->balance;

            $lines[] = '';
            $lines[] = '*Against invoice ' . $inv->invoice_no . '*';
            $lines[] = '• Invoice total: ' . $this->money($total, $icur);
            $lines[] = '• Paid so far: ' . $this->money($paid, $icur);

            if ($bal > 0.5) {
                $lines[] = '• ⏳ Balance still due: *' . $this->money($bal, $icur) . '*';
                if ($inv->due_at) {
                    $lines[] = '• 📅 Due by: ' . $inv->due_at->format('d M Y');
                }
            } else {
                $lines[] = '• 🎉 *This invoice is now fully paid.* Nothing more is due.';
            }
        } elseif ($p->order_no) {
            $lines[] = '';
            $lines[] = 'For order ' . $p->order_no . '.';
        }

        if ($isAdvance) {
            $lines[] = '';
            $lines[] = 'This is an advance — it will be set against your invoice when your order is completed.';
        }

        $lines[] = '';
        $lines[] = 'Your receipt is attached. Thank you for your business 🙏';
        $lines[] = '— ' . $t->name;

        return implode("\n", $lines);
    }

    /** The invoice this payment settles, re-read fresh so paid/balance include it. */
    public function invoiceFor(Tenant $t, CustomerPayment $p): ?Invoice
    {
        try {
            if ($p->invoice_no) {
                $inv = Invoice::where('tenant_id', $t->id)->where('invoice_no', $p->invoice_no)->first();
                if ($inv && ! $inv->isVoid()) {
                    return $inv;
                }
            }
            if ($p->order_no) {
                $order = \App\Models\Order::where('tenant_id', $t->id)->where('order_no', $p->order_no)->first();
                if ($order) {
                    return Invoice::where('tenant_id', $t->id)->where('order_id', $order->id)
                        ->where('status', '!=', 'void')->orderByDesc('id')->first();
                }
            }
        } catch (\Throwable $e) {
        }

        return null;
    }

    /** Stored PDF if we have one, else render and keep it. Returns base64 (preferred) or a URL. */
    private function invoicePdf(Tenant $t, Invoice $inv): ?string
    {
        try {
            $disk = Storage::disk('public');
            if ($inv->pdf_path && $disk->exists($inv->pdf_path)) {
                return base64_encode($disk->get($inv->pdf_path));
            }
            if (! class_exists(\Dompdf\Dompdf::class)) {
                return null;
            }
            $renderer = app(InvoiceRenderer::class);
            $inv->loadMissing('items');
            $pdf = $renderer->pdf($t, $renderer->fromInvoice($t, $inv));
            $path = 'invoices/' . $t->id . '/' . $inv->invoice_no . '.pdf';
            $disk->put($path, $pdf);
            $inv->pdf_path = $path;
            $inv->save();

            return base64_encode($pdf);
        } catch (\Throwable $e) {
            \Log::warning('CustomerBillingNotifier pdf failed: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Send it. The document carries a short caption (WhatsApp truncates long ones) and the full
     * message goes as text, so the customer reads the numbers even without opening the PDF.
     */
    private function send(Tenant $t, string $phone, string $text, ?string $media, string $fileName): bool
    {
        if (trim((string) $t->whatsapp_instance) === '') {
            return false;
        }
        try {
            $gw = app(WhatsAppManager::class)->forTenant($t);
            $gw->sendText($t->whatsapp_instance, $phone, $text);
            MessageLog::record($t->id, $phone, $t->whatsapp_instance, 'out', 'system', $text);

            if ($media !== null && method_exists($gw, 'sendDocument')) {
                $gw->sendDocument($t->whatsapp_instance, $phone, $media, $fileName, $fileName);
            }

            return true;
        } catch (\Throwable $e) {
            \Log::warning('CustomerBillingNotifier send failed: ' . $e->getMessage());

            return false;
        }
    }

    /** Invoice → order → nothing. Digits only, as the gateways expect. */
    private function phoneFor(Invoice $inv): string
    {
        $p = $this->digits((string) ($inv->customer_phone ?? ''));
        if ($p !== '') {
            return $p;
        }
        try {
            $inv->loadMissing('order');

            return $this->digits((string) ($inv->order?->customer_phone ?? ''));
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function itemCount(Invoice $inv): int
    {
        try {
            $inv->loadMissing('items');

            return (int) $inv->items->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function digits(string $s): string
    {
        return (string) preg_replace('/[^0-9]/', '', $s);
    }

    private function firstName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        return (string) ($parts[0] ?? $name);
    }

    private function money(float $n, string $cur): string
    {
        $dec = strtoupper($cur) === 'USD' ? 2 : 0;

        return $cur . ' ' . number_format($n, $dec);
    }

    private function methodName(string $m): string
    {
        $m = strtolower(trim($m));

        return match (true) {
            str_contains($m, 'momo') || str_contains($m, 'mobile') => 'Mobile Money',
            str_contains($m, 'bank') => 'bank transfer',
            str_contains($m, 'cash') => 'cash',
            str_contains($m, 'cheque') || str_contains($m, 'check') => 'cheque',
            default => $m,
        };
    }

    private function no(string $reason): array
    {
        return ['sent' => false, 'reason' => $reason];
    }
}
