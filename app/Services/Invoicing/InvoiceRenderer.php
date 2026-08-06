<?php

namespace App\Services\Invoicing;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Builds the branded invoice DATA + HTML + PDF. Shared by:
 *   - OrderInvoiceController (quick WYSIWYG PDF, not stored)
 *   - InvoiceService (issues a numbered, stored invoice)
 * so both documents look identical. dompdf renders the same table-based HTML as the quotation.
 */
class InvoiceRenderer
{
    /** Decimals to show for a currency (USD 2, others 0). */
    public function decimals(string $cur): int
    {
        return in_array(strtoupper($cur), ['USD'], true) ? 2 : 0;
    }

    /** Normalised data from the on-screen (posted) invoice, or the saved order as fallback. */
    public function fromRequest(Request $r, Tenant $t): array
    {
        $cur = strtoupper(trim((string) $r->input('currency', ''))) ?: (string) ($t->setting('currency', 'UGX') ?: 'UGX');
        $dec = $r->has('dec') ? max(0, min(2, (int) $r->input('dec'))) : $this->decimals($cur);

        $lines  = [];
        $posted = $r->input('lines');
        if (is_string($posted)) { $posted = json_decode($posted, true); }
        if (is_array($posted) && $posted) {
            foreach ($posted as $l) {
                $name = trim((string) ($l['name'] ?? ''));
                if ($name === '') continue;
                $qty  = (float) ($l['qty'] ?? 0); if ($qty <= 0) $qty = 1;
                $unit = (float) ($l['unit'] ?? 0);
                $lines[] = ['name' => $name, 'qty' => $qty, 'unit' => $unit, 'amount' => $qty * $unit,
                            'sku' => trim((string) ($l['sku'] ?? '')), 'uom' => trim((string) ($l['uom'] ?? '')),
                            'pack' => max(0.0, (float) ($l['pack'] ?? 0))];
            }
        }

        $order = null;
        $id = (int) $r->input('id', $r->input('row', 0));
        if ($id > 0) $order = Order::where('tenant_id', $t->id)->find($id);

        $no       = trim((string) $r->input('order_no', '')) ?: ($order ? (string) ($order->order_no ?: ('#' . $order->id)) : ('#' . $id));
        $customer = trim((string) $r->input('customer', '')) ?: ($order ? (string) ($order->customer_name ?? '') : '');
        $phone    = trim((string) $r->input('phone', '')) ?: ($order ? (string) ($order->customer_phone ?? '') : '');
        $address  = trim((string) $r->input('address', '')) ?: ($order ? (string) ($order->location ?? '') : '');
        $status   = trim((string) $r->input('status', '')) ?: ($order ? (string) ($order->status ?? '') : '');
        $notes    = trim((string) $r->input('notes', '')) ?: ($order ? (string) ($order->notes ?? '') : '');

        if (! $lines && $order) {
            $cur = (string) ($t->setting('currency', 'UGX') ?: 'UGX');
            $dec = $this->decimals($cur);
            foreach ((is_array($order->items_json) ? $order->items_json : []) as $it) {
                $name = trim((string) ($it['name'] ?? '')); if ($name === '') continue;
                $qty  = (float) ($it['qty'] ?? 1); if ($qty <= 0) $qty = 1;
                $unit = (float) ($it['price'] ?? 0);
                $lines[] = ['name' => $name, 'qty' => $qty, 'unit' => $unit, 'amount' => $qty * $unit, 'sku' => '', 'uom' => '', 'pack' => 0.0];
            }
        }

        $subtotal = array_sum(array_map(fn ($l) => $l['amount'], $lines));
        $discount = max(0.0, (float) $r->input('discount', 0));
        $freight  = max(0.0, (float) $r->input('delivery', $r->input('freight', 0)));
        $grand    = max(0.0, $subtotal - $discount + $freight);

        if ($r->has('paid'))      $paid = max(0.0, (float) $r->input('paid', 0));
        elseif ($order)           $paid = (float) ($order->amount_paid ?? 0);
        else                      $paid = 0.0;

        $termsDays = $r->has('terms_days') ? max(0, (int) $r->input('terms_days')) : max(0, (int) $t->setting('invoice_terms_days', 0));

        return $this->finish($t, [
            'no' => $no, 'title' => (string) $t->setting('invoice_title', 'INVOICE') ?: 'INVOICE',
            'customer' => $customer, 'phone' => $phone, 'address' => $address, 'status' => $status,
            'notes' => $notes, 'cur' => $cur, 'dec' => $dec, 'lines' => $lines,
            'subtotal' => $subtotal, 'discount' => $discount, 'freight' => $freight, 'grand' => $grand, 'paid' => $paid,
            'disc_label' => trim((string) $r->input('disc_label', 'Discount')) ?: 'Discount',
            'terms_days' => $termsDays, 'date' => now()->format('d/m/Y'),
        ]);
    }

