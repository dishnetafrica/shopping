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
    public function generate(Tenant $tenant, string $customerPhone, string $customerName, array $quote, ?string $quoteNo = null, ?string $portalUrl = null, float $freight = 0.0): ?array
    {
        if (! $this->available()) return null;
        $matched = array_filter($quote['lines'], fn ($l) => $l['matched']);
        if (! $matched) return null;

        // On the running series, take the real number BEFORE drawing the PDF, so the PDF shows the
        // number the quotation is saved under (persist keeps a series number; see QuoteNumber::isSeries).
        $no   = $quoteNo ?: (\App\Services\Sales\QuoteNumber::sequential($tenant)
            ? \App\Services\Sales\QuoteNumber::next($tenant) : $this->quoteNo($tenant));

        // Compute discount + VAT once so the PDF and the saved/returned total all agree.
        $discPct = (float) $tenant->setting('quote_discount_pct', 0);
        $vatPct  = (float) $tenant->setting('quote_vat_pct', 0);
        $incVat  = (bool) $tenant->setting('prices_include_vat', false);
        $totals  = $this->priced($quote, $discPct, $vatPct, $incVat);

        // Freight (additive, Q3) sits on top of the priced lines + VAT to form the grand total.
        $freight = max(0.0, (float) $freight);
        $totals['freight'] = $freight;
        $totals['grand']   = (float) $totals['grand'] + $freight;

        // Internet-provider businesses: the DishNet layout, with one-time and monthly charges apart.
        // The prices are the ones just calculated above; the ISP layer only groups and lays them out.
        $isp = null;
        if (\App\Support\Vertical::of($tenant) === \App\Support\Vertical::ISP) {
            $isp = $this->ispDoc($tenant, $no, $customerPhone, $customerName, $quote, $totals, $vatPct, $freight);
            $totals['grand'] = $isp['one_off_total'];
        }
        $html = $isp ? $isp['html'] : $this->html($tenant, $no, $customerPhone, $customerName, $quote, $totals, $portalUrl);

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
        ] + ($isp ? [
            // Internet provider: the stored total is the one-time total; the monthly total is kept beside it.
            'monthly_total'   => $isp['monthly_total'],
            'service_lines'   => $isp['service_lines'],
            'billing_by_name' => $isp['billing_by_name'],
        ] : []);
    }

    /**
     * One-time / monthly figures for an Internet-provider quotation, priced exactly as generate() does
     * (same discount, VAT and VAT-inclusive settings), for a save that has no PDF behind it.
     */
    private function ispFigures(Tenant $tenant, string $phone, string $name, array $quote): array
    {
        $vatPct = (float) $tenant->setting('quote_vat_pct', 0);
        $totals = $this->priced($quote, (float) $tenant->setting('quote_discount_pct', 0), $vatPct, (bool) $tenant->setting('prices_include_vat', false));
        $d = $this->ispDoc($tenant, (string) ($quote['no'] ?? ''), $phone, $name, $quote, $totals, $vatPct, 0.0);
        unset($d['html']);

        return $d;
    }

    /**
     * The Internet-provider quotation (App\Services\Isp\IspQuoteLayout). Everything printed comes from
     * the business's settings, the customer record, the salesperson and the catalogue. Terms print
     * only once approved in the Sales Brain; until then the section is left out, and the platform's
     * general default terms (written for another market) are never used here.
     *
     * @return array{html:string,one_off_total:float,monthly_total:float,service_lines:string[],billing_by_name:array}
     */
    private function ispDoc(Tenant $tenant, string $no, string $phone, string $name, array $quote, array $totals, float $vatPct, float $freight): array
    {
        $names = [];
        foreach ($totals['lines'] as $l) {
            if (! empty($l['matched'])) {
                $names[] = (string) ($l['name'] ?? '');
            }
        }
        $products = [];
        foreach (\App\Models\Product::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereIn('name', $names)->get(['name', 'billing', 'service_line']) as $p) {
            $products[mb_strtolower(trim((string) $p->name))] = ['billing' => $p->billing, 'service_line' => $p->service_line];
        }
        $split = \App\Services\Isp\IspQuote::split($totals['lines'], $products, $vatPct / 100);

        $digits = preg_replace('/[^0-9]/', '', $phone);
        $cust = \App\Models\CustomerProfile::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('phone', $digits)->first();
        $prepId = app(\App\Services\Sales\SalesOwnership::class)->preparedBy($tenant, $phone);
        $prep = $prepId ? \App\Models\User::where('tenant_id', $tenant->id)->find($prepId) : null;
        $valid = (int) $tenant->setting('quote_validity_days', 14);
        $lines = fn ($s) => array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $s) ?: [])));

        $html = \App\Services\Isp\IspQuoteLayout::html([
            'company'       => (string) $tenant->name,
            'address_lines' => $lines($tenant->setting('address', '')),
            'phone'         => trim((string) ($tenant->setting('public_phone', '') ?: $tenant->whatsapp_number)),
            'email'         => trim((string) $tenant->setting('public_email', $tenant->setting('email', ''))),
            'website'       => trim((string) $tenant->setting('website', '')),
            'tagline'       => trim((string) $tenant->setting('quote_tagline', '')),
            'quote_no'      => $no,
            'date'          => now()->format('j M Y'),
            'valid_until'   => now()->addDays($valid > 0 ? $valid : 14)->format('j M Y'),
            'currency'      => (string) $quote['currency'],
            'customer'      => [
                'name'          => $name !== '' ? $name : ($cust?->name ?: '+' . $digits),
                'company'       => (string) ($cust?->contact_person ? $cust->name : ''),
                'address_lines' => array_filter([(string) ($cust?->address ?? ''), trim(implode(', ', array_filter([(string) ($cust?->city ?? ''), (string) ($cust?->country ?? '')])))]),
                'phone'         => $digits !== '' ? '+' . $digits : '',
                'email'         => (string) ($cust?->email ?? ''),
            ],
            'service_lines' => $split['service_lines'],
            'split'         => $split,
            'vat_label'     => $vatPct > 0 ? 'VAT ' . rtrim(rtrim(number_format($vatPct, 2), '0'), '.') . '%' : 'Tax',
            // Only terms management has PUBLISHED in the Sales Brain; none published → none printed.
            'terms'         => app(\App\Services\Sales\Brain\SalesBrain::class)->quoteTerms($tenant, $split['service_lines']),
            'prepared_by'   => ['name' => (string) ($prep?->name ?? ''), 'phone' => $prep && $prep->phone ? '+' . preg_replace('/[^0-9]/', '', (string) $prep->phone) : ''],
        ]);

        $billing = [];
        foreach (['one_off', 'monthly'] as $g) {
            foreach ($split[$g] as $l) {
                $billing[mb_strtolower(trim((string) $l['name']))] = $g;
            }
        }

        return [
            'html'            => $html,
            'one_off_total'   => round($split['one_off_total']['total'] + max(0.0, $freight), 2),
            'monthly_total'   => $split['monthly_total']['total'],
            'service_lines'   => $split['service_lines'],
            'billing_by_name' => $billing,
        ];
    }

    /**
     * Persist a sent quotation + its line items so it can be listed, resent, and
     * converted into an order later. Best-effort: never throws into the send path.
     */
    public function persist(Tenant $tenant, string $phone, string $name, array $quote, array $doc, string $source): ?\App\Models\Quotation
    {
        try {
            // Internet provider: one-time and monthly totals are kept apart even when the PDF step did
            // not run (no PDF engine, a render failure, or a caller that skips it) — never one sum.
            if (! array_key_exists('monthly_total', $doc) && \App\Support\Vertical::of($tenant) === \App\Support\Vertical::ISP) {
                $f = $this->ispFigures($tenant, $phone, $name, $quote);
                $doc = array_merge($doc, $f, ['total' => $f['one_off_total']]);
            }
            $valid = (int) ($tenant->setting('quote_validity_days', 14));
            if ($valid <= 0) $valid = 14;
            $q = \App\Models\Quotation::create([
                'tenant_id'      => $tenant->id,
                'quote_no'       => (string) ($doc['no'] ?? ''),
                'customer_name'  => $name !== '' ? $name : null,
                'customer_phone' => preg_replace('/[^0-9]/', '', $phone),
                // Prepared by: the staff member creating it, or the customer's salesperson for a bot draft.
                'sales_exec_id'  => app(\App\Services\Sales\SalesOwnership::class)->preparedBy($tenant, $phone),
                'currency'       => (string) ($quote['currency'] ?? $tenant->setting('currency', 'UGX')),
                'total'          => (float) ($doc['total'] ?? $quote['total'] ?? 0),
                'status'         => 'sent',
                'source'         => $source,
                'valid_until'    => now()->addDays($valid)->toDateString(),
                'pdf_path'       => (string) ($doc['path'] ?? ''),
                'send_count'     => 1,
                'last_sent_at'   => now(),
            ] + (array_key_exists('monthly_total', $doc) ? ['meta' => [
                // Internet provider: what is paid every month, kept beside the one-time total.
                'monthly_total' => (float) $doc['monthly_total'],
                'service_lines' => (array) ($doc['service_lines'] ?? []),
            ]] : []));
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
                ] + (isset($doc['billing_by_name']) ? ['billing' => $doc['billing_by_name'][mb_strtolower(trim((string) ($l['name'] ?? '')))] ?? 'one_off'] : []));
            }
            // BOS spine (Slice 4): record QuoteCreated for the customer timeline. Guarded + never-throws.
            \App\BOS\Emit::customerEvent($tenant->id, 'QuoteCreated', (string) $q->customer_phone, [
                'quote_no' => (string) $q->quote_no, 'total' => (float) $q->total, 'currency' => (string) $q->currency,
            ], ['actor' => ['type' => 'system', 'name' => $source]]);
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

    private function html(Tenant $tenant, string $no, string $phone, string $name, array $quote, array $totals, ?string $portalUrl = null): string
    {
        // v4.46 — the approval table already prints "Company Stamp & Authorised Signature" over an
        // empty box. Fill it instead of floating an image somewhere else on the page.
        //
        // `.abox` is a FIXED 88px tall with 18px of padding and an ~11px label — roughly 59px of
        // room. The stamp is capped at 72px and the box grows only when one is actually printed,
        // so an unstamped quotation is byte-identical to what it was before this drop.
        $stampPx = min(72, \App\Services\Documents\DocumentStamp::sizePx($tenant));
        $stamp = \App\Services\Documents\DocumentStamp::img($tenant, 'quotation', $stampPx);
        $aboxH = $stamp !== '' ? (88 + $stampPx - 40) : 88;
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

        // Meta the professional layout shows in the KV panel.
        $validDays = (int) $tenant->setting('quote_validity_days', 14);
        $validUntil = now()->addDays($validDays > 0 ? $validDays : 14)->format('d/m/Y');
        $payShort  = trim((string) $tenant->setting('quote_payment_terms', ''));
        $vatPctNum = (float) $totals['vatPct'];
        $vatP      = rtrim(rtrim(number_format($vatPctNum, 2), '0'), '.');
        $taxCell   = $vatPctNum > 0 ? ($vatP . '%') : '&mdash;';

        // line rows (PHP-driven zebra so it renders in dompdf; thumbnails via base64 imgData).
        $rows = '';
        $i = 0;
        foreach ($totals['lines'] as $l) {
            $i++;
            $zebra = ($i % 2 === 0) ? ' class="alt"' : '';
            $unit  = trim((string) ($l['unit'] ?? ''));
            $qtyUnit = number_format((float) ($l['qty'] ?? 0), 2) . ($unit !== '' ? ' ' . strtoupper(e($unit)) : ' PCS');
            $bv = trim(implode(' · ', array_filter([
                trim((string) ($l['brand'] ?? '')),
                trim((string) ($l['variant'] ?? '')),
            ])));
            $bvHtml = $bv !== '' ? '<div class="bv">' . e($bv) . '</div>' : '';
            $imgSrc = $this->imgData((string) ($l['image'] ?? ''));
            $thumb  = $imgSrc !== '' ? '<img src="' . $imgSrc . '" width="30" height="30" style="border-radius:4px;object-fit:contain">' : '<div class="noimg">&nbsp;</div>';

            if (empty($l['matched'])) {
                $rows .= '<tr' . $zebra . '><td class="c">' . $i . '</td><td class="c">' . $thumb . '</td>'
                       . '<td><div class="pn">' . e($l['name']) . '</div><div class="bv" style="color:#a15c00">To be confirmed</div></td>'
                       . '<td class="c">' . $qtyUnit . '</td><td class="r">&mdash;</td><td class="c">&mdash;</td><td class="c">&mdash;</td><td class="r">&mdash;</td></tr>';
                continue;
            }
            $rows .= '<tr' . $zebra . '>'
                . '<td class="c">' . $i . '</td>'
                . '<td class="c">' . $thumb . '</td>'
                . '<td><div class="pn">' . e($l['name']) . '</div>' . $bvHtml . '</td>'
                . '<td class="c">' . $qtyUnit . '</td>'
                . '<td class="r">' . number_format((float) $l['_unit'], $m2) . '</td>'
                . '<td class="c">' . number_format((float) $l['_disc'], 2) . '</td>'
                . '<td class="c">' . $taxCell . '</td>'
                . '<td class="r b">' . number_format((float) $l['_sub'], $m2) . '</td>'
                . '</tr>';
        }

        $subB = number_format($totals['subtotalBefore'], $m2);
        $disc = number_format($totals['discount'], $m2);
        $subT = number_format($totals['subTotal'], $m2);
        $vat  = number_format($totals['vat'], $m2);
        $freightNum = (float) ($totals['freight'] ?? 0);
        $freight = number_format($freightNum, $m2);
        $grand = number_format($totals['grand'], $m2);

        // optional blocks
        $subHtmlBlock = $subHtml !== '' ? '<div class="sub">' . $subHtml . '</div>' : '';
        $webLineHtml  = $webLine !== '' ? '<div class="webline">' . $webLine . '</div>' : '';
        $tinLineHtml  = $tinLine !== '' ? '&nbsp;&middot; ' . $tinLine : '';
        $salesRow     = $sales !== '' ? '<tr><td class="k">Sales Representative</td><td class="v">' . $sales . '</td></tr>' : '';
        $payRow       = $payShort !== '' ? '<tr><td class="k">Payment Terms</td><td class="v">' . e($payShort) . '</td></tr>' : '';
        $lpoRow       = $lpo !== '' ? '<tr><td class="k">Customer LPO</td><td class="v">' . $lpo . '</td></tr>' : '';
        $vatRow       = $vatPctNum > 0
            ? '<tr><td class="l">VAT (' . $vatP . '%)</td><td class="v">' . $vat . '</td></tr>'
            : '';
        $freightRow   = $freightNum > 0
            ? '<tr><td class="l">Freight</td><td class="v">' . $freight . '</td></tr>'
            : '';
        $incNote = ! empty($totals['inclusive'])
            ? '<div style="font-size:9px;color:#555;margin-top:4px;">Listed prices are VAT-inclusive; unit prices are shown net and ' . $vatP . '% VAT is broken out — the total equals the VAT-inclusive price.</div>'
            : '';

        // QR / live-portal panel (only when we have a portal URL). QR image is dependency-optional
        // (composer require bacon/bacon-qr-code); the link is always shown so the panel is complete.
        $qrPanel = '';
        if ($portalUrl) {
            $qrUri  = $this->qrDataUri($portalUrl);
            $qrImg  = $qrUri !== '' ? '<td style="width:104px"><img src="' . $qrUri . '" width="96" height="96"></td>' : '';
            $shortUrl = e(preg_replace('#^https?://#', '', (string) $portalUrl));
            $qrPanel = '<div class="qr"><table width="100%"><tr>' . $qrImg
                . '<td style="padding-left:' . ($qrImg ? '10' : '0') . 'px;vertical-align:top">'
                . '<div class="qh">Open your live quotation</div>'
                . '<div class="qt2">Scan or visit the link to view, edit &amp; approve online. It always shows the latest revision.</div>'
                . '<div class="qu">' . $shortUrl . '</div></td></tr></table></div>';
        }

        return <<<HTML
<!doctype html><html><head><meta charset="utf-8"><style>
* { font-family: "DejaVu Sans", Arial, sans-serif; }
@page { margin: 40px 34px 58px; }
body { color:#1a1a1a; font-size:11px; margin:0; }
.formcode { text-align:right; font-size:9px; font-weight:bold; }
.company { font-size:20px; font-weight:bold; letter-spacing:.3px; }
.sub { font-size:9.5px; color:#555; line-height:1.4; margin-top:2px; }
.qbadge { text-align:right; }
.qbadge .qt { font-size:25px; font-weight:bold; letter-spacing:2px; color:#0C831F; }
.qbadge .qn { font-size:11.5px; font-weight:bold; margin-top:2px; }
.rule { border:0; border-top:2px solid #0C831F; margin:10px 0 13px; }
.tobox { border:1px solid #d8ddd9; border-radius:8px; padding:11px 13px; }
.tobox .k { font-size:8.5px; color:#6b7280; text-transform:uppercase; letter-spacing:.06em; font-weight:bold; }
.tobox .v { font-size:13px; font-weight:bold; margin:2px 0 8px; }
.kv { width:100%; border-collapse:collapse; }
.kv td { padding:6px 10px; font-size:10.5px; border-bottom:1px solid #eef1ee; }
.kv td.k { color:#6b7280; font-weight:bold; width:54%; }
.kv td.v { text-align:right; font-weight:bold; }
table.items { width:100%; border-collapse:collapse; margin-top:4px; }
table.items th { background:#0C831F; color:#fff; padding:8px 6px; font-size:9.5px; text-align:center; font-weight:bold; }
table.items td { border-bottom:1px solid #eef1ee; padding:7px 6px; font-size:10px; vertical-align:middle; }
table.items tr.alt td { background:#fafbfa; }
.pn { font-weight:bold; font-size:10.5px; } .bv { color:#6b7280; font-size:9px; margin-top:1px; }
.noimg { width:30px; height:30px; border-radius:4px; background:#eef2ed; }
.r { text-align:right; } .c { text-align:center; } .b { font-weight:bold; }
table.tot { width:100%; border-collapse:collapse; }
table.tot td { padding:7px 12px; font-size:11px; }
table.tot td.l { color:#555; } table.tot td.v { text-align:right; font-weight:bold; }
table.tot tr.grand td { background:#0C831F; color:#fff; font-size:13.5px; font-weight:bold; }
.qr { border:1px solid #d8ddd9; border-radius:10px; padding:11px; }
.qr .qh { font-weight:bold; font-size:11.5px; color:#0C831F; }
.qr .qt2 { font-size:9px; color:#333; margin-top:3px; line-height:1.4; }
.qr .qu { font-size:8.5px; color:#555; margin-top:4px; }
.terms { font-size:9px; color:#444; line-height:1.55; }
.terms .h { font-weight:bold; color:#111; }
.appr { width:100%; border-collapse:collapse; margin-top:16px; }
.appr td { width:50%; vertical-align:top; padding-right:14px; }
.abox { border:1px dashed #b7c0b9; border-radius:10px; height:{$aboxH}px; padding:9px 11px; }
.abox .t { font-size:8.5px; color:#6b7280; text-transform:uppercase; letter-spacing:.06em; font-weight:bold; }
.foot { position:fixed; bottom:-40px; left:0; right:0; text-align:center; font-size:8.5px; color:#8a8f8a; border-top:1px solid #e6e9e6; padding-top:6px; }
.pageno:after { content: "Page " counter(page); }
</style></head><body>
<div class="foot">{$company} · Quotation {$no}{$tinLineHtml} · <span class="pageno"></span></div>
<table width="100%"><tr>
  <td style="width:120px;vertical-align:middle;">{$logoTag}</td>
  <td style="vertical-align:middle;padding-left:6px;">
    <div class="company">{$company}</div>
    {$subHtmlBlock}{$webLineHtml}
  </td>
  <td class="qbadge" style="vertical-align:middle;"><div class="qt">QUOTATION</div><div class="qn">No. {$no}</div></td>
</tr></table>
<hr class="rule">

<table width="100%" style="margin-bottom:12px"><tr>
  <td width="54%" style="vertical-align:top;padding-right:16px;">
    <div class="tobox">
      <div class="k">Quotation For</div><div class="v">{$custName}</div>
      <div class="k">Attention</div><div style="font-size:10.5px">{$phone}</div>
      <div class="k" style="margin-top:8px">Contact Person</div><div style="font-size:10.5px">&nbsp;</div>
    </div>
  </td>
  <td width="46%" style="vertical-align:top;">
    <table class="kv">
      <tr><td class="k">Quotation No.</td><td class="v">{$no}</td></tr>
      <tr><td class="k">Date</td><td class="v">{$date}</td></tr>
      <tr><td class="k">Valid Until</td><td class="v">{$validUntil}</td></tr>
      {$salesRow}
      <tr><td class="k">Currency</td><td class="v">{$cur}</td></tr>
      {$payRow}
      {$lpoRow}
    </table>
  </td>
</tr></table>

<table class="items">
  <tr>
    <th style="width:20px;">#</th>
    <th style="width:40px;">Image</th>
    <th>Product / Brand</th>
    <th style="width:62px;">Qty/Unit</th>
    <th style="width:80px;">Unit Price</th>
    <th style="width:44px;">Disc%</th>
    <th style="width:40px;">Tax</th>
    <th style="width:90px;">Line Total</th>
  </tr>
  {$rows}
</table>

<table width="100%" style="margin-top:12px;"><tr>
  <td width="46%" style="vertical-align:top;padding-right:16px;">{$qrPanel}</td>
  <td width="54%" style="vertical-align:top;">
    <table class="tot">
      <tr><td class="l">Subtotal</td><td class="v">{$subB}</td></tr>
      <tr><td class="l">Discount</td><td class="v">{$disc}</td></tr>
      {$vatRow}
      {$freightRow}
      <tr class="grand"><td>GRAND TOTAL &nbsp; {$cur}</td><td class="v">{$grand}</td></tr>
    </table>
    {$incNote}
  </td>
</tr></table>

<div class="terms" style="margin-top:16px">
  <div class="h">Terms &amp; Conditions</div>
  <div><span class="h">Payment:</span> {$tPay}</div>
  <div><span class="h">Delivery:</span> {$tDel}</div>
  <div><span class="h">Pricing:</span> {$tPri}</div>
  <div><span class="h">Cancellation:</span> {$tCan}</div>
</div>

<table class="appr">
  <tr>
    <td><div class="abox"><div class="t">Company Stamp &amp; Authorised Signature</div>{$stamp}</div></td>
    <td style="padding-right:0"><div class="abox"><div class="t">Customer Approval — Name / Signature / Date</div></div></td>
  </tr>
</table>
</body></html>
HTML;
    }

    /**
     * QR code as a data URI for the live-quotation link. Dependency-optional: if
     * bacon/bacon-qr-code is installed (composer require bacon/bacon-qr-code) an SVG QR is embedded
     * (dompdf v3 renders SVG via php-svg-lib); otherwise returns '' and the PDF shows the link only.
     */
    private function qrDataUri(string $text): string
    {
        try {
            if (class_exists(\BaconQrCode\Writer::class)) {
                // Margin is the QUIET ZONE in modules. The QR spec requires >= 4; the previous
                // value of 1 produced a QR many phone cameras refused to scan. 4 restores it.
                $renderer = new \BaconQrCode\Renderer\ImageRenderer(
                    new \BaconQrCode\Renderer\RendererStyle\RendererStyle(220, 4),
                    new \BaconQrCode\Renderer\Image\SvgImageBackEnd()
                );
                $svg = (new \BaconQrCode\Writer($renderer))->writeString($text);

                return 'data:image/svg+xml;base64,' . base64_encode($svg);
            }
        } catch (\Throwable $e) {
            // fall through to link-only
        }

        return '';
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
