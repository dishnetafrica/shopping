<?php

namespace App\Services\Bot;

use App\Models\Tenant;
use App\Support\BrandDefaults;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Builds a branded PDF quotation from a deterministic OrderCalculator quote and returns the bytes
 * (base64 for WhatsApp) plus a saved copy for records. Uses dompdf if installed; if not, callers
 * fall back to a plain-text quote so nothing breaks.
 *
 * LAYOUT: models the Patronics Services quotation form (form code, centred company header,
 * To / quote-detail boxes, per-line discount, VAT block, payment terms, signatures). Every
 * label and the tax/discount rates come from tenant settings, so any tenant gets the same
 * structure with their own details. Money is table-based HTML => renders the same in dompdf.
 *
 * Tenant settings used (all optional; sensible defaults shown):
 *   quote_form_code      e.g. "PSUL-FR-F36"   (top-right form reference)
 *   quote_subtitle       company sub-lines under the name (use \n between lines)
 *   tin                  company TIN shown under the header
 *   public_email / email, public_phone, website, address, logo   (existing brand settings)
 *   quote_vat_pct        VAT percentage, default 0 (Patronics = 18)
 *   quote_discount_pct   default line discount %, default 0 (per-line $l['disc'] overrides)
 *   sales_employee       shown as "Sales Employee: ..."
 *   quote_prepared_by    shown on the Prepared By line
 *   quote_customer_lpo   optional LPO reference
 *   quote_terms_payment / quote_delivery / quote_pricing / quote_cancellation   term paragraphs
 */
class QuotationService
{
    public function available(): bool
    {
        return class_exists(\Dompdf\Dompdf::class);
    }

