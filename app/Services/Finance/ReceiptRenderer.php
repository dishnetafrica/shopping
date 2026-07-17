<?php

namespace App\Services\Finance;

use App\Models\CustomerPayment;
use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;

/**
 * Branded payment-receipt PDF. Mirrors the document style used by InvoiceRenderer / QuotationService
 * (tenant-driven company header, table-based layout so dompdf renders it identically) and saves a copy
 * under storage/receipts. Renders only — reads the payment as recorded, computes no money. Returns null
 * if the PDF engine is not installed, so callers degrade gracefully to a plain message.
 */
final class ReceiptRenderer
{
    public function available(): bool
    {
        return class_exists(\Dompdf\Dompdf::class);
    }

    /** @return array{no:string,path:string,url:string,b64:string,fileName:string}|null */
    public function pdf(Tenant $tenant, CustomerPayment $p): ?array
    {
        if (! $this->available()) {
            return null;
        }
        $no = (string) ($p->receipt_no ?: ('RCPT-' . $p->id));
        $html = $this->html($tenant, $p, $no);

        try {
            $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => true]);
            $dompdf->loadHtml($html);
            // Tenant-configurable (Settings → Documents); defaults match the built-in one-page layout.
            $size = strtoupper((string) $tenant->setting('receipt_page_size', 'A5')) ?: 'A5';
            $orient = strtolower((string) $tenant->setting('receipt_orientation', 'landscape')) === 'portrait' ? 'portrait' : 'landscape';
            $dompdf->setPaper($size, $orient);
            $dompdf->render();
            $pdf = $dompdf->output();
        } catch (\Throwable $e) {
            \Log::warning('ReceiptRenderer failed: ' . $e->getMessage());

            return null;
        }

        $fileName = 'Receipt-' . $no . '.pdf';
        $path = 'receipts/' . $tenant->id . '/' . $fileName;
        try { Storage::disk('public')->put($path, $pdf); } catch (\Throwable $e) { /* best-effort copy */ }

        return ['no' => $no, 'path' => $path, 'url' => $this->url($path), 'b64' => base64_encode($pdf), 'fileName' => $fileName];
    }

    private function url(string $path): string
    {
        try { return Storage::disk('public')->url($path); } catch (\Throwable $e) { return ''; }
    }

    private function money(float $n, string $cur): string
    {
        $dec = $cur === 'USD' ? 2 : 0;
        $sym = $cur === 'USD' ? '$' : '';

        return $sym . number_format($n, $dec) . ($sym ? '' : ' ' . $cur);
    }

    private function html(Tenant $tenant, CustomerPayment $p, string $no): string
    {
        $company = e((string) $tenant->name);
        $address = nl2br(e((string) ($tenant->setting('address', '') ?: '')));
        $tin = trim((string) $tenant->setting('tin', ''));
        $email = trim((string) $tenant->setting('public_email', $tenant->setting('email', '')));
        $phone = trim((string) ($tenant->setting('public_phone', '') ?: $tenant->whatsapp_number));
        $logo = (string) $tenant->setting('logo', '');
        $logoTag = $logo !== '' ? '<img src="' . e($logo) . '" style="height:52px;max-width:120px;object-fit:contain">' : '';

        $cur = (string) ($p->currency ?: $tenant->setting('currency', 'UGX'));
        $amount = $this->money((float) $p->amount, $cur);
        $usd = (float) $p->usd_amount > 0 && $cur !== 'USD' ? ('(≈ ' . $this->money((float) $p->usd_amount, 'USD') . ')') : '';
        $date = optional($p->created_at)->format('d/m/Y') ?: date('d/m/Y');
        $customer = e((string) ($p->customer_name ?: $p->customer_phone ?: 'Customer'));
        $method = e(ucwords(str_replace('_', ' ', (string) ($p->method ?: 'cash'))));
        $ref = e((string) ($p->reference ?: '—'));
        $against = $p->invoice_no ? ('Invoice ' . e((string) $p->invoice_no)) : ($p->order_no ? ('Order ' . e((string) $p->order_no)) : 'Account');
        $by = e((string) ($p->created_by ?: $p->holder ?: ''));
        $contact = array_filter([$phone !== '' ? e($phone) : '', $email !== '' ? e($email) : '']);
        $contactLine = $contact ? implode(' &nbsp;|&nbsp; ', $contact) : '';
        $tinLine = $tin !== '' ? 'TIN: ' . e($tin) : '';

        return <<<HTML
<!doctype html><html><head><meta charset="utf-8"><style>
@page{margin:0;}
*{font-family:"DejaVu Sans",Arial,sans-serif;}
html,body{margin:0;padding:0;}
body{color:#111;font-size:11px;padding:8px 12px;}
.head{display:table;width:100%;border-bottom:2px solid #16a34a;padding-bottom:5px;}
.head .l{display:table-cell;vertical-align:middle;}
.head .r{display:table-cell;vertical-align:middle;text-align:right;}
.company{font-size:16px;font-weight:bold;}
.sub{font-size:9px;color:#444;line-height:1.3;margin-top:1px;}
.title{text-align:center;font-size:16px;font-weight:bold;letter-spacing:2px;color:#16a34a;margin:7px 0 1px;}
.rcptno{text-align:center;font-size:10px;color:#555;margin-bottom:5px;}
table.kv{width:100%;border-collapse:collapse;margin-top:3px;}
table.kv td{padding:3px 10px;font-size:11px;border-bottom:1px solid #eee;}
table.kv td.k{color:#666;width:38%;}
table.kv td.v{font-weight:bold;text-align:right;}
.amtbox{margin-top:8px;border:2px solid #16a34a;border-radius:8px;padding:7px 16px;text-align:center;background:#f4fbf6;}
.amtbox .lbl{font-size:9px;text-transform:uppercase;letter-spacing:1px;color:#16a34a;font-weight:bold;}
.amtbox .amt{font-size:22px;font-weight:800;color:#15803d;margin-top:1px;}
.amtbox .usd{font-size:10px;color:#555;}
.note{margin-top:8px;font-size:10px;color:#555;}
.sig{margin-top:14px;display:table;width:100%;}
.sig .c{display:table-cell;width:50%;font-size:9px;color:#555;}
.sigline{border-bottom:1px solid #111;height:16px;margin-bottom:2px;}
</style></head><body>
<div class="head">
  <div class="l"><div class="company">{$company}</div><div class="sub">{$address}<br>{$contactLine}<br>{$tinLine}</div></div>
  <div class="r">{$logoTag}</div>
</div>

<div class="title">PAYMENT RECEIPT</div>
<div class="rcptno">No. {$no} &nbsp;·&nbsp; {$date}</div>

<table class="kv">
  <tr><td class="k">Received from</td><td class="v">{$customer}</td></tr>
  <tr><td class="k">Payment method</td><td class="v">{$method}</td></tr>
  <tr><td class="k">Reference</td><td class="v">{$ref}</td></tr>
  <tr><td class="k">Allocated to</td><td class="v">{$against}</td></tr>
</table>

<div class="amtbox">
  <div class="lbl">Amount received</div>
  <div class="amt">{$amount}</div>
  <div class="usd">{$usd}</div>
</div>

<div class="note">Received with thanks. This receipt confirms the payment above has been recorded against your account.</div>

<div class="sig">
  <div class="c">Received by: {$by}<div class="sigline"></div>Signature</div>
  <div class="c" style="text-align:right;">&nbsp;<div class="sigline"></div>Official stamp</div>
</div>
</body></html>
HTML;
    }
}
