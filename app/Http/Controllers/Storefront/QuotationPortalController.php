<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Quotation;
use App\Models\Tenant;
use App\Services\Bot\QuotationService;
use App\Services\Sales\QuotationConvertService;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * The public customer quotation portal — the "single workspace" for a quotation, reached by an
 * unguessable token (no login), at /{shop}/q/{token}. It is a THIN projection layer over the
 * existing Quotation record + the frozen QuotationService/QuotationConvertService. It never
 * duplicates pricing, numbering, PDF or conversion logic — it only reads the record and calls
 * the frozen services. All three customer touchpoints (portal, PDF, WhatsApp) render from this
 * one Quotation, so they stay in sync.
 */
class QuotationPortalController extends Controller
{
    /** Resolve [tenant, base, quotation] from the shop + token, or 404. */
    private function resolve(?string $shop, string $token): array
    {
        if ($shop !== null && $shop !== '') {
            $tenant = Tenant::where('slug', strtolower(trim($shop)))->first();
            $base   = '/' . strtolower(trim($shop));
        } else {
            $tenant = Tenant::byCustomDomain(request()->getHost());
            $base   = '';
        }
        abort_if(! $tenant, 404);
        abort_if(($tenant->status ?? 'active') === 'suspended', 404);
        app(TenantContext::class)->set($tenant->id);

        $q = Quotation::where('tenant_id', $tenant->id)
            ->where('public_token', $token)
            ->with('items')
            ->first();
        abort_if(! $q, 404);

        return [$tenant, $base, $q];
    }

    /** The portal page itself (static HTML hydrated from the data endpoint). */
    public function show(?string $shop = null, string $token = '')
    {
        [$tenant, $base, $q] = $this->resolve($shop, $token);

        // Stamp first-view for the "expected response" UX + audit (best-effort, never blocks).
        if ($q->viewed_at === null) {
            try { $q->forceFill(['viewed_at' => now()])->save(); } catch (\Throwable $e) {
            }
        }

        $cfg = [
            'base'      => $base,
            'token'     => (string) $q->public_token,
            'quoteNo'   => (string) $q->quote_no,
            'company'   => (string) $tenant->name,
            'logo'      => $tenant->setting('logo', '') ? $this->imageUrl((string) $tenant->setting('logo', '')) : '',
            'currency'  => (string) $q->currency,
            'dataUrl'   => url(rtrim($base, '/') . '/q/' . $q->public_token . '/data'),
            'pdfUrl'    => url(rtrim($base, '/') . '/q/' . $q->public_token . '/pdf'),
            'shopUrl'   => url(rtrim($base, '/') . '/shop'),
            'waNumber'  => preg_replace('/[^0-9]/', '', (string) ($tenant->whatsapp_number ?? '')),
        ];

        $path = resource_path('storefront/quotation.html');
        $html = is_file($path) ? file_get_contents($path) : '<h1>Quotation unavailable</h1>';
        $html = str_replace('__QUOTE_CONFIG__', json_encode($cfg, JSON_UNESCAPED_UNICODE), $html);

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('Cache-Control', 'no-store');
    }

