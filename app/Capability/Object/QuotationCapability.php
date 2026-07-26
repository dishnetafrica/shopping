<?php

namespace App\Capability\Object;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ObjectCapability;
use App\Capability\Dto\Brief;
use App\Capability\Dto\WorkspacePayload;
use App\Capability\Support\Money;
use App\Models\CustomerProfile;
use App\Models\Quotation;
use App\Services\Bot\QuotationService;
use App\Services\Sales\QuotationConvertService;
use Illuminate\Support\Carbon;

/**
 * Quotation 360 — the full quote: line items, totals, customer/order links, a branded-PDF export and
 * conversion to an order (frozen QuotationConvertService). PDF is served from the stored copy or
 * re-rendered from the saved line items via the frozen QuotationService.
 */
final class QuotationCapability implements ObjectCapability
{
    private const OPEN = ['sent', 'accepted', 'draft', 'pending'];

    public function key(): string { return 'quotation'; }

    public function permission(): string { return 'menu:orders'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        return (new WorkspacePayload)->object(['type' => 'quotation'])->meta(['capability' => 'quotation']);
    }

    public function forId(CapabilityContext $ctx, string $id): WorkspacePayload
    {
        $q = Quotation::where('tenant_id', $ctx->tenantId())->with('items')->find((int) $id);
        if (! $q) {
            return (new WorkspacePayload)->object(['type' => 'quotation', 'title' => 'Quotation not found'])->meta(['capability' => 'quotation']);
        }
        // Currency-aware: the quote is in its OWN currency (native/UGX when untagged, NOT the USD
        // display base). Convert to the report currency so a UGX total never prints with a '$'.
        $rc = \App\Services\Money\ReportingCurrency::forContext($ctx);
        $native = $ctx->nativeCurrency();
        $cur = (string) ($q->currency ?: $native);
        $qdate = $q->created_at ? Carbon::parse($q->created_at)->format('Y-m-d') : null;
        $fmt = fn ($v) => $rc->text((float) $v, $cur, null, $qdate);
        $status = strtolower((string) $q->status);
        $valid = $q->valid_until ? Carbon::parse($q->valid_until) : null;
        $created = $q->created_at ? Carbon::parse($q->created_at) : null;
        $ep = '/api/v1/workspace/quotation/' . $q->id;
        $converted = (bool) $q->order_id;
        $canConvert = ! $converted && in_array($status, self::OPEN, true);

        $qPhone = preg_replace('/[^0-9]/', '', (string) $q->customer_phone);
        $profile = $qPhone === '' ? null : CustomerProfile::where('tenant_id', $ctx->tenantId())
            ->whereRaw("regexp_replace(coalesce(phone,''),'[^0-9]','','g') = ?", [$qPhone])->first();

        // --- line items ---
        $lineRows = $q->items->map(function ($it) use ($fmt) {
            $qty = (float) $it->qty;
            return ['cells' => [(string) $it->name . ((string) $it->unit_label !== '' ? ' (' . $it->unit_label . ')' : ''),
                rtrim(rtrim(number_format($qty, 2), '0'), '.'), $fmt($it->unit_price), $fmt($it->line_total)], 'link' => null];
        })->all();

        // --- actions ---
        // Download via the same proven streaming route the legacy panel uses (Content-Disposition, same
        // session) — avoids any public-storage-URL/domain problem. The v3 shell opens the href directly.
        $downloadPdf = ['key' => 'quotation_pdf', 'label' => 'Download PDF', 'href' => '/papi/quotation-pdf?id=' . $q->id, 'actor' => 'human', 'tone' => $converted ? 'default' : 'primary'];
        $convert = ['key' => 'convert_to_order', 'label' => 'Convert to order', 'method' => 'POST', 'endpoint' => $ep . '/convert_to_order', 'actor' => 'human', 'tone' => 'primary', 'confirm' => 'Convert quotation ' . $q->quote_no . ' into an order?'];
        $openCustomer = $profile ? ['key' => 'open_customer', 'label' => 'Open customer', 'method' => 'GET', 'endpoint' => '/api/v1/workspace/customer/' . $profile->id, 'actor' => 'human', 'tone' => 'default'] : null;
        $openOrder = $converted ? ['key' => 'open_order', 'label' => 'Open order', 'method' => 'GET', 'endpoint' => '/api/v1/workspace/salesOrder/' . $q->order_id, 'actor' => 'human', 'tone' => 'default'] : null;

        $primary = $canConvert ? $convert : ($openOrder ?: $downloadPdf);

        // --- pipeline ---
        $st = fn ($done, $now) => $done ? 'done' : ($now ? 'now' : 'wait');
        $sent = in_array($status, ['sent', 'accepted', 'converted'], true);
        $pipeline = [
            ['label' => 'Drafted', 'value' => $q->quote_no, 'state' => 'done', 'icon' => 'edit'],
            ['label' => 'Sent', 'value' => $sent ? 'Sent' : 'Not sent', 'state' => $st($sent, ! $sent), 'icon' => 'send'],
            ['label' => 'Converted', 'value' => $converted ? 'Order raised' : ($status === 'declined' ? 'Declined' : 'Pending'), 'state' => $st($converted, $sent && ! $converted && $status !== 'declined'), 'icon' => 'cart'],
        ];

        // --- overview cards ---
        $relItems = [];
        $relItems[] = ['icon' => 'user', 'color' => '#16a34a', 'title' => (string) ($q->customer_name ?: 'Customer'),
            'sub' => $profile && ($profile->city || $profile->country) ? trim(implode(', ', array_filter([(string) $profile->city, (string) $profile->country]))) : (string) $q->customer_phone,
            'right' => '', 'link' => $profile ? '/api/v1/workspace/customer/' . $profile->id : null];
        if ($converted) {
            $relItems[] = ['icon' => 'cart', 'color' => '#4f46e5', 'title' => 'Converted order', 'sub' => 'View the sales order', 'right' => '', 'link' => '/api/v1/workspace/salesOrder/' . $q->order_id];
        }

        // Asset Lifecycle · S8b — a project quote groups its lines into priced sections (stored in meta).
        // Show one card per section (with a subtotal) instead of the flat line-items card; ordinary quotes
        // are unchanged.
        $project = is_array($q->meta) ? ($q->meta['project'] ?? null) : null;
        $sectionCards = [];
        if (is_array($project) && ! empty($project['sections'])) {
            foreach ($project['sections'] as $sec) {
                $secRows = [];
                foreach (($sec['lines'] ?? []) as $ln) {
                    $sqty = (float) ($ln['qty'] ?? 0);
                    $secRows[] = ['cells' => [
                        (string) ($ln['name'] ?? ''),
                        rtrim(rtrim(number_format($sqty, 2), '0'), '.'),
                        $fmt($ln['unit_price'] ?? 0),
                        $fmt($ln['line_total'] ?? 0),
                    ], 'link' => null];
                }
                $sectionCards[] = ['kind' => 'table', 'title' => (string) ($sec['title'] ?? 'Section'),
                    'sub' => 'Subtotal ' . $fmt($sec['subtotal'] ?? 0),
                    'columns' => [['label' => 'Item'], ['label' => 'Qty', 'align' => 'right'], ['label' => 'Unit price', 'align' => 'right'], ['label' => 'Amount', 'align' => 'right']],
                    'rows' => $secRows, 'empty' => '—'];
            }
        }
        $itemCards = $sectionCards !== [] ? $sectionCards : [
            ['kind' => 'table', 'title' => 'Line items', 'sub' => $q->items->count() . ' products',
             'columns' => [['label' => 'Description'], ['label' => 'Qty', 'align' => 'right'], ['label' => 'Unit price', 'align' => 'right'], ['label' => 'Amount', 'align' => 'right']],
             'rows' => $lineRows, 'empty' => 'No priced line items on this quotation'],
        ];

        $overviewCards = array_merge([
            ['kind' => 'links', 'title' => 'Related', 'items' => $relItems],
        ], $itemCards, [
            ['kind' => 'dl', 'title' => 'Summary', 'rows' => array_values(array_filter([
                ['Total', $fmt($q->total)],
                ['Currency', $cur],
                $valid ? ['Valid until', $valid->format('d M Y')] : null,
                $created ? ['Created', $created->format('d M Y')] : null,
                ['Status', ucfirst($status ?: 'draft')],
            ]))],
        ]);

        $docs = [];
        if ($q->pdf_path) {
            $docs[] = ['type' => 'quotation', 'title' => 'Quotation ' . $q->quote_no . ' (PDF)', 'url' => $this->publicUrl((string) $q->pdf_path)];
        }

        $rail = [
            ['key' => 'total', 'label' => 'Quotation total', 'value' => $fmt($q->total)],
            ['key' => 'items', 'label' => 'Line items', 'value' => number_format($q->items->count())],
            ['key' => 'valid', 'label' => 'Valid until', 'value' => $valid ? $valid->format('d M Y') : '—', 'tone' => ($valid && $valid->isPast() && ! $converted) ? 'warn' : ''],
            ['key' => 'created', 'label' => 'Created', 'value' => $created ? $created->format('d M Y') : '—'],
            ['key' => 'status', 'label' => 'Status', 'value' => ucfirst($status ?: 'draft')],
        ];

        // --- CRUD actions (reuse the panel's proven /papi endpoints; no engine changes) ---
        $isOpen = ! $converted && in_array($status, ['sent', 'pending', 'draft'], true);
        $canEdit = ! $converted && $status !== 'accepted';

        $editLines = $q->items->map(fn ($it) => [
            'name' => (string) $it->name, 'unit' => (string) ($it->unit_label ?? ''),
            'qty' => (int) $it->qty, 'price' => (float) $it->unit_price,
        ])->values()->all();
        $editQuote = ($canEdit && $editLines) ? [
            'key' => 'edit_quotation', 'label' => 'Revise quotation', 'method' => 'POST', 'endpoint' => '/papi/quotation-revise',
            'actor' => 'human', 'tone' => 'default', 'builder' => 'quotation', 'edit' => true,
            'productSearch' => '/api/v1/workspace/products/_/search',
            'currencies' => array_values(array_unique([$cur, 'USD', 'UGX', 'SSP'])),
            'customer' => (string) ($q->customer_name ?: 'Customer'), 'lines' => $editLines,
            'prefill' => ['id' => (int) $q->id, 'currency' => $cur, 'name' => (string) $q->customer_name, 'phone' => (string) $q->customer_phone],
        ] : null;

        $sBody = fn (string $s) => ['id' => (int) $q->id, 'status' => $s];
        $accept  = $isOpen ? ['key' => 'quote_accept', 'label' => 'Mark accepted', 'method' => 'POST', 'endpoint' => '/papi/quotation-status', 'actor' => 'human', 'tone' => 'default', 'body' => $sBody('accepted')] : null;
        $decline = $isOpen ? ['key' => 'quote_decline', 'label' => 'Mark declined', 'method' => 'POST', 'endpoint' => '/papi/quotation-status', 'actor' => 'human', 'tone' => 'default', 'body' => $sBody('declined'), 'confirm' => 'Mark quotation ' . $q->quote_no . ' as declined?'] : null;
        $expire  = $isOpen ? ['key' => 'quote_expire', 'label' => 'Mark expired', 'method' => 'POST', 'endpoint' => '/papi/quotation-status', 'actor' => 'human', 'tone' => 'default', 'body' => $sBody('expired')] : null;
        $resend  = ($qPhone !== '' && ! $converted) ? ['key' => 'quote_resend', 'label' => 'Resend to customer', 'method' => 'POST', 'endpoint' => '/papi/quotation-resend', 'actor' => 'human', 'tone' => 'default', 'body' => ['id' => (int) $q->id], 'confirm' => 'Resend quotation ' . $q->quote_no . ' to ' . ($q->customer_name ?: 'the customer') . ' on WhatsApp?'] : null;
        $archive = ! $converted ? ['key' => 'quote_archive', 'label' => 'Archive quotation', 'method' => 'POST', 'endpoint' => '/papi/quotation-delete', 'actor' => 'human', 'tone' => 'danger', 'body' => ['id' => (int) $q->id], 'confirm' => 'Archive quotation ' . $q->quote_no . '? It is hidden from the active list but kept for records.'] : null;

        $headerActions = array_values(array_filter([$primary, $editQuote]));
        $moreActions = array_values(array_filter([
            $downloadPdf === $primary ? null : $downloadPdf,
            $accept, $decline, $resend, $expire, $openCustomer, $archive,
        ]));
        $quickActions = array_values(array_filter([$downloadPdf, $canConvert ? $convert : null, $editQuote, $resend, $openOrder, $openCustomer]));

        return (new WorkspacePayload)
            ->object([
                'type' => 'quotation', 'id' => (string) $q->id, 'ref' => (string) $q->quote_no,
                'title' => 'Quote ' . $q->quote_no,
                'subtitle' => trim('For ' . ($q->customer_name ?: $q->customer_phone ?: 'customer') . ($created ? ' · ' . $created->format('d M Y') : '') . ((int) $q->current_rev > 1 ? ' · Revision ' . (int) $q->current_rev : '')),
                'status' => $converted ? 'converted' : $status,
            ])
            ->headline($fmt($q->total), $converted ? 'converted to order' : 'quotation total', $converted ? 'ok' : 'default')
            ->brief($this->quotationBrief($q, $status, $converted, $valid, $fmt))
            ->financials($rail)
            ->nba(
                $canConvert ? 'Quotation sent to ' . ($q->customer_name ?: 'the customer') . '. Convert it to an order once they accept, or download the PDF to share.'
                    : ($converted ? 'This quotation became a sales order. Open the order to continue.' : 'Download the PDF to share this quotation with the customer.'),
                $primary,
            )
            ->quickActions($quickActions)
            ->pipeline($pipeline)
            ->overviewCards($overviewCards)
            ->documents($docs)
            ->tabs([
                ['key' => 'overview', 'label' => 'Overview'],
                ['key' => 'documents', 'label' => 'Documents', 'count' => count($docs)],
            ])
            ->actions($headerActions)
            ->more($moreActions)
            ->meta(['capability' => 'quotation', 'ttl' => 30, 'layout' => 'record', 'listKey' => 'quotations', 'permissions' => ['menu:orders']]);
    }

