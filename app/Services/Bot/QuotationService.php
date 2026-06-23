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
 */
class QuotationService
{
    public function available(): bool
    {
        return class_exists(\Dompdf\Dompdf::class);
    }

    /** @return array|null ['no'=>, 'b64'=>, 'path'=>, 'url'=>, 'fileName'=>, 'total'=>, 'currency'=>] */
    public function generate(Tenant $tenant, string $customerPhone, string $customerName, array $quote): ?array
    {
        if (! $this->available()) return null;
        $matched = array_filter($quote['lines'], fn ($l) => $l['matched']);
        if (! $matched) return null;

        $no   = $this->quoteNo($tenant);
        $html = $this->html($tenant, $no, $customerPhone, $customerName, $quote);

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
            'total'    => $quote['total'],
            'currency' => $quote['currency'],
        ];
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

    private function html(Tenant $tenant, string $no, string $phone, string $name, array $quote): string
    {
        $cur     = $quote['currency'];
        $accent  = (string) ($tenant->setting('theme_accent', '') ?: '#103A8C');
        $company = e((string) $tenant->name);
        $addr    = e((string) $tenant->setting('address', ''));
        $email   = e((string) $tenant->setting('public_email', $tenant->setting('email', '')));
        $cphone  = e((string) ($tenant->setting('public_phone', '') ?: $tenant->whatsapp_number));
        $web     = e((string) $tenant->setting('website', ''));
        $valid   = (int) ($tenant->setting('quote_validity_days', 14));
        $terms   = (string) ($tenant->setting('quote_terms', 'Prices are per the current list and may change. Delivery and payment terms confirmed on order. Wholesale items may have a minimum order.'));
        $logo    = (string) $tenant->setting('logo', '');
        $logoTag = '';
        if ($logo !== '') {
            $src = Str::startsWith($logo, ['http://', 'https://', '/']) ? $logo : $logo;
            $logoTag = '<img src="' . e($src) . '" style="height:54px;max-width:180px;object-fit:contain">';
        }

        $rows = '';
        $i = 0;
        foreach ($quote['lines'] as $l) {
            $i++;
            if (! $l['matched']) {
                $rows .= '<tr><td>' . $i . '</td><td>' . e($l['name']) . ' <i style="color:#888">(to be confirmed)</i></td><td class="r">' . (int) $l['qty'] . '</td><td class="r">—</td><td class="r">—</td></tr>';
                continue;
            }
            $unit = $l['unit'] ? ' / ' . e($l['unit']) : '';
            $rows .= '<tr><td>' . $i . '</td><td>' . e($l['name']) . '</td><td class="r">' . (int) $l['qty'] . '</td><td class="r">' . $cur . ' ' . number_format($l['price']) . $unit . '</td><td class="r">' . $cur . ' ' . number_format($l['sum']) . '</td></tr>';
        }

        $date  = now()->format('j M Y');
        $until = now()->addDays($valid)->format('j M Y');
        $custLine = $name !== '' ? e($name) . ' · ' . e($phone) : e($phone);

        return <<<HTML
<!doctype html><html><head><meta charset="utf-8"><style>
* { font-family: DejaVu Sans, sans-serif; }
body { color:#222; font-size:12px; margin:0; }
.head { border-bottom:3px solid {$accent}; padding:0 0 14px; margin-bottom:18px; }
.company { font-size:20px; font-weight:bold; color:{$accent}; }
.muted { color:#666; font-size:11px; }
.title { float:right; text-align:right; }
.title h1 { color:{$accent}; font-size:22px; margin:0; letter-spacing:1px; }
table { width:100%; border-collapse:collapse; margin-top:8px; }
th { background:{$accent}; color:#fff; text-align:left; padding:7px 8px; font-size:11px; }
td { padding:7px 8px; border-bottom:1px solid #eee; }
.r { text-align:right; }
.total { font-size:15px; font-weight:bold; color:{$accent}; }
.terms { margin-top:22px; color:#555; font-size:10.5px; line-height:1.5; }
.meta td { border:0; padding:2px 0; }
</style></head><body>
<div class="head">
  <div class="title"><h1>QUOTATION</h1><div class="muted">{$no}</div></div>
  {$logoTag}
  <div class="company">{$company}</div>
  <div class="muted">{$addr}<br>{$cphone} · {$email} {$web}</div>
</div>
<table class="meta">
  <tr><td style="width:90px"><b>To</b></td><td>{$custLine}</td><td style="width:90px"><b>Date</b></td><td>{$date}</td></tr>
  <tr><td><b>Valid until</b></td><td>{$until}</td><td><b>Currency</b></td><td>{$cur}</td></tr>
</table>
<table>
  <tr><th style="width:30px">#</th><th>Item</th><th class="r" style="width:60px">Qty</th><th class="r" style="width:120px">Unit price</th><th class="r" style="width:120px">Amount</th></tr>
  {$rows}
  <tr><td colspan="4" class="r total">Total</td><td class="r total">{$cur} {$this->fmt($quote['total'])}</td></tr>
</table>
<div class="terms"><b>Terms:</b> {$terms}<br>This quotation is valid until {$until}. To order, reply on WhatsApp.</div>
</body></html>
HTML;
    }

    private function fmt(float $n): string
    {
        return number_format($n);
    }
}