    /** The live quotation data the portal renders — a projection of the current record. */
    public function data(?string $shop = null, string $token = '')
    {
        [$tenant, $base, $q] = $this->resolve($shop, $token);

        $items = $q->items->map(function ($it) {
            $qty   = (int) $it->qty;
            $price = (float) $it->unit_price;
            $line  = (float) ($it->line_total ?: $qty * $price);

            return [
                'name'       => (string) $it->name,
                'brand'      => (string) ($it->brand ?? ''),
                'variant'    => (string) ($it->variant ?? ''),
                'unit'       => (string) ($it->unit_label ?? ''),
                'qty'        => $qty,
                'unit_price' => $price,
                'line_total' => $line,
                'image'      => $this->imageUrl((string) ($it->image_url ?? '')),
            ];
        })->values();

        $subtotal = (float) $items->sum('line_total');
        $grand    = (float) $q->total;

        // Sales rep (assigned user, else the tenant's configured sales employee, else blank).
        $rep = optional($q->salesExec)->name ?: (string) $tenant->setting('sales_employee', '');

        // A simple, honest timeline from the fields we have today (revision detail arrives in Q3).
        $timeline = [
            ['label' => 'Quotation created', 'at' => optional($q->created_at)->toIso8601String(), 'done' => true],
            ['label' => 'Customer viewed',   'at' => optional($q->viewed_at)->toIso8601String(),  'done' => $q->viewed_at !== null],
            ['label' => 'Approved',          'at' => optional($q->accepted_at ?? null)->toIso8601String() ?? null, 'done' => in_array($q->status, ['accepted', 'converted'], true)],
            ['label' => 'Sales Order',       'at' => null, 'done' => $q->status === 'converted'],
        ];

        return response()->json([
            'ok'           => true,
            'quote_no'     => (string) $q->quote_no,
            'status'       => (string) $q->status,
            'status_label' => $this->statusLabel((string) $q->status),
            'currency'     => (string) $q->currency,
            'valid_until'  => optional($q->valid_until)->toDateString(),
            'is_open'      => $q->isOpen(),
            'is_converted' => $q->isConverted(),
            'revision'     => 1, // becomes dynamic when the revision engine (Q3) lands
            'sales_rep'    => $rep,
            'customer'     => [
                'name'    => (string) $q->customer_name,
                'phone'   => (string) $q->customer_phone,
                'address' => (string) ($q->delivery_address ?? ''),
            ],
            'items'    => $items,
            'totals'   => [
                'subtotal' => $subtotal,
                'discount' => 0.0,
                'tax'      => 0.0,
                'freight'  => 0.0,
                'grand'    => $grand,
            ],
            'remarks'   => (string) ($q->remarks ?? ''),
            'timeline'  => $timeline,
            'order_no'  => $q->order ? (string) $q->order->order_no : null,
            'pdf_url'   => url(rtrim($base, '/') . '/q/' . $q->public_token . '/pdf'),
            'shop_url'  => url(rtrim($base, '/') . '/shop'),
        ]);
    }