    private function quotationBrief(Quotation $q, string $status, bool $converted, ?Carbon $valid, callable $fmt): Brief
    {
        if ($converted) {
            return new Brief('low', 'Quotation converted to an order.', 'This quote has been accepted and turned into a sales order.', ['tone' => 'ok', 'label' => 'Converted']);
        }
        if ($status === 'declined') {
            return new Brief('low', 'Quotation declined.', 'The customer did not proceed with this quote.', ['tone' => 'danger', 'label' => 'Declined']);
        }
        if ($valid && $valid->isPast()) {
            return new Brief('med', 'Quotation expired.', 'Validity lapsed on ' . $valid->format('d M Y') . '. Re-issue with fresh pricing if the customer is still interested.', ['tone' => 'warn', 'label' => 'Expired']);
        }

        return new Brief('low', $fmt($q->total) . ' quotation to ' . ($q->customer_name ?: 'the customer') . '.', 'Awaiting the customer\'s reply. Convert it to an order when they accept.', ['tone' => 'info', 'label' => 'Awaiting reply']);
    }

    private function publicUrl(string $path): string
    {
        if (str_starts_with($path, 'http')) {
            return $path;
        }
        try { return \Illuminate\Support\Facades\Storage::disk('public')->url($path); } catch (\Throwable $e) { return $path; }
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return match ($action) {
            'convert_to_order' => $this->convert($ctx, $input),
            'quotation_pdf' => $this->pdf($ctx, $input),
            default => ['ok' => false, 'error' => 'unknown_action:' . $action],
        };
    }