    /** Normalised data straight from a saved order (base currency) — used for auto-issue. */
    public function fromOrder(Tenant $t, Order $order): array
    {
        $cur = (string) ($t->setting('currency', 'UGX') ?: 'UGX');
        $dec = $this->decimals($cur);
        $lines = [];
        foreach ((is_array($order->items_json) ? $order->items_json : []) as $it) {
            $name = trim((string) ($it['name'] ?? '')); if ($name === '') continue;
            $qty  = (float) ($it['qty'] ?? 1); if ($qty <= 0) $qty = 1;
            $unit = (float) ($it['price'] ?? 0);
            $lines[] = ['name' => $name, 'qty' => $qty, 'unit' => $unit, 'amount' => $qty * $unit, 'sku' => '', 'uom' => '', 'pack' => 0.0];
        }
        $subtotal = array_sum(array_map(fn ($l) => $l['amount'], $lines));
        // Honour a saved order total that differs from raw subtotal (delivery/discount folded in).
        $savedTotal = (float) ($order->total ?? 0);
        $freight = 0.0; $discount = 0.0;
        if ($savedTotal > $subtotal + 0.01)      $freight = round($savedTotal - $subtotal, 2);
        elseif ($savedTotal > 0 && $savedTotal < $subtotal - 0.01) $discount = round($subtotal - $savedTotal, 2);
        $grand = max(0.0, $subtotal - $discount + $freight);
        $paid  = (float) ($order->amount_paid ?? 0);

        return $this->finish($t, [
            'no' => (string) ($order->order_no ?: ('#' . $order->id)),
            'title' => (string) $t->setting('invoice_title', 'INVOICE') ?: 'INVOICE',
            'customer' => (string) ($order->customer_name ?? ''), 'phone' => (string) ($order->customer_phone ?? ''),
            'address' => (string) ($order->location ?? ''), 'status' => (string) ($order->status ?? ''),
            'notes' => (string) ($order->notes ?? ''), 'cur' => $cur, 'dec' => $dec, 'lines' => $lines,
            'subtotal' => $subtotal, 'discount' => $discount, 'freight' => $freight, 'grand' => $grand, 'paid' => $paid,
            'disc_label' => 'Discount', 'terms_days' => max(0, (int) $t->setting('invoice_terms_days', 0)),
            'date' => now()->format('d/m/Y'),
        ]);
    }

    /** Re-render a stored invoice (money already in the invoice's currency). */
    public function fromInvoice(Tenant $t, Invoice $inv): array
    {
        $cur = (string) $inv->currency;
        $dec = $this->decimals($cur);
        $lines = [];
        foreach ($inv->items as $it) {
            $lines[] = ['name' => (string) $it->name, 'qty' => (float) $it->qty, 'unit' => (float) $it->unit_price,
                        'amount' => (float) $it->line_total, 'sku' => (string) ($it->sku ?? ''),
                        'specs' => (string) ($it->specs_summary ?? ''),   // Asset Lifecycle · frozen spec snapshot
                        'uom' => (string) ($it->uom ?? ''), 'pack' => (float) ($it->pack ?? 0)];
        }
        return $this->finish($t, [
            'no' => (string) $inv->invoice_no, 'title' => (string) ($inv->title ?: 'INVOICE'),
            'customer' => (string) $inv->customer_name, 'phone' => (string) $inv->customer_phone,
            'address' => (string) $inv->bill_address, 'status' => $inv->status === 'void' ? 'VOID' : '',
            'notes' => (string) $inv->notes, 'cur' => $cur, 'dec' => $dec, 'lines' => $lines,
            'subtotal' => (float) $inv->subtotal, 'discount' => (float) $inv->discount, 'freight' => (float) $inv->freight,
            'grand' => (float) $inv->total, 'paid' => (float) $inv->amount_paid, 'disc_label' => 'Discount',
            'terms_days' => max(0, (int) $t->setting('invoice_terms_days', 0)),
            'date' => optional($inv->issued_at)->format('d/m/Y') ?: now()->format('d/m/Y'),
        ]);
    }

    /** Derive balance + credit-term labels and normalise the data block. */
    private function finish(Tenant $t, array $d): array
    {
        $d['balance'] = round(($d['grand'] ?? 0) - ($d['paid'] ?? 0), 2);
        $termsDays = (int) ($d['terms_days'] ?? 0);
        if ($d['balance'] <= 0.5)      { $d['terms_label'] = 'Paid in full';   $d['due_date'] = ''; }
        elseif ($termsDays > 0)        { $d['terms_label'] = 'Net ' . $termsDays . ' days'; $d['due_date'] = now()->addDays($termsDays)->format('d/m/Y'); }
        else                           { $d['terms_label'] = 'Due on receipt'; $d['due_date'] = ''; }
        return $d;
    }