    /** Stream the quotation PDF — serve the stored file, else regenerate via the frozen renderer. */
    public function pdf(?string $shop = null, string $token = '')
    {
        [$tenant, $base, $q] = $this->resolve($shop, $token);

        $disk = Storage::disk('public');
        if ($q->pdf_path && $disk->exists($q->pdf_path)) {
            return $disk->response($q->pdf_path, 'Quotation-' . $q->quote_no . '.pdf', ['Content-Type' => 'application/pdf'], 'inline');
        }

        // Regenerate from the saved line items using the FROZEN QuotationService (same as the panel).
        $svc = app(QuotationService::class);
        if (! $svc->available()) {
            abort(503, 'PDF engine unavailable');
        }
        $lines = $q->items->map(fn ($it) => [
            'name'    => (string) $it->name,
            'qty'     => (int) $it->qty,
            'price'   => (float) $it->unit_price,
            'sum'     => (float) ($it->line_total ?: $it->qty * $it->unit_price),
            'unit'    => (string) ($it->unit_label ?? ''),
            'image'   => (string) ($it->image_url ?? ''),
            'matched' => true,
        ])->all();
        $quote = ['lines' => $lines, 'currency' => (string) $q->currency, 'total' => (float) $q->total];
        $doc   = $svc->generate($tenant, (string) $q->customer_phone, (string) $q->customer_name, $quote, (string) $q->quote_no);
        abort_if(! $doc, 503, 'Could not render the quotation PDF');

        if (! empty($doc['path'])) {
            try { $q->forceFill(['pdf_path' => $doc['path']])->save(); } catch (\Throwable $e) {
            }
        }
        $bytes = ! empty($doc['b64']) ? base64_decode($doc['b64']) : ($doc['path'] ? $disk->get($doc['path']) : '');

        return response($bytes, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="Quotation-' . $q->quote_no . '.pdf"',
        ]);
    }

    /** Share the quotation by WhatsApp or Email (reuses the frozen gateway / Mail::raw pattern). */
    public function share(Request $r, ?string $shop = null, string $token = '')
    {
        [$tenant, $base, $q] = $this->resolve($shop, $token);
        $channel = strtolower((string) $r->input('channel', 'whatsapp'));
        $to      = trim((string) $r->input('to', ''));

        $portal  = url(rtrim($base, '/') . '/q/' . $q->public_token);
        $summary = "📄 Quotation {$q->quote_no}\nTotal: {$q->currency} " . number_format((float) $q->total)
            . (optional($q->valid_until)->toFormattedDateString() ? "\nValid until: " . $q->valid_until->toFormattedDateString() : '')
            . "\nView & approve online: {$portal}";

        try {
            if ($channel === 'email') {
                if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
                    return response()->json(['ok' => false, 'error' => 'Enter a valid email address.'], 422);
                }
                $bytes = $this->pdfBytes($tenant, $q);
                Mail::raw($summary, function ($m) use ($to, $bytes, $q, $tenant) {
                    $m->to($to)->subject('Quotation ' . $q->quote_no . ' from ' . $tenant->name);
                    if ($bytes !== '') {
                        $m->attachData($bytes, 'Quotation-' . $q->quote_no . '.pdf', ['mime' => 'application/pdf']);
                    }
                });

                return response()->json(['ok' => true, 'channel' => 'email']);
            }

            // WhatsApp: send the summary text + the PDF document to the chosen number (default the customer).
            $phone = preg_replace('/[^0-9]/', '', $to) ?: (string) $q->customer_phone;
            $gw    = app(WhatsAppManager::class)->forTenant($tenant);
            $gw->sendText($tenant->whatsapp_instance, $phone, $summary);
            if (method_exists($gw, 'sendDocument')) {
                $media = $q->pdf_path ? Storage::disk('public')->url($q->pdf_path) : ('data:application/pdf;base64,' . base64_encode($this->pdfBytes($tenant, $q)));
                $gw->sendDocument($tenant->whatsapp_instance, $phone, $media, 'Quotation-' . $q->quote_no . '.pdf', 'Your quotation ' . $q->quote_no);
            }

            return response()->json(['ok' => true, 'channel' => 'whatsapp']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'Could not share right now. Please try again.'], 500);
        }
    }

    /**
     * Approve the quotation → lock + convert to a Sales Order in one click.
     * Reuses the FROZEN QuotationConvertService (idempotent via order_id — no duplicate orders).
     */
    public function approve(Request $r, ?string $shop = null, string $token = '')
    {
        [$tenant, $base, $q] = $this->resolve($shop, $token);

        if (! $q->isOpen() && $q->status !== 'accepted') {
            // Already converted/declined/expired — return the existing order if any.
            return response()->json([
                'ok'       => $q->isConverted(),
                'status'   => (string) $q->status,
                'order_no' => $q->order ? (string) $q->order->order_no : null,
                'error'    => $q->isConverted() ? null : 'This quotation can no longer be approved.',
            ], $q->isConverted() ? 200 : 409);
        }

        try {
            $q->forceFill(['status' => 'accepted', 'accepted_at' => now()])->save();
            $out   = app(QuotationConvertService::class)->convert($tenant, $q->fresh());
            $order = $out['order'] ?? null;

            return response()->json([
                'ok'       => true,
                'status'   => 'converted',
                'order_no' => $order ? (string) $order->order_no : null,
                'already'  => (bool) ($out['already'] ?? false),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'Could not approve the quotation. Our team has been notified.'], 500);
        }
    }

    /** PDF bytes for sharing — stored file if present, else regenerate via the frozen renderer. */
    private function pdfBytes(Tenant $tenant, Quotation $q): string
    {
        $disk = Storage::disk('public');
        if ($q->pdf_path && $disk->exists($q->pdf_path)) {
            return (string) $disk->get($q->pdf_path);
        }
        $svc = app(QuotationService::class);
        if (! $svc->available()) {
            return '';
        }
        $lines = $q->items->map(fn ($it) => [
            'name' => (string) $it->name, 'qty' => (int) $it->qty, 'price' => (float) $it->unit_price,
            'sum' => (float) ($it->line_total ?: $it->qty * $it->unit_price), 'unit' => (string) ($it->unit_label ?? ''),
            'image' => (string) ($it->image_url ?? ''), 'matched' => true,
        ])->all();
        $doc = $svc->generate($tenant, (string) $q->customer_phone, (string) $q->customer_name, ['lines' => $lines, 'currency' => (string) $q->currency, 'total' => (float) $q->total], (string) $q->quote_no);

        return $doc && ! empty($doc['b64']) ? (string) base64_decode($doc['b64']) : '';
    }

    private function statusLabel(string $status): string
    {
        return [
            'sent'       => 'Awaiting your review',
            'accepted'   => 'Approved',
            'converted'  => 'Approved — Sales Order created',
            'declined'   => 'Declined',
            'expired'    => 'Expired',
            'draft'      => 'Draft',
        ][$status] ?? ucfirst($status);
    }

    private function imageUrl(?string $value): string
    {
        if (! $value) {
            return '';
        }
        return \Illuminate\Support\Str::startsWith($value, ['http://', 'https://', '/'])
            ? $value
            : Storage::disk('public')->url($value);
    }
}