    /** @return array|null ['no'=>, 'b64'=>, 'path'=>, 'url'=>, 'fileName'=>, 'total'=>, 'currency'=>] */
    public function generate(Tenant $tenant, string $customerPhone, string $customerName, array $quote, ?string $quoteNo = null): ?array
    {
        if (! $this->available()) return null;
        $matched = array_filter($quote['lines'], fn ($l) => $l['matched']);
        if (! $matched) return null;

        $no   = $quoteNo ?: $this->quoteNo($tenant);

        // Compute discount + VAT once so the PDF and the saved/returned total all agree.
        $discPct = (float) $tenant->setting('quote_discount_pct', 0);
        $vatPct  = (float) $tenant->setting('quote_vat_pct', 0);
        $incVat  = (bool) $tenant->setting('prices_include_vat', false);
        $totals  = $this->priced($quote, $discPct, $vatPct, $incVat);

        $html = $this->html($tenant, $no, $customerPhone, $customerName, $quote, $totals);

        try {
            $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => true]);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4');
            $dompdf->render();
            $pdf = $dompdf->output();
        } catch (\Throwable $e) {
            \Log::warning('QuotationService render failed: ' . $e->getMessage());
            return null;
        }

        $fileName = "Quotation-{$no}.pdf";
        $path     = "quotations/{$tenant->id}/{$fileName}";
        try { Storage::disk('public')->put($path, $pdf); } catch (\Throwable $e) { /* record copy is best-effort */ }

        return [
            'no'       => $no,
            'b64'      => base64_encode($pdf),
            'path'     => $path,
            'url'      => $this->url($path),
            'fileName' => $fileName,
            'total'    => $totals['grand'],           // VAT/discount-inclusive, matches the PDF
            'currency' => $quote['currency'],
        ];
    }

    /**
     * Persist a sent quotation + its line items so it can be listed, resent, and
     * converted into an order later. Best-effort: never throws into the send path.
     */
    public function persist(Tenant $tenant, string $phone, string $name, array $quote, array $doc, string $source): ?\App\Models\Quotation
    {
        try {
            $valid = (int) ($tenant->setting('quote_validity_days', 14));
            if ($valid <= 0) $valid = 14;
            $q = \App\Models\Quotation::create([
                'tenant_id'      => $tenant->id,
                'quote_no'       => (string) ($doc['no'] ?? ''),
                'customer_name'  => $name !== '' ? $name : null,
                'customer_phone' => preg_replace('/[^0-9]/', '', $phone),
                'currency'       => (string) ($quote['currency'] ?? $tenant->setting('currency', 'UGX')),
                'total'          => (float) ($doc['total'] ?? $quote['total'] ?? 0),
                'status'         => 'sent',
                'source'         => $source,
                'valid_until'    => now()->addDays($valid)->toDateString(),
                'pdf_path'       => (string) ($doc['path'] ?? ''),
                'send_count'     => 1,
                'last_sent_at'   => now(),
            ]);
            foreach (($quote['lines'] ?? []) as $l) {
                if (empty($l['matched'])) continue;
                \App\Models\QuotationItem::create([
                    'quotation_id' => $q->id,
                    'tenant_id'    => $tenant->id,
                    'name'         => (string) ($l['name'] ?? ''),
                    'qty'          => (int) ($l['qty'] ?? 1),
                    'unit_price'   => (float) ($l['price'] ?? 0),
                    'line_total'   => (float) ($l['sum'] ?? 0),
                    'unit_label'   => ((string) ($l['unit'] ?? '')) ?: null,
                    'image_url'    => ((string) ($l['image'] ?? '')) ?: null,
                    'matched'      => true,
                ]);
            }
            return $q;
        } catch (\Throwable $e) {
            \Log::warning('Quotation persist failed: ' . $e->getMessage());
            return null;
        }
    }

    private function quoteNo(Tenant $tenant): string
    {
        $prefix = (string) ($tenant->order_prefix ?: 'Q');
        return strtoupper($prefix) . '-Q' . now()->format('ymd') . '-' . strtoupper(Str::random(4));
    }

    private function url(string $path): string
    {
        try { return Storage::disk('public')->url($path); } catch (\Throwable $e) { return ''; }
    }

    /**
     * Apply per-line discount + VAT and return the enriched lines plus the totals block.
     * A line may carry its own $l['disc'] (percent); otherwise the tenant default applies.
     *
     * @return array{lines:array,subtotalBefore:float,discount:float,subTotal:float,vat:float,grand:float}
     */
    private function priced(array $quote, float $discPct, float $vatPct, bool $pricesIncludeVat = false): array
    {
        $subtotalBefore = 0.0;
        $subTotal       = 0.0;
        $lines          = [];

        // When the catalogue price already INCLUDES VAT (e.g. a "prices inclusive of VAT"
        // list / website), back the VAT out of each unit price so we never charge VAT twice.
        // The ex-VAT figures feed the grid + subtotal; adding VAT back gives a grand total that
        // equals the original VAT-inclusive price (after any discount) — so the quotation total
        // matches what the customer sees on the website.
        $rate  = $vatPct / 100;
        $backOut = ($pricesIncludeVat && $rate > 0);

        foreach ($quote['lines'] as $l) {
            $qty   = (float) ($l['qty'] ?? 0);
            $price = (float) ($l['price'] ?? 0);
            $unit  = $backOut ? ($price / (1 + $rate)) : $price;   // ex-VAT unit price
            $matched = ! empty($l['matched']);
            $disc  = isset($l['disc']) ? (float) $l['disc'] : $discPct;
            $disc  = max(0.0, min(100.0, $disc));

            $gross = $qty * $unit;
            $after = $unit * (1 - $disc / 100);
            $sub   = $qty * $after;

            if ($matched) {
                $subtotalBefore += $gross;
                $subTotal       += $sub;
            }

            $l['_unit']  = $unit;   // price shown in the grid (ex-VAT when inclusive mode)
            $l['_disc']  = $disc;
            $l['_after'] = $after;
            $l['_sub']   = $sub;
            $lines[]     = $l;
        }

        $discount = $subtotalBefore - $subTotal;
        $vat      = $subTotal * $rate;
        $grand    = $subTotal + $vat;

        return [
            'lines'          => $lines,
            'subtotalBefore' => $subtotalBefore,
            'discount'       => $discount,
            'subTotal'       => $subTotal,
            'vat'            => $vat,
            'vatPct'         => $vatPct,
            'inclusive'      => $backOut,
            'grand'          => $grand,
        ];
    }

    private function html(Tenant $tenant, string $no, string $phone, string $name, array $quote, array $totals): string
    {
        $cur = $quote['currency'];
        $dec = \App\Services\Pricing::decimalsForCurrency($cur);
        $m2  = 2; // money in the quote grid always shows 2dp, like the Patronics form

        $formCode = e((string) $tenant->setting('quote_form_code', ''));
        $company  = e((string) $tenant->name);
        $subtitle = (string) $tenant->setting('quote_subtitle', '');
        if ($subtitle === '') {
            $subtitle = trim((string) $tenant->setting('address', ''));
        }
        $subHtml  = nl2br(e($subtitle));
        $email    = trim((string) $tenant->setting('public_email', $tenant->setting('email', '')));
        $cphone   = trim((string) ($tenant->setting('public_phone', '') ?: $tenant->whatsapp_number));
        $web      = trim((string) $tenant->setting('website', ''));
        $tin      = trim((string) $tenant->setting('tin', ''));

        $webParts = array_filter([
            $web   !== '' ? 'Website: ' . e($web) : '',
            $email !== '' ? 'Email: ' . e($email) : '',
        ], fn ($x) => $x !== '');
        $webLine = $webParts ? implode(' &nbsp;|&nbsp; ', $webParts) : '';
        $tinLine = $tin !== '' ? 'TIN: ' . e($tin) : '';

        $logo    = (string) $tenant->setting('logo', '');
        $logoTag = $logo !== ''
            ? '<img src="' . e($logo) . '" style="height:56px;max-width:90px;object-fit:contain">'
            : '&nbsp;';

        $date   = now()->format('d/m/Y');
        $lpo    = e((string) $tenant->setting('quote_customer_lpo', ''));
        $sales  = e((string) $tenant->setting('sales_employee', ''));
        $prepBy = e((string) $tenant->setting('quote_prepared_by', ''));

        // Term paragraphs (Patronics defaults; each overridable per tenant).
        $tPay = e((string) $tenant->setting('quote_terms_payment',
            "100% payment shall be done with the order before dispatch of the goods, unless stated otherwise.\n3% PER MONTH interest will charge at all overdue accounts."));
        $tDel = e((string) $tenant->setting('quote_delivery',
            'Ex-stock Kampala, Postdated Check and LPO required before goods are dispatched.'));
        $tPri = e((string) $tenant->setting('quote_pricing',
            'Valid for 30 days pricing subject to change there after without prior notification'));
        $tCan = e((string) $tenant->setting('quote_cancellation',
            'Should the order be cancelled by the client, will be charged on a pro-rate basis according to the stage the contract has reached at the time of cancellation. Such charges will not be less than 25% of the order value.'));
        $tPay = nl2br($tPay);

        $custName = $name !== '' ? e($name) : e($phone);

        // line rows
        $rows = '';
        $i = 0;
        foreach ($totals['lines'] as $l) {
            $i++;
            $unit = trim((string) ($l['unit'] ?? ''));
            $qtyUnit = number_format((float) ($l['qty'] ?? 0), 2) . ($unit !== '' ? ' ' . strtoupper(e($unit)) : ' PCS');
            if (empty($l['matched'])) {
                $rows .= '<tr><td class="c">' . $i . '</td><td>' . e($l['name'])
                       . ' <i style="color:#888">(to be confirmed)</i></td><td class="c">' . $qtyUnit
                       . '</td><td class="r">&mdash;</td><td class="c">&mdash;</td><td class="r">&mdash;</td><td class="r">&mdash;</td></tr>';
                continue;
            }
            $rows .= '<tr>'
                . '<td class="c">' . $i . '</td>'
                . '<td>' . e($l['name']) . '</td>'
                . '<td class="c">' . $qtyUnit . '</td>'
                . '<td class="r">' . number_format((float) $l['_unit'], $m2) . '</td>'
                . '<td class="c">' . number_format((float) $l['_disc'], 2) . '</td>'
                . '<td class="r">' . number_format((float) $l['_after'], $m2) . '</td>'
                . '<td class="r">' . number_format((float) $l['_sub'], $m2) . '</td>'
                . '</tr>';
        }

        $subB = number_format($totals['subtotalBefore'], $m2);
        $disc = number_format($totals['discount'], $m2);
        $subT = number_format($totals['subTotal'], $m2);
        $vat  = number_format($totals['vat'], $m2);
        $vatP = rtrim(rtrim(number_format($totals['vatPct'], 2), '0'), '.');
        $grand = number_format($totals['grand'], $m2);

        // optional blocks
        $formCodeHtml = $formCode !== '' ? '<div class="formcode">' . $formCode . '</div>' : '';
        $webLineHtml  = $webLine !== '' ? '<div class="webline">' . $webLine . '</div>' : '';
        $tinLineHtml  = $tinLine !== '' ? '<div class="webline" style="padding-top:2px;">' . $tinLine . '</div>' : '';
        $salesHtml    = $sales !== '' ? '<div class="sales"><b>Sales Employee</b> &nbsp;' . $sales . '</div>' : '';
        $lpoCell      = $lpo !== '' ? $lpo : '&nbsp;';
        $prepCell     = $prepBy !== '' ? '<span style="position:relative;top:-2px;">' . $prepBy . '</span>' : '';
        $vatRow       = $totals['vatPct'] > 0
            ? '<tr><td class="lbl">VAT-' . $vatP . '%</td><td class="r">' . $vat . '</td></tr>'
            : '';
        $incNote = ! empty($totals['inclusive'])
            ? '<div style="font-size:9px;color:#555;margin-top:4px;">Listed prices are VAT-inclusive; unit prices above are shown net and ' . $vatP . '% VAT is broken out — the total equals the VAT-inclusive price.</div>'
            : '';

        return <<<HTML
<!doctype html><html><head><meta charset="utf-8"><style>
* { font-family: "DejaVu Sans", Arial, sans-serif; }
body { color:#111; font-size:11px; margin:0; }
.formcode { text-align:right; font-size:9px; font-weight:bold; }
.company { font-size:19px; font-weight:bold; text-align:center; line-height:1.1; }
.sub { text-align:center; font-size:9.5px; line-height:1.35; }
.webline { text-align:center; font-size:9.5px; padding-top:6px; }
.rule { border:0; border-top:1px solid #111; margin:8px 0 0; }
.qtitle { text-align:right; font-size:20px; font-weight:bold; letter-spacing:1px; margin:14px 0 6px; }
.box { border:1px solid #111; padding:8px 10px; }
.kv { width:100%; border-collapse:collapse; }
.kv td { border:1px solid #111; padding:5px 8px; font-size:10.5px; }
.kv td.k { font-weight:bold; width:46%; }
.sales { font-size:10.5px; margin:12px 0 4px; }
table.items { width:100%; border-collapse:collapse; margin-top:4px; }
table.items th { background:#f0f0f0; border:1px solid #111; padding:6px; font-size:10px; text-align:center; }
table.items td { border:1px solid #111; padding:6px; font-size:10px; vertical-align:top; }
.r { text-align:right; } .c { text-align:center; }
table.tot { border-collapse:collapse; }
table.tot td { border:1px solid #111; padding:6px 10px; font-size:10.5px; }
table.tot td.lbl { font-weight:bold; }
table.tot tr.grand td { font-weight:bold; font-size:12px; }
.terms { margin-top:24px; font-size:9.5px; line-height:1.5; }
.terms .h { font-weight:bold; }
.sig td { padding-top:30px; font-size:10px; }
.sigline { border-bottom:1px solid #111; height:1px; }
.pageno { text-align:right; font-size:9px; color:#444; margin-top:22px; }
</style></head><body>
{$formCodeHtml}
<table width="100%"><tr>
  <td style="width:90px;vertical-align:middle;">{$logoTag}</td>
  <td style="vertical-align:middle;">
    <div class="company">{$company}</div>
    <div class="sub">{$subHtml}</div>
  </td>
  <td style="width:90px;">&nbsp;</td>
</tr></table>
{$webLineHtml}
{$tinLineHtml}
<hr class="rule">

<div class="qtitle">QUOTATION</div>

<table width="100%"><tr>
  <td width="55%" style="vertical-align:top;padding-right:14px;">
    <div class="box" style="height:118px;">
      <div><b>To :</b>&nbsp; {$custName}</div>
      <div style="height:70px;">&nbsp;</div>
      <div><b>Contact Person :</b></div>
    </div>
  </td>
  <td width="45%" style="vertical-align:top;">
    <table class="kv">
      <tr><td class="k">Quote No.</td><td>{$no}</td></tr>
      <tr><td class="k">Date :</td><td>{$date}</td></tr>
      <tr><td class="k">Customer LPO :</td><td>{$lpoCell}</td></tr>
      <tr><td class="k">Currency :</td><td>{$cur}</td></tr>
    </table>
  </td>
</tr></table>

{$salesHtml}

<table class="items">
  <tr>
    <th style="width:22px;">#</th>
    <th>Description</th>
    <th style="width:74px;">Qty/Unit</th>
    <th style="width:80px;">Price</th>
    <th style="width:48px;">Disc %</th>
    <th style="width:88px;">Price After Disc</th>
    <th style="width:95px;">Sub-Total</th>
  </tr>
  {$rows}
</table>

<table width="100%" style="margin-top:10px;"><tr>
  <td width="52%">&nbsp;</td>
  <td width="48%">
    <table class="tot" width="100%">
      <tr><td class="lbl">Subtotal Before Discount</td><td class="r">{$subB}</td></tr>
      <tr><td class="lbl">Discount</td><td class="r">{$disc}</td></tr>
      <tr><td class="lbl">Sub Total</td><td class="r">{$subT}</td></tr>
      {$vatRow}
      <tr class="grand"><td>Total &nbsp; {$cur}</td><td class="r">{$grand}</td></tr>
    </table>
    {$incNote}
  </td>
</tr></table>

<div class="terms">
  <div class="h">Terms of Payments:</div>
  <div>&nbsp;&nbsp;{$tPay}</div>
  <div style="height:8px;">&nbsp;</div>
  <div><span class="h">Delivery</span>: {$tDel}</div>
  <div><span class="h">Pricing:</span> {$tPri}</div>
  <div><span class="h">Cancellation:</span> {$tCan}</div>
</div>

<table class="sig" width="100%">
  <tr>
    <td width="18%">Prepared By :</td>
    <td width="32%">{$prepCell}<div class="sigline"></div></td>
    <td width="18%" style="padding-left:18px;">Signature :</td>
    <td width="32%"><div class="sigline"></div></td>
  </tr>
  <tr>
    <td>Authorised By :</td>
    <td><div class="sigline"></div></td>
    <td style="padding-left:18px;">Signature:</td>
    <td><div class="sigline"></div></td>
  </tr>
</table>

<div class="pageno">Page 1 of 1</div>
</body></html>
HTML;
    }

    private function fmt(float $n, int $dec = 0): string
    {
        return number_format($n, $dec);
    }

    /**
     * Turn a product image_url into something dompdf can render. Local /storage images are
     * embedded as a base64 data URI (most reliable, no network); a remote http(s) URL is
     * returned as-is for dompdf to fetch (isRemoteEnabled is on). Returns '' if unavailable.
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
            } catch (\Throwable $e) { /* fall through to URL */ }
        }

        return Str::startsWith($url, ['http://', 'https://']) ? $url : '';
    }
}
