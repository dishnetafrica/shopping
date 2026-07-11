<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * GET|POST /papi/order-invoice-pdf — a clean, branded, server-side WHOLESALE invoice PDF for an
 * order, rendered with dompdf (same engine as the quotation, so it looks equally professional).
 *
 * WHY POST: the seller can tweak the on-screen invoice (custom line prices, discount, freight,
 * currency) for THIS order only before exporting. The panel posts that exact on-screen state so
 * the PDF is WYSIWYG. If called with only an order id (GET), it falls back to the SAVED order in
 * the tenant base currency, so a plain link still works.
 *
 * The layout deliberately mirrors QuotationService (centred company header, To/detail boxes,
 * bordered line grid, totals block, terms) — but as an INVOICE: Bill-To business, amount paid /
 * balance due, payment terms + bank details, and NO editable controls or browser chrome. No
 * "groceries delivered fast" tagline — this is a B2B trade document.
 */
class OrderInvoiceController extends Controller
{
    public function pdf(Request $r)
    {
        $t = $r->user() ? $r->user()->tenant : null;
        if (! $t) abort(403);
        if (! class_exists(\Dompdf\Dompdf::class)) abort(503, 'PDF engine unavailable');

        $data = $this->collect($r, $t);
        if (empty($data['lines'])) abort(422, 'Nothing to invoice');

        $html = $this->html($t, $data);

        try {
            $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => true]);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4');
            $dompdf->render();
            $pdf = $dompdf->output();
        } catch (\Throwable $e) {
            \Log::warning('OrderInvoice render failed: ' . $e->getMessage());
            abort(500, 'Could not build the invoice PDF');
        }

        $file = 'Invoice-' . preg_replace('/[^A-Za-z0-9_\-]/', '', $data['no']) . '.pdf';
        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $file . '"',
        ]);
    }

    /**
     * Build the invoice data from the posted on-screen state, or fall back to the saved order.
     * All money in $data is already in the DISPLAY currency (the panel converts before posting);
     * the fallback path uses the tenant base currency as-is.
     */
    private function collect(Request $r, Tenant $t): array
    {
        $cur = strtoupper(trim((string) $r->input('currency', ''))) ?: (string) ($t->setting('currency', 'UGX') ?: 'UGX');
        $dec = $r->has('dec') ? max(0, min(2, (int) $r->input('dec'))) : (in_array($cur, ['USD'], true) ? 2 : 0);

        $lines = [];
        $posted = $r->input('lines');
        if (is_string($posted)) { $posted = json_decode($posted, true); }

        if (is_array($posted) && $posted) {
            foreach ($posted as $l) {
                $name = trim((string) ($l['name'] ?? ''));
                if ($name === '') continue;
                $qty  = (float) ($l['qty'] ?? 0);
                $unit = (float) ($l['unit'] ?? 0);
                if ($qty <= 0) $qty = 1;
                $lines[] = ['name' => $name, 'qty' => $qty, 'unit' => $unit, 'amount' => $qty * $unit,
                            'sku' => trim((string) ($l['sku'] ?? '')), 'uom' => trim((string) ($l['uom'] ?? ''))];
            }
        }

        // Order reference + customer: prefer posted, else the saved order.
        $order = null;
        $id = (int) $r->input('id', $r->input('row', 0));
        if ($id > 0) {
            $order = Order::where('tenant_id', $t->id)->find($id);
        }

        $no       = trim((string) $r->input('order_no', '')) ?: ($order ? (string) ($order->order_no ?: ('#' . $order->id)) : ('#' . $id));
        $customer = trim((string) $r->input('customer', '')) ?: ($order ? (string) ($order->customer_name ?? '') : '');
        $phone    = trim((string) $r->input('phone', '')) ?: ($order ? (string) ($order->customer_phone ?? '') : '');
        $address  = trim((string) $r->input('address', '')) ?: ($order ? (string) ($order->location ?? '') : '');
        $status   = trim((string) $r->input('status', '')) ?: ($order ? (string) ($order->status ?? '') : '');
        $notes    = trim((string) $r->input('notes', '')) ?: ($order ? (string) ($order->notes ?? '') : '');

        // Fallback: no posted lines but we have a saved order → read its items in base currency.
        if (! $lines && $order) {
            $cur = (string) ($t->setting('currency', 'UGX') ?: 'UGX');
            $dec = in_array($cur, ['USD'], true) ? 2 : 0;
            $items = is_array($order->items_json) ? $order->items_json : [];
            foreach ($items as $it) {
                $name = trim((string) ($it['name'] ?? ''));
                if ($name === '') continue;
                $qty  = (float) ($it['qty'] ?? 1);
                $unit = (float) ($it['price'] ?? 0);
                if ($qty <= 0) $qty = 1;
                $lines[] = ['name' => $name, 'qty' => $qty, 'unit' => $unit, 'amount' => $qty * $unit, 'sku' => '', 'uom' => ''];
            }
        }

        $subtotal = array_sum(array_map(fn ($l) => $l['amount'], $lines));
        $discount = max(0.0, (float) $r->input('discount', 0));
        $freight  = max(0.0, (float) $r->input('delivery', $r->input('freight', 0)));
        $grand    = max(0.0, $subtotal - $discount + $freight);

        // Paid / balance: prefer posted paid; else derive from the saved order total vs amount_paid.
        if ($r->has('paid')) {
            $paid = max(0.0, (float) $r->input('paid', 0));
        } elseif ($order) {
            $paid = (float) ($order->amount_paid ?? 0);
        } else {
            $paid = 0.0;
        }
        $balance = round($grand - $paid, 2);

        // Credit terms: tenant default number of days, unless the order is already paid in full.
        // A per-order override can be posted as terms_days (e.g. a special agreement with a buyer).
        $termsDays = $r->has('terms_days') ? max(0, (int) $r->input('terms_days')) : max(0, (int) $t->setting('invoice_terms_days', 0));
        if ($balance <= 0.5) {
            $termsLabel = 'Paid in full';
            $dueDate    = '';
        } elseif ($termsDays > 0) {
            $termsLabel = 'Net ' . $termsDays . ' days';
            $dueDate    = now()->addDays($termsDays)->format('d/m/Y');
        } else {
            $termsLabel = 'Due on receipt';
            $dueDate    = '';
        }

        return [
            'no' => $no, 'customer' => $customer, 'phone' => $phone, 'address' => $address,
            'status' => $status, 'notes' => $notes, 'payment' => trim((string) $r->input('payment', '')),
            'cur' => $cur, 'dec' => $dec, 'lines' => $lines,
            'subtotal' => $subtotal, 'discount' => $discount, 'freight' => $freight,
            'grand' => $grand, 'paid' => $paid, 'balance' => $balance,
            'disc_label' => trim((string) $r->input('disc_label', 'Discount')) ?: 'Discount',
            'terms_days' => $termsDays, 'terms_label' => $termsLabel, 'due_date' => $dueDate,
        ];
    }

    private function money(float $n, string $cur, int $dec): string
    {
        return $cur . ' ' . number_format($n, $dec);
    }

    private function html(Tenant $t, array $d): string
    {
        $cur = $d['cur'];
        $dec = $d['dec'];

        // --- Branding (same settings the quotation uses, so both documents match) ---
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

        $logo    = (string) $t->setting('logo', '');
        $logoTag = $this->imgData($logo);
        $logoTag = $logoTag !== ''
            ? '<img src="' . e($logoTag) . '" style="height:58px;max-width:110px;object-fit:contain">'
            : '&nbsp;';

        $title = e((string) $t->setting('invoice_title', 'INVOICE')) ?: 'INVOICE';
        $date  = now()->format('d/m/Y');
        $no    = e((string) $d['no']);

        // --- Bill-To ---
        $custName = $d['customer'] !== '' ? e($d['customer']) : ($d['phone'] !== '' ? e($d['phone']) : 'Cash Customer');
        $billLines = array_filter([
            $d['phone']   !== '' ? 'Tel: ' . e($d['phone']) : '',
            $d['address'] !== '' ? e($d['address']) : '',
        ], fn ($x) => $x !== '');
        $billExtra = $billLines ? '<div style="margin-top:4px;font-size:10px;line-height:1.5;">' . implode('<br>', $billLines) . '</div>' : '';

        // --- Line rows ---
        $rows = '';
        $i = 0;
        foreach ($d['lines'] as $l) {
            $i++;
            $qty = rtrim(rtrim(number_format((float) $l['qty'], 2), '0'), '.');
            $uom = trim((string) ($l['uom'] ?? ''));
            if ($uom !== '') $qty .= ' ' . strtoupper(e($uom));
            $sku = $l['sku'] !== '' ? '<div style="font-size:8.5px;color:#777;">' . e($l['sku']) . '</div>' : '';
            $rows .= '<tr>'
                . '<td class="c">' . $i . '</td>'
                . '<td>' . e($l['name']) . $sku . '</td>'
                . '<td class="c">' . $qty . '</td>'
                . '<td class="r">' . number_format((float) $l['unit'], $dec) . '</td>'
                . '<td class="r">' . number_format((float) $l['amount'], $dec) . '</td>'
                . '</tr>';
        }

        $sub   = $this->money($d['subtotal'], $cur, $dec);
        $grand = $this->money($d['grand'], $cur, $dec);
        $discRow = $d['discount'] > 0
            ? '<tr><td class="lbl">' . e($d['disc_label']) . '</td><td class="r">- ' . $this->money($d['discount'], $cur, $dec) . '</td></tr>'
            : '';
        $freightRow = $d['freight'] > 0
            ? '<tr><td class="lbl">Freight / Delivery</td><td class="r">' . $this->money($d['freight'], $cur, $dec) . '</td></tr>'
            : '';

        // Paid / balance block (only if any payment or an explicit balance is meaningful).
        $payBlock = '';
        if ($d['paid'] > 0 || $d['balance'] < $d['grand']) {
            $balColor = $d['balance'] > 0.5 ? '#C0392B' : '#15803D';
            $balLbl   = $d['balance'] > 0.5 ? 'Balance Due' : 'Paid in Full';
            $payBlock = '<tr><td class="lbl">Amount Paid</td><td class="r">' . $this->money($d['paid'], $cur, $dec) . '</td></tr>'
                . '<tr class="bal"><td style="color:' . $balColor . '">' . $balLbl . '</td><td class="r" style="color:' . $balColor . '">'
                . $this->money(max(0, $d['balance']), $cur, $dec) . '</td></tr>';
        }

        $statusChip = $d['status'] !== ''
            ? '<span style="border:1px solid #111;border-radius:3px;padding:1px 6px;font-size:9px;">' . e($d['status']) . '</span>'
            : '';

        // Payment-terms + due-date rows in the info box (credit-sale aware).
        $termsRow = '<tr><td class="k">Terms</td><td>' . e((string) $d['terms_label']) . '</td></tr>';
        $dueRow   = $d['due_date'] !== ''
            ? '<tr><td class="k">Due Date</td><td><b>' . e((string) $d['due_date']) . '</b></td></tr>'
            : '';
        $notesHtml = $d['notes'] !== ''
            ? '<div class="notes"><b>Notes:</b> ' . nl2br(e($d['notes'])) . '</div>'
            : '';

        // --- Terms / bank (wholesale defaults; each overridable per tenant) ---
        $terms = trim((string) $t->setting('invoice_terms',
            'Goods remain the property of ' . strip_tags($company) . ' until paid in full. '
            . 'Please quote the invoice number on payment. Claims for shortage or damage must be made within 48 hours of receipt.'));
        $bank  = trim((string) $t->setting('bank_details', ''));
        $bankHtml = $bank !== ''
            ? '<div class="terms"><span class="h">Payment details:</span><br>' . nl2br(e($bank)) . '</div>'
            : '';
        $termsHtml = $terms !== ''
            ? '<div class="terms"><span class="h">Terms:</span> ' . nl2br(e($terms)) . '</div>'
            : '';

        // Credit-terms line: only meaningful on an unpaid/partly-paid credit sale.
        $creditHtml = '';
        if ($d['balance'] > 0.5 && (int) $d['terms_days'] > 0) {
            $default = 'This is a credit sale on ' . $d['terms_label']
                . ($d['due_date'] !== '' ? ' — full balance due by ' . $d['due_date'] : '')
                . '. Overdue balances may attract interest and hold further supply.';
            $credit  = trim((string) $t->setting('credit_terms', $default));
            if ($credit !== '') {
                $creditHtml = '<div class="terms"><span class="h">Payment terms:</span> ' . nl2br(e($credit)) . '</div>';
            }
        }
        $footer = trim((string) $t->setting('invoice_footer',
            'Thank you for your business. This is a computer-generated invoice and is valid without signature.'));

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
    <th style="width:80px;">Qty / Unit</th>
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

<div class="foot">{$footer}</div>
</body></html>
HTML;
    }

    /**
     * Local /storage image → base64 data URI (most reliable in dompdf); a remote http(s) URL is
     * returned as-is (isRemoteEnabled is on). Returns '' if not usable.
     */
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