    public function pdf(Tenant $t, array $d): string
    {
        $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => true]);
        $dompdf->loadHtml($this->html($t, $d));
        // Tenant-configurable (Settings → Documents); defaults match the previous fixed A4 portrait.
        $size = strtoupper((string) $t->setting('invoice_page_size', 'A4')) ?: 'A4';
        $orient = strtolower((string) $t->setting('invoice_orientation', 'portrait')) === 'landscape' ? 'landscape' : 'portrait';
        $dompdf->setPaper($size, $orient);
        $dompdf->render();
        return $dompdf->output();
    }

    private function money(float $n, string $cur, int $dec): string
    {
        return $cur . ' ' . number_format($n, $dec);
    }

    public function html(Tenant $t, array $d): string
    {
        $cur = $d['cur'];
        $dec = $d['dec'];

        $company  = e((string) $t->name);
        $subtitle = trim((string) $t->setting('quote_subtitle', ''));
        if ($subtitle === '') $subtitle = trim((string) $t->setting('address', ''));
        $subHtml  = $subtitle !== '' ? nl2br(e($subtitle)) : '';
        $email    = trim((string) $t->setting('public_email', $t->setting('email', '')));
        $cphone   = trim((string) ($t->setting('public_phone', '') ?: $t->whatsapp_number));
        $web      = trim((string) $t->setting('website', ''));
        $tin      = trim((string) $t->setting('tin', ''));

        $contactParts = array_filter([
            $cphone !== '' ? 'Tel/WhatsApp: ' . e($cphone) : '',
            $email  !== '' ? 'Email: ' . e($email) : '',
            $web    !== '' ? 'Website: ' . e($web) : '',
        ], fn ($x) => $x !== '');
        $contactLine = $contactParts ? '<div class="webline">' . implode(' &nbsp;|&nbsp; ', $contactParts) . '</div>' : '';
        $tinLine     = $tin !== '' ? '<div class="webline" style="padding-top:2px;">TIN: ' . e($tin) . '</div>' : '';

        $logoTag = $this->imgData((string) $t->setting('logo', ''));
        $logoTag = $logoTag !== '' ? '<img src="' . e($logoTag) . '" style="height:58px;max-width:110px;object-fit:contain">' : '&nbsp;';

        $title = e((string) ($d['title'] ?? 'INVOICE')) ?: 'INVOICE';
        $date  = e((string) ($d['date'] ?? now()->format('d/m/Y')));
        $no    = e((string) $d['no']);

        $custName = $d['customer'] !== '' ? e($d['customer']) : ($d['phone'] !== '' ? e($d['phone']) : 'Cash Customer');
        $billLines = array_filter([
            $d['phone']   !== '' ? 'Tel: ' . e($d['phone']) : '',
            $d['address'] !== '' ? e($d['address']) : '',
        ], fn ($x) => $x !== '');
        $billExtra = $billLines ? '<div style="margin-top:4px;font-size:10px;line-height:1.5;">' . implode('<br>', $billLines) . '</div>' : '';

        $hasPack = false;
        foreach ($d['lines'] as $l) { if ((float) ($l['pack'] ?? 0) > 0) { $hasPack = true; break; } }
        $ppDec = $dec > 0 ? $dec : 2;

        $rows = '';
        $i = 0;
        foreach ($d['lines'] as $l) {
            $i++;
            $qty = rtrim(rtrim(number_format((float) $l['qty'], 2), '0'), '.');
            $uom = trim((string) ($l['uom'] ?? ''));
            if ($uom !== '') $qty .= ' ' . strtoupper(e($uom));
            $sku  = ($l['sku'] ?? '') !== '' ? '<div style="font-size:8.5px;color:#777;">' . e($l['sku']) . '</div>' : '';
            // Asset Lifecycle · frozen product-spec snapshot, shown as a compact grey sub-line (invoices only carry it).
            $specs = ($l['specs'] ?? '') !== '' ? '<div style="font-size:8.5px;color:#555;">' . e($l['specs']) . '</div>' : '';
            $pack = (float) ($l['pack'] ?? 0);

            $unitCell = number_format((float) $l['unit'], $dec);
            if ($pack > 0 && $uom !== '') {
                $unitCell .= '<div style="font-size:8px;color:#777;">= ' . number_format((float) $l['unit'] / $pack, $ppDec) . ' /pc</div>';
            }
            $packCell = '';
            if ($hasPack) {
                $packTxt  = $pack > 0 ? '&times;' . rtrim(rtrim(number_format($pack, 2), '0'), '.') : '&mdash;';
                $packCell = '<td class="c">' . $packTxt . '</td>';
            }
            $rows .= '<tr><td class="c">' . $i . '</td><td>' . e($l['name']) . $sku . $specs . '</td>'
                . '<td class="c">' . $qty . '</td>' . $packCell
                . '<td class="r">' . $unitCell . '</td>'
                . '<td class="r">' . number_format((float) $l['amount'], $dec) . '</td></tr>';
        }
        $packHead = $hasPack ? '<th style="width:52px;">Pack</th>' : '';

        $sub   = $this->money($d['subtotal'], $cur, $dec);
        $grand = $this->money($d['grand'], $cur, $dec);
        $discRow = $d['discount'] > 0 ? '<tr><td class="lbl">' . e($d['disc_label']) . '</td><td class="r">- ' . $this->money($d['discount'], $cur, $dec) . '</td></tr>' : '';
        $freightRow = $d['freight'] > 0 ? '<tr><td class="lbl">Freight / Delivery</td><td class="r">' . $this->money($d['freight'], $cur, $dec) . '</td></tr>' : '';

        $payBlock = '';
        if ($d['paid'] > 0 || $d['balance'] < $d['grand']) {
            $balColor = $d['balance'] > 0.5 ? '#C0392B' : '#15803D';
            $balLbl   = $d['balance'] > 0.5 ? 'Balance Due' : 'Paid in Full';
            $payBlock = '<tr><td class="lbl">Amount Paid</td><td class="r">' . $this->money($d['paid'], $cur, $dec) . '</td></tr>'
                . '<tr class="bal"><td style="color:' . $balColor . '">' . $balLbl . '</td><td class="r" style="color:' . $balColor . '">'
                . $this->money(max(0, $d['balance']), $cur, $dec) . '</td></tr>';
        }

        $statusChip = ($d['status'] ?? '') !== '' ? '<span style="border:1px solid #111;border-radius:3px;padding:1px 6px;font-size:9px;">' . e($d['status']) . '</span>' : '';
        $termsRow = '<tr><td class="k">Terms</td><td>' . e((string) $d['terms_label']) . '</td></tr>';
        $dueRow   = $d['due_date'] !== '' ? '<tr><td class="k">Due Date</td><td><b>' . e((string) $d['due_date']) . '</b></td></tr>' : '';
        $notesHtml = ($d['notes'] ?? '') !== '' ? '<div class="notes"><b>Notes:</b> ' . nl2br(e($d['notes'])) . '</div>' : '';

        $terms = trim((string) $t->setting('invoice_terms',
            'Goods remain the property of ' . strip_tags($company) . ' until paid in full. '
            . 'Please quote the invoice number on payment. Claims for shortage or damage must be made within 48 hours of receipt.'));
        $bank  = trim((string) $t->setting('bank_details', ''));
        $bankHtml = $bank !== '' ? '<div class="terms"><span class="h">Payment details:</span><br>' . nl2br(e($bank)) . '</div>' : '';
        $termsHtml = $terms !== '' ? '<div class="terms"><span class="h">Terms:</span> ' . nl2br(e($terms)) . '</div>' : '';

        $creditHtml = '';
        if ($d['balance'] > 0.5 && (int) $d['terms_days'] > 0) {
            $default = 'This is a credit sale on ' . $d['terms_label']
                . ($d['due_date'] !== '' ? ' — full balance due by ' . $d['due_date'] : '')
                . '. Overdue balances may attract interest and hold further supply.';
            $credit  = trim((string) $t->setting('credit_terms', $default));
            if ($credit !== '') $creditHtml = '<div class="terms"><span class="h">Payment terms:</span> ' . nl2br(e($credit)) . '</div>';
        }
        // v4.46 — the invoice was the one document with no stamp area at all, and its default
        // footer said "valid without signature", which is the exact sentence customers are pushing
        // back on. Stamped, that sentence is both wrong and self-defeating, so it changes — but
        // only the DEFAULT: an owner who wrote their own footer keeps it, stamp or no stamp.
        $stampHtml = \App\Services\Documents\DocumentStamp::block($t, 'invoice');
        $footer = trim((string) $t->setting('invoice_footer', $stampHtml !== ''
            ? 'Thank you for your business. This invoice is issued and stamped by ' . $t->name . '.'
            : 'Thank you for your business. This is a computer-generated invoice and is valid without signature.'));

        return <<<HTML
<!doctype html><html><head><meta charset="utf-8"><style>
* { font-family: "DejaVu Sans", Arial, sans-serif; }
body { color:#111; font-size:11px; margin:0; }
.company { font-size:19px; font-weight:bold; text-align:center; line-height:1.1; }
.sub { text-align:center; font-size:9.5px; line-height:1.35; }
.webline { text-align:center; font-size:9.5px; padding-top:6px; }
.rule { border:0; border-top:1px solid #111; margin:8px 0 0; }
.qtitle { text-align:right; font-size:22px; font-weight:bold; letter-spacing:2px; margin:14px 0 6px; }
.box { border:1px solid #111; padding:8px 10px; }
.kv { width:100%; border-collapse:collapse; }
.kv td { border:1px solid #111; padding:5px 8px; font-size:10.5px; }
.kv td.k { font-weight:bold; width:46%; }
table.items { width:100%; border-collapse:collapse; margin-top:12px; }
table.items th { background:#f0f0f0; border:1px solid #111; padding:6px; font-size:10px; text-align:center; }
table.items td { border:1px solid #111; padding:6px; font-size:10px; vertical-align:top; }
.r { text-align:right; } .c { text-align:center; }
table.tot { border-collapse:collapse; }
table.tot td { border:1px solid #111; padding:6px 10px; font-size:10.5px; }
table.tot td.lbl { font-weight:bold; }
table.tot tr.grand td { font-weight:bold; font-size:12px; background:#f0f0f0; }
table.tot tr.bal td { font-weight:bold; font-size:11.5px; }
.notes { margin-top:14px; font-size:10px; border-left:3px solid #ccc; padding-left:8px; }
.terms { margin-top:16px; font-size:9.5px; line-height:1.5; }
.terms .h { font-weight:bold; }
.foot { text-align:center; font-size:9.5px; color:#444; margin-top:26px; border-top:1px solid #ddd; padding-top:8px; }
</style></head><body>
<table width="100%"><tr>
  <td style="width:110px;vertical-align:middle;">{$logoTag}</td>
  <td style="vertical-align:middle;">
    <div class="company">{$company}</div>
    <div class="sub">{$subHtml}</div>
  </td>
  <td style="width:110px;">&nbsp;</td>
</tr></table>
{$contactLine}
{$tinLine}
<hr class="rule">

<div class="qtitle">{$title}</div>

<table width="100%"><tr>
  <td width="55%" style="vertical-align:top;padding-right:14px;">
    <div class="box" style="min-height:96px;">
      <div style="font-size:9px;color:#666;text-transform:uppercase;letter-spacing:1px;">Bill To</div>
      <div style="font-weight:bold;font-size:12px;margin-top:3px;">{$custName}</div>
      {$billExtra}
    </div>
  </td>
  <td width="45%" style="vertical-align:top;">
    <table class="kv">
      <tr><td class="k">Invoice No.</td><td>{$no}</td></tr>
      <tr><td class="k">Date</td><td>{$date}</td></tr>
      {$termsRow}
      {$dueRow}
      <tr><td class="k">Currency</td><td>{$cur}</td></tr>
      <tr><td class="k">Status</td><td>{$statusChip}</td></tr>
    </table>
  </td>
</tr></table>

<table class="items">
  <tr>
    <th style="width:24px;">#</th>
    <th>Description</th>
    <th style="width:78px;">Qty / Unit</th>
    {$packHead}
    <th style="width:105px;">Unit Price</th>
    <th style="width:120px;">Amount</th>
  </tr>
  {$rows}
</table>

<table width="100%" style="margin-top:10px;"><tr>
  <td width="50%" style="vertical-align:top;">{$notesHtml}</td>
  <td width="50%">
    <table class="tot" width="100%">
      <tr><td class="lbl">Subtotal</td><td class="r">{$sub}</td></tr>
      {$discRow}
      {$freightRow}
      <tr class="grand"><td>Total &nbsp; {$cur}</td><td class="r">{$grand}</td></tr>
      {$payBlock}
    </table>
  </td>
</tr></table>

{$creditHtml}
{$termsHtml}
{$bankHtml}
{$stampHtml}

<div class="foot">{$footer}</div>
</body></html>
HTML;
    }

    /* ============================ CREDIT NOTES ============================ */

    /** Normalised data from a stored credit note, for the PDF. */
    public function fromCreditNote(Tenant $t, \App\Models\CreditNote $cn): array
    {
        $cur = (string) $cn->currency;
        $dec = $this->decimals($cur);
        $lines = [];
        foreach ($cn->items as $it) {
            $lines[] = ['name' => (string) $it->name, 'qty' => (float) $it->qty, 'unit' => (float) $it->unit_price,
                        'amount' => (float) $it->line_total, 'sku' => (string) ($it->sku ?? ''),
                        'uom' => (string) ($it->uom ?? ''), 'pack' => (float) ($it->pack ?? 0)];
        }
        return [
            'no' => (string) $cn->credit_note_no, 'title' => (string) ($cn->title ?: 'CREDIT NOTE'),
            'customer' => (string) $cn->customer_name, 'phone' => (string) $cn->customer_phone,
            'address' => (string) $cn->bill_address, 'status' => $cn->status === 'void' ? 'VOID' : '',
            'notes' => (string) $cn->notes, 'cur' => $cur, 'dec' => $dec, 'lines' => $lines,
            'subtotal' => (float) $cn->subtotal, 'total' => (float) $cn->total,
            'reason' => \App\Models\CreditNote::reasonLabel((string) $cn->reason_code),
            'against' => (string) ($cn->invoice_no ?? ''),
            'date' => optional($cn->issued_at)->format('d/m/Y') ?: now()->format('d/m/Y'),
        ];
    }

    public function creditNotePdf(Tenant $t, array $d): string
    {
        $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => true]);
        $dompdf->loadHtml($this->creditNoteHtml($t, $d));
        $dompdf->setPaper('A4');
        $dompdf->render();
        return $dompdf->output();
    }

    public function creditNoteHtml(Tenant $t, array $d): string
    {
        $cur = (string) ($d['cur'] ?? ($t->setting('currency', 'UGX') ?: 'UGX'));
        $dec = (int) ($d['dec'] ?? $this->decimals($cur));

        $company  = e((string) $t->name);
        $subtitle = trim((string) $t->setting('quote_subtitle', '')) ?: trim((string) $t->setting('address', ''));
        $subHtml  = $subtitle !== '' ? nl2br(e($subtitle)) : '';
        $email    = trim((string) $t->setting('public_email', $t->setting('email', '')));
        $cphone   = trim((string) ($t->setting('public_phone', '') ?: $t->whatsapp_number));
        $web      = trim((string) $t->setting('website', ''));
        $tin      = trim((string) $t->setting('tin', ''));

        $contactParts = array_filter([
            $cphone !== '' ? 'Tel/WhatsApp: ' . e($cphone) : '',
            $email  !== '' ? 'Email: ' . e($email) : '',
            $web    !== '' ? 'Website: ' . e($web) : '',
        ], fn ($x) => $x !== '');
        $contactLine = $contactParts ? '<div class="webline">' . implode(' &nbsp;|&nbsp; ', $contactParts) . '</div>' : '';
        $tinLine     = $tin !== '' ? '<div class="webline" style="padding-top:2px;">TIN: ' . e($tin) . '</div>' : '';

        $logoTag = $this->imgData((string) $t->setting('logo', ''));
        $logoTag = $logoTag !== '' ? '<img src="' . e($logoTag) . '" style="height:58px;max-width:110px;object-fit:contain">' : '&nbsp;';

        $title = e((string) ($d['title'] ?? 'CREDIT NOTE')) ?: 'CREDIT NOTE';
        $date  = e((string) ($d['date'] ?? now()->format('d/m/Y')));
        $no    = e((string) $d['no']);
        $reason = e((string) ($d['reason'] ?? ''));
        $against = trim((string) ($d['against'] ?? ''));

        $custName = ($d['customer'] ?? '') !== '' ? e($d['customer']) : (($d['phone'] ?? '') !== '' ? e($d['phone']) : 'Customer');
        $billLines = array_filter([
            ($d['phone'] ?? '')   !== '' ? 'Tel: ' . e($d['phone']) : '',
            ($d['address'] ?? '') !== '' ? e($d['address']) : '',
        ], fn ($x) => $x !== '');
        $billExtra = $billLines ? '<div style="margin-top:4px;font-size:10px;line-height:1.5;">' . implode('<br>', $billLines) . '</div>' : '';

        $hasPack = false;
        foreach (($d['lines'] ?? []) as $l) { if ((float) ($l['pack'] ?? 0) > 0) { $hasPack = true; break; } }

        $rows = '';
        $i = 0;
        foreach (($d['lines'] ?? []) as $l) {
            $i++;
            $qty = rtrim(rtrim(number_format((float) $l['qty'], 2), '0'), '.');
            $uom = trim((string) ($l['uom'] ?? ''));
            if ($uom !== '') $qty .= ' ' . strtoupper(e($uom));
            $sku  = ($l['sku'] ?? '') !== '' ? '<div style="font-size:8.5px;color:#777;">' . e($l['sku']) . '</div>' : '';
            // Asset Lifecycle · frozen product-spec snapshot, shown as a compact grey sub-line (invoices only carry it).
            $specs = ($l['specs'] ?? '') !== '' ? '<div style="font-size:8.5px;color:#555;">' . e($l['specs']) . '</div>' : '';
            $pack = (float) ($l['pack'] ?? 0);
            $packCell = '';
            if ($hasPack) {
                $packTxt  = $pack > 0 ? '&times;' . rtrim(rtrim(number_format($pack, 2), '0'), '.') : '&mdash;';
                $packCell = '<td class="c">' . $packTxt . '</td>';
            }
            $rows .= '<tr><td class="c">' . $i . '</td><td>' . e($l['name']) . $sku . $specs . '</td>'
                . '<td class="c">' . $qty . '</td>' . $packCell
                . '<td class="r">' . number_format((float) $l['unit'], $dec) . '</td>'
                . '<td class="r">' . number_format((float) $l['amount'], $dec) . '</td></tr>';
        }
        $packHead = $hasPack ? '<th style="width:52px;">Pack</th>' : '';

        $total = $this->money((float) ($d['total'] ?? 0), $cur, $dec);
        $sub   = $this->money((float) ($d['subtotal'] ?? ($d['total'] ?? 0)), $cur, $dec);

        $againstRow = $against !== '' ? '<tr><td class="k">Against Invoice</td><td>' . e($against) . '</td></tr>' : '';
        $statusChip = ($d['status'] ?? '') !== '' ? '<span style="border:1px solid #C0392B;color:#C0392B;border-radius:3px;padding:1px 6px;font-size:9px;">' . e($d['status']) . '</span>' : '';
        $notesHtml  = ($d['notes'] ?? '') !== '' ? '<div class="notes"><b>Notes:</b> ' . nl2br(e($d['notes'])) . '</div>' : '';
        $reasonHtml = $reason !== '' ? '<div class="notes"><b>Reason:</b> ' . $reason . '</div>' : '';

        $blurb = trim((string) $t->setting('credit_note_blurb',
            'This credit note reduces the amount owing on the account'
            . ($against !== '' ? ' against invoice ' . $against : '')
            . '. It may be set off against this or a future invoice, or refunded as agreed.'));
        $blurbHtml = $blurb !== '' ? '<div class="terms"><span class="h">Note:</span> ' . nl2br(e($blurb)) . '</div>' : '';

        // v4.46 — a credit note reduces what somebody owes, so it is the document most likely to be
        // waved at us later. Same treatment as the invoice.
        $stampHtml = \App\Services\Documents\DocumentStamp::block($t, 'credit_note');
        $footer = trim((string) $t->setting('invoice_footer', $stampHtml !== ''
            ? 'This credit note is issued and stamped by ' . $t->name . '.'
            : 'This is a computer-generated credit note and is valid without signature.'));

        return <<<HTML
<!doctype html><html><head><meta charset="utf-8"><style>
* { font-family: "DejaVu Sans", Arial, sans-serif; }
body { color:#111; font-size:11px; margin:0; }
.company { font-size:19px; font-weight:bold; text-align:center; line-height:1.1; }
.sub { text-align:center; font-size:9.5px; line-height:1.35; }
.webline { text-align:center; font-size:9.5px; padding-top:6px; }
.rule { border:0; border-top:1px solid #111; margin:8px 0 0; }
.qtitle { text-align:right; font-size:22px; font-weight:bold; letter-spacing:2px; margin:14px 0 6px; color:#C0392B; }
.box { border:1px solid #111; padding:8px 10px; }
.kv { width:100%; border-collapse:collapse; }
.kv td { border:1px solid #111; padding:5px 8px; font-size:10.5px; }
.kv td.k { font-weight:bold; width:46%; }
table.items { width:100%; border-collapse:collapse; margin-top:12px; }
table.items th { background:#f0f0f0; border:1px solid #111; padding:6px; font-size:10px; text-align:center; }
table.items td { border:1px solid #111; padding:6px; font-size:10px; vertical-align:top; }
.r { text-align:right; } .c { text-align:center; }
table.tot { border-collapse:collapse; }
table.tot td { border:1px solid #111; padding:6px 10px; font-size:10.5px; }
table.tot td.lbl { font-weight:bold; }
table.tot tr.grand td { font-weight:bold; font-size:12px; background:#FCEBEB; color:#C0392B; }
.notes { margin-top:14px; font-size:10px; border-left:3px solid #ccc; padding-left:8px; }
.terms { margin-top:16px; font-size:9.5px; line-height:1.5; }
.terms .h { font-weight:bold; }
.foot { text-align:center; font-size:9.5px; color:#444; margin-top:26px; border-top:1px solid #ddd; padding-top:8px; }
</style></head><body>
<table width="100%"><tr>
  <td style="width:110px;vertical-align:middle;">{$logoTag}</td>
  <td style="vertical-align:middle;">
    <div class="company">{$company}</div>
    <div class="sub">{$subHtml}</div>
  </td>
  <td style="width:110px;">&nbsp;</td>
</tr></table>
{$contactLine}
{$tinLine}
<hr class="rule">

<div class="qtitle">{$title}</div>

<table width="100%"><tr>
  <td width="55%" style="vertical-align:top;padding-right:14px;">
    <div class="box" style="min-height:96px;">
      <div style="font-size:9px;color:#666;text-transform:uppercase;letter-spacing:1px;">Credit To</div>
      <div style="font-weight:bold;font-size:12px;margin-top:3px;">{$custName}</div>
      {$billExtra}
    </div>
  </td>
  <td width="45%" style="vertical-align:top;">
    <table class="kv">
      <tr><td class="k">Credit Note No.</td><td>{$no}</td></tr>
      <tr><td class="k">Date</td><td>{$date}</td></tr>
      {$againstRow}
      <tr><td class="k">Currency</td><td>{$cur}</td></tr>
      <tr><td class="k">Status</td><td>{$statusChip}</td></tr>
    </table>
  </td>
</tr></table>

<table class="items">
  <tr>
    <th style="width:24px;">#</th>
    <th>Description</th>
    <th style="width:78px;">Qty / Unit</th>
    {$packHead}
    <th style="width:105px;">Unit Price</th>
    <th style="width:120px;">Amount</th>
  </tr>
  {$rows}
</table>

<table width="100%" style="margin-top:10px;"><tr>
  <td width="50%" style="vertical-align:top;">{$reasonHtml}{$notesHtml}</td>
  <td width="50%">
    <table class="tot" width="100%">
      <tr><td class="lbl">Subtotal</td><td class="r">{$sub}</td></tr>
      <tr class="grand"><td>Total Credit &nbsp; {$cur}</td><td class="r">{$total}</td></tr>
    </table>
  </td>
</tr></table>

{$blurbHtml}
{$stampHtml}

<div class="foot">{$footer}</div>
</body></html>
HTML;
    }

    /** Render a customer STATEMENT of account (from StatementService::for) to PDF bytes. */
    public function statementPdf(Tenant $t, array $stmt): string
    {
        $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => true]);
        $dompdf->loadHtml($this->statementHtml($t, $stmt));
        $dompdf->setPaper('A4');
        $dompdf->render();
        return $dompdf->output();
    }

    public function statementHtml(Tenant $t, array $stmt): string
    {
        $company  = e((string) $t->name);
        $subtitle = trim((string) $t->setting('quote_subtitle', '')) ?: trim((string) $t->setting('address', ''));
        $subHtml  = $subtitle !== '' ? nl2br(e($subtitle)) : '';
        $email    = trim((string) $t->setting('public_email', $t->setting('email', '')));
        $cphone   = trim((string) ($t->setting('public_phone', '') ?: $t->whatsapp_number));
        $tin      = trim((string) $t->setting('tin', ''));
        $contact  = array_filter([
            $cphone !== '' ? 'Tel/WhatsApp: ' . e($cphone) : '',
            $email  !== '' ? 'Email: ' . e($email) : '',
            $tin    !== '' ? 'TIN: ' . e($tin) : '',
        ], fn ($x) => $x !== '');
        $contactLine = $contact ? '<div class="webline">' . implode(' &nbsp;|&nbsp; ', $contact) . '</div>' : '';
        $logoTag = $this->imgData((string) $t->setting('logo', ''));
        $logoTag = $logoTag !== '' ? '<img src="' . e($logoTag) . '" style="height:56px;max-width:110px;object-fit:contain">' : '&nbsp;';

        $cust = $stmt['name'] !== '' ? e($stmt['name']) : e($stmt['phone']);
        $date = now()->format('d/m/Y');

        $rows = '';
        $i = 0;
        foreach ($stmt['lines'] as $l) {
            $i++;
            $dec = in_array($l['currency'], ['USD'], true) ? 2 : 0;
            $bal = (float) $l['balance'];
            $isCredit = (($l['status'] ?? '') === 'credit') || (($l['kind'] ?? '') === 'credit');
            $col = $isCredit ? '#15803D' : ($bal > 0.005 ? '#C0392B' : '#15803D');
            $label = e($l['no']);
            if ($isCredit) {
                $sub = trim((string) ($l['reason'] ?? 'Credit note'));
                if (($l['against'] ?? '') !== '') $sub .= ' · ' . $l['against'];
                $label .= '<div style="font-size:8.5px;color:#C0392B;">Credit note — ' . e($sub) . '</div>';
            }
            $balTxt = $isCredit ? '(' . number_format(abs($bal), $dec) . ')' : number_format($bal, $dec);
            $totTxt = $isCredit ? '(' . number_format(abs((float) $l['total']), $dec) . ')' : number_format((float) $l['total'], $dec);
            $rows .= '<tr>'
                . '<td class="c">' . $i . '</td>'
                . '<td>' . $label . '</td>'
                . '<td class="c">' . e((string) $l['date']) . '</td>'
                . '<td class="c">' . e($l['currency']) . '</td>'
                . '<td class="r">' . $totTxt . '</td>'
                . '<td class="r">' . number_format((float) $l['paid'], $dec) . '</td>'
                . '<td class="r" style="color:' . $col . ';font-weight:bold;">' . $balTxt . '</td>'
                . '</tr>';
        }
        if ($rows === '') $rows = '<tr><td colspan="7" class="c" style="color:#777;">No invoices on record.</td></tr>';

        $outRows = '';
        foreach ($stmt['outstanding'] as $cur => $amt) {
            $dec = in_array($cur, ['USD'], true) ? 2 : 0;
            $outRows .= '<tr class="grand"><td>Total outstanding &nbsp; ' . e($cur) . '</td><td class="r">' . e($cur) . ' ' . number_format((float) $amt, $dec) . '</td></tr>';
        }
        foreach (($stmt['credit_balance'] ?? []) as $cur => $amt) {
            $dec = in_array($cur, ['USD'], true) ? 2 : 0;
            $outRows .= '<tr class="grand"><td style="color:#15803D;">Credit in your favour &nbsp; ' . e($cur) . '</td><td class="r" style="color:#15803D;">' . e($cur) . ' ' . number_format((float) $amt, $dec) . '</td></tr>';
        }
        if ($outRows === '') $outRows = '<tr class="grand"><td>Total outstanding</td><td class="r" style="color:#15803D;">Nil — thank you</td></tr>';

        $footer = trim((string) $t->setting('invoice_footer', 'This is a computer-generated statement.'));

        return <<<HTML
<!doctype html><html><head><meta charset="utf-8"><style>
* { font-family:"DejaVu Sans",Arial,sans-serif; }
body { color:#111; font-size:11px; margin:0; }
.company { font-size:19px; font-weight:bold; text-align:center; }
.sub { text-align:center; font-size:9.5px; }
.webline { text-align:center; font-size:9.5px; padding-top:6px; }
.rule { border:0; border-top:1px solid #111; margin:8px 0 0; }
.qtitle { text-align:right; font-size:20px; font-weight:bold; letter-spacing:1px; margin:14px 0 6px; }
.box { border:1px solid #111; padding:8px 10px; }
table.items { width:100%; border-collapse:collapse; margin-top:12px; }
table.items th { background:#f0f0f0; border:1px solid #111; padding:6px; font-size:10px; }
table.items td { border:1px solid #111; padding:6px; font-size:10px; }
.r { text-align:right; } .c { text-align:center; }
table.tot { border-collapse:collapse; margin-top:10px; }
table.tot td { border:1px solid #111; padding:6px 10px; font-size:11px; }
table.tot tr.grand td { font-weight:bold; font-size:12px; background:#f0f0f0; }
.foot { text-align:center; font-size:9.5px; color:#444; margin-top:24px; border-top:1px solid #ddd; padding-top:8px; }
</style></head><body>
<table width="100%"><tr>
  <td style="width:110px;">{$logoTag}</td>
  <td style="vertical-align:middle;"><div class="company">{$company}</div><div class="sub">{$subHtml}</div></td>
  <td style="width:110px;">&nbsp;</td>
</tr></table>
{$contactLine}
<hr class="rule">
<div class="qtitle">STATEMENT OF ACCOUNT</div>
<table width="100%"><tr>
  <td width="60%" style="vertical-align:top;">
    <div class="box"><div style="font-size:9px;color:#666;text-transform:uppercase;">Account</div>
      <div style="font-weight:bold;font-size:12px;margin-top:3px;">{$cust}</div>
      <div style="font-size:10px;margin-top:2px;">Tel: {$stmt['phone']}</div></div>
  </td>
  <td width="40%" style="vertical-align:top;text-align:right;font-size:10.5px;">Statement date: <b>{$date}</b></td>
</tr></table>
<table class="items">
  <tr><th style="width:22px;">#</th><th>Invoice</th><th style="width:78px;">Date</th><th style="width:44px;">Cur</th><th style="width:80px;">Total</th><th style="width:80px;">Paid</th><th style="width:88px;">Balance</th></tr>
  {$rows}
</table>
<table width="100%"><tr><td width="55%">&nbsp;</td><td width="45%">
  <table class="tot" width="100%">{$outRows}</table>
</td></tr></table>
<div class="foot">{$footer}</div>
</body></html>
HTML;
    }

    private function imgData(string $url): string
    {
        $url = trim($url);
        if ($url === '') return '';
        if (preg_match('#/storage/(.+)$#', $url, $m)) {
            try {
                $rel = $m[1];
                if (Storage::disk('public')->exists($rel)) {
                    $ext  = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
                    $mime = $ext === 'png' ? 'image/png' : ($ext === 'webp' ? 'image/webp' : 'image/jpeg');
                    return 'data:' . $mime . ';base64,' . base64_encode(Storage::disk('public')->get($rel));
                }
            } catch (\Throwable $e) { /* fall through */ }
        }
        return Str::startsWith($url, ['http://', 'https://']) ? $url : '';
    }
}
