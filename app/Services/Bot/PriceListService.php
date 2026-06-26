<?php

namespace App\Services\Bot;

use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Builds a branded PDF PRICE LIST from the tenant's live, active, priced products — so the
 * figures are always current (never a stale hand-made file). Returns the bytes (base64 for
 * WhatsApp) plus a saved copy. Uses dompdf if installed; callers fall back gracefully if not.
 *
 * This is the answer to a "price list / rate list" request for catalogue-style (manufacturer)
 * tenants. Bulk/negotiated pricing is handled separately by the quotation flow.
 */
class PriceListService
{
    public function available(): bool
    {
        return class_exists(\Dompdf\Dompdf::class);
    }

    /**
     * @return array|null ['b64'=>, 'url'=>, 'path'=>, 'fileName'=>, 'count'=>]
     */
    public function generate(Tenant $tenant): ?array
    {
        if (! $this->available()) return null;

        $products = Product::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('active', true)
            ->orderBy('name')
            ->limit(300)
            ->get();
        if ($products->isEmpty()) return null;

        $html = $this->html($tenant, $products);

        try {
            $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => true]);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4');
            $dompdf->render();
            $pdf = $dompdf->output();
        } catch (\Throwable $e) {
            \Log::warning('PriceListService render failed: ' . $e->getMessage());
            return null;
        }

        $fileName = 'Price-List-' . now()->format('ymd') . '.pdf';
        $path     = "price-lists/{$tenant->id}/{$fileName}";
        try { Storage::disk('public')->put($path, $pdf); } catch (\Throwable $e) { /* best-effort */ }

        return [
            'b64'      => base64_encode($pdf),
            'url'      => $this->url($path),
            'path'     => $path,
            'fileName' => $fileName,
            'count'    => $products->count(),
        ];
    }

    private function url(string $path): string
    {
        try { return Storage::disk('public')->url($path); } catch (\Throwable $e) { return ''; }
    }

    /** @param \Illuminate\Support\Collection $products */
    private function html(Tenant $tenant, $products): string
    {
        $accent  = (string) ($tenant->setting('theme_accent', '') ?: '#103A8C');
        $cur     = (string) ($tenant->setting('currency', 'UGX') ?: 'UGX');
        $company = e((string) $tenant->name);
        $addr    = e((string) $tenant->setting('address', ''));
        $email   = e((string) $tenant->setting('public_email', $tenant->setting('email', '')));
        $cphone  = e((string) ($tenant->setting('public_phone', '') ?: $tenant->whatsapp_number));
        $web     = e((string) $tenant->setting('website', ''));
        $terms   = (string) ($tenant->setting('price_list_terms',
            'Prices are per unit shown and may change without notice. Bulk / wholesale quantities are quoted on request. Delivery and payment terms confirmed on order.'));
        $logo    = (string) $tenant->setting('logo', '');
        $logoTag = $logo !== '' ? '<img src="' . e($logo) . '" style="height:54px;max-width:180px;object-fit:contain">' : '';

        $rows = '';
        $i = 0;
        foreach ($products as $p) {
            $i++;
            $pack = '';
            if ((int) $p->pack_size > 0) {
                $pack = (int) $p->pack_size . ' / ' . ($p->unit_label ?: 'unit');
            } elseif ($p->unit_label) {
                $pack = e((string) $p->unit_label);
            } else {
                $pack = '—';
            }
            $price = ((float) $p->price > 0)
                ? $cur . ' ' . number_format((float) $p->price)
                : '<i style="color:#888">On request</i>';
            $moq = ((int) $p->moq > 0) ? (int) $p->moq . ' ' . e((string) ($p->unit_label ?: '')) : '—';

            $rows .= '<tr><td>' . $i . '</td><td>' . e((string) $p->name) . '</td><td>' . $pack
                   . '</td><td class="r">' . $price . '</td><td class="r">' . $moq . '</td></tr>';
        }

        $date    = now()->format('j M Y');
        $contact = implode(' · ', array_filter([$cphone, $email, $web], fn ($x) => trim((string) $x) !== ''));
        $metaLine = trim($addr) !== '' ? ($addr . ($contact !== '' ? '<br>' . $contact : '')) : $contact;
        $terms   = e($terms);

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
.foot { margin-top:18px; color:#666; font-size:10px; border-top:1px solid #eee; padding-top:8px; }
</style></head><body>
<div class="head">
  <div class="title"><h1>PRICE LIST</h1><div class="muted">{$date}</div></div>
  {$logoTag}
  <div class="company">{$company}</div>
  <div class="muted">{$metaLine}</div>
</div>
<table>
  <thead><tr><th>#</th><th>Product</th><th>Pack / Unit</th><th class="r">Price</th><th class="r">MOQ</th></tr></thead>
  <tbody>{$rows}</tbody>
</table>
<div class="foot">{$terms}</div>
</body></html>
HTML;
    }
}