    private function convert(CapabilityContext $ctx, array $input): array
    {
        $q = Quotation::where('tenant_id', $ctx->tenantId())->with('items')->find((int) ($input['id'] ?? 0));
        if (! $q) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        try {
            $res = app(QuotationConvertService::class)->convert($ctx->tenant, $q);
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'message' => 'This quotation has no priced items to convert.'];
        }
        $o = $res['order'];

        return ['ok' => true, 'order_id' => $o ? (int) $o->id : null, 'message' => ($res['already'] ? 'Already converted — order ' : 'Converted to order ') . ($o ? (string) $o->order_no : '') . '.'];
    }

    /**
     * Return the quotation PDF as base64 so the browser downloads it directly — this avoids depending on
     * the server's public storage URL (which may point at an internal host). Renders fresh from the saved
     * line items; falls back to streaming the stored PDF file if no items remain.
     */
    private function pdf(CapabilityContext $ctx, array $input): array
    {
        $q = Quotation::where('tenant_id', $ctx->tenantId())->with('items')->find((int) ($input['id'] ?? 0));
        if (! $q) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $file = 'Quotation-' . $q->quote_no . '.pdf';

        $lines = $q->items->map(fn ($it) => [
            'name' => (string) $it->name, 'qty' => (int) $it->qty, 'price' => (float) $it->unit_price,
            'sum' => (float) $it->line_total, 'unit' => (string) ($it->unit_label ?? ''), 'image' => (string) ($it->image_url ?? ''), 'matched' => true,
        ])->values()->all();

        if ($lines) {
            $doc = app(QuotationService::class)->generate($ctx->tenant, (string) $q->customer_phone, (string) $q->customer_name,
                ['lines' => $lines, 'currency' => (string) ($q->currency ?: $ctx->nativeCurrency()), 'total' => (float) $q->total], (string) $q->quote_no);
            if ($doc && ($doc['b64'] ?? '') !== '') {
                if (! $q->pdf_path && ($doc['path'] ?? '') !== '') { $q->pdf_path = $doc['path']; $q->save(); }

                return ['ok' => true, 'pdf_b64' => $doc['b64'], 'filename' => $doc['fileName'] ?? $file, 'message' => 'Quotation ' . $q->quote_no . ' downloaded.'];
            }
        }

        // Fallback: stream the previously-saved PDF file bytes.
        if ($q->pdf_path) {
            try {
                $bytes = \Illuminate\Support\Facades\Storage::disk('public')->get((string) $q->pdf_path);
                if ($bytes) {
                    return ['ok' => true, 'pdf_b64' => base64_encode($bytes), 'filename' => $file, 'message' => 'Quotation ' . $q->quote_no . ' downloaded.'];
                }
            } catch (\Throwable $e) { /* fall through */ }
        }

        return ['ok' => false, 'error' => 'pdf_unavailable', 'message' => $lines ? 'The PDF engine is not available on this server.' : 'This quotation has no line items to render.'];
    }
}
