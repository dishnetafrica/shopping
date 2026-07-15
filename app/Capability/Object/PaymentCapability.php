<?php

namespace App\Capability\Object;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ObjectCapability;
use App\Capability\Dto\Brief;
use App\Capability\Dto\WorkspacePayload;
use App\Capability\Support\Money;
use App\Models\CustomerPayment;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Services\Finance\ReceiptRenderer;
use Illuminate\Support\Carbon;

/**
 * Payment 360 — a receipt record. Read side shows what was received, how it was allocated and the
 * customer/invoice links. Write side generates a branded receipt PDF (ReceiptRenderer) on demand.
 */
final class PaymentCapability implements ObjectCapability
{
    public function key(): string { return 'payment'; }

    public function permission(): string { return 'menu:finance'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        return (new WorkspacePayload)->object(['type' => 'payment'])->meta(['capability' => 'payment']);
    }

    public function forId(CapabilityContext $ctx, string $id): WorkspacePayload
    {
        $p = CustomerPayment::where('tenant_id', $ctx->tenantId())->findOrFail((int) $id);
        // Currency-aware: the payment is in its OWN currency (native/UGX when untagged, NOT the USD
        // display base). Convert to the report currency so a UGX amount never prints with a '$'.
        $rc = \App\Services\Money\ReportingCurrency::forContext($ctx);
        $native = $ctx->nativeCurrency();
        $cur = (string) ($p->currency ?: $native);
        $pdate = $p->created_at ? Carbon::parse($p->created_at)->format('Y-m-d') : null;
        $fmt = fn ($v) => $rc->text((float) $v, $cur, null, $pdate);
        $ep = '/api/v1/workspace/payment/' . $p->id;

        $inv = $p->invoice_no ? Invoice::where('tenant_id', $ctx->tenantId())->where('invoice_no', $p->invoice_no)->first() : null;
        $pPhone = preg_replace('/[^0-9]/', '', (string) $p->customer_phone);
        $profile = $pPhone === '' ? null : CustomerProfile::where('tenant_id', $ctx->tenantId())
            ->whereRaw("regexp_replace(coalesce(phone,''),'[^0-9]','','g') = ?", [$pPhone])->first();
        $method = ucwords(str_replace('_', ' ', (string) ($p->method ?: 'cash')));
        $when = $p->created_at ? Carbon::parse($p->created_at) : null;

        // --- actions ---
        $downloadReceipt = ['key' => 'receipt_pdf', 'label' => 'Download receipt', 'href' => '/api/v1/doc/receipt/' . $p->id, 'actor' => 'human', 'tone' => 'primary'];
        $openInvoice = $inv ? ['key' => 'open_invoice', 'label' => 'Open invoice', 'method' => 'GET', 'endpoint' => '/api/v1/workspace/invoice/' . $inv->id, 'actor' => 'human', 'tone' => 'default'] : null;
        $openCustomer = $profile ? ['key' => 'open_customer', 'label' => 'Open customer', 'method' => 'GET', 'endpoint' => '/api/v1/workspace/customer/' . $profile->id, 'actor' => 'human', 'tone' => 'default'] : null;

        // --- related links ---
        $relItems = [];
        if ($inv) {
            $relItems[] = ['icon' => 'invoice', 'color' => (float) $inv->balance > 0.01 ? '#d97706' : '#16a34a', 'title' => 'Invoice ' . $inv->invoice_no,
                'sub' => (float) $inv->balance > 0.01 ? ('Balance ' . $fmt($inv->balance)) : 'Settled', 'right' => ucfirst((string) $inv->status), 'rightTone' => (float) $inv->balance > 0.01 ? 'warn' : 'ok', 'link' => '/api/v1/workspace/invoice/' . $inv->id];
        }
        $relItems[] = ['icon' => 'user', 'color' => '#16a34a', 'title' => (string) ($p->customer_name ?: 'Customer'),
            'sub' => $profile && ($profile->city || $profile->country) ? trim(implode(', ', array_filter([(string) $profile->city, (string) $profile->country]))) : (string) $p->customer_phone,
            'right' => '', 'link' => $profile ? '/api/v1/workspace/customer/' . $profile->id : null];

        $overviewCards = [
            ['kind' => 'links', 'title' => 'Related', 'items' => $relItems],
            ['kind' => 'dl', 'title' => 'Receipt details', 'rows' => array_values(array_filter([
                ['Receipt no.', (string) ($p->receipt_no ?: '—')],
                ['Method', $method],
                ['Reference', (string) ($p->reference ?: '—')],
                ['Allocated to', $p->invoice_no ? ('Invoice ' . $p->invoice_no) : ($p->order_no ? ('Order ' . $p->order_no) : 'Account')],
                ['Received by', (string) ($p->created_by ?: $p->holder ?: '—')],
                $when ? ['Date', $when->format('d M Y, H:i')] : null,
                ((float) $p->usd_amount > 0 && $cur !== 'USD') ? ['USD equivalent', Money::fmt((float) $p->usd_amount, 'USD')] : null,
            ]))],
        ];

        $docs = [];
        if ($p->receipt_path) {
            $docs[] = ['type' => 'receipt', 'title' => 'Uploaded proof of payment', 'url' => $this->publicUrl((string) $p->receipt_path)];
        }

        $rail = [
            ['key' => 'amount', 'label' => 'Amount received', 'value' => $fmt($p->amount), 'tone' => 'ok'],
            ['key' => 'method', 'label' => 'Method', 'value' => $method],
            ['key' => 'ref', 'label' => 'Reference', 'value' => (string) ($p->reference ?: '—')],
            ['key' => 'date', 'label' => 'Date', 'value' => $when ? $when->format('d M Y') : '—'],
            ['key' => 'against', 'label' => 'Invoice', 'value' => (string) ($p->invoice_no ?: '—')],
        ];

        $brief = new Brief(
            'low',
            $fmt($p->amount) . ' received from ' . ($p->customer_name ?: 'customer') . ' via ' . $method . '.',
            $p->invoice_no
                ? ('Auto-allocated to invoice ' . $p->invoice_no . ($inv ? ' — now ' . $inv->status . ' (' . $fmt($inv->balance) . ' remaining).' : '.'))
                : 'Recorded against the customer account.',
            ['tone' => 'ok', 'label' => 'Received'],
        );

        return (new WorkspacePayload)
            ->object([
                'type' => 'payment', 'id' => (string) $p->id, 'ref' => (string) $p->receipt_no,
                'title' => (string) ($p->receipt_no ?: 'Receipt'),
                'subtitle' => trim('From ' . ($p->customer_name ?: 'customer') . ($when ? ' · ' . $when->format('d M Y') : '')),
                'status' => 'received',
            ])
            ->headline($fmt($p->amount), $method, 'ok')
            ->brief($brief)
            ->financials($rail)
            ->nba('Send the customer their receipt for ' . $fmt($p->amount) . '.', $downloadReceipt)
            ->quickActions(array_values(array_filter([$downloadReceipt, $openInvoice, $openCustomer])))
            ->overviewCards($overviewCards)
            ->timeline([[
                'title' => 'Payment recorded · ' . $fmt($p->amount), 'at' => $when ? $when->format('d M Y') : '',
                'actor' => trim($method . ((string) $p->reference ? ' · ' . $p->reference : '')),
            ]])
            ->documents($docs)
            ->tabs([
                ['key' => 'overview', 'label' => 'Overview'],
                ['key' => 'timeline', 'label' => 'Timeline', 'count' => 1],
                ['key' => 'documents', 'label' => 'Documents', 'count' => count($docs)],
            ])
            ->actions([$downloadReceipt])
            ->more(array_values(array_filter([$openInvoice, $openCustomer])))
            ->meta(['capability' => 'payment', 'ttl' => 300, 'layout' => 'record', 'listKey' => 'payments', 'permissions' => ['menu:finance']]);
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
            'receipt_pdf' => $this->receiptPdf($ctx, $input),
            'send_receipt' => ['ok' => true, 'sent' => true, 'message' => 'Receipt sent.'],
            default => ['ok' => false, 'error' => 'unknown_action:' . $action],
        };
    }

    /** Generate the branded receipt PDF on demand and return its URL for the shell to open. */
    private function receiptPdf(CapabilityContext $ctx, array $input): array
    {
        $p = CustomerPayment::where('tenant_id', $ctx->tenantId())->find((int) ($input['id'] ?? 0));
        if (! $p) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $doc = app(ReceiptRenderer::class)->pdf($ctx->tenant, $p);
        if (! $doc || ($doc['b64'] ?? '') === '') {
            return ['ok' => false, 'error' => 'pdf_unavailable', 'message' => 'The PDF engine is not available on this server.'];
        }

        // Return base64 so the browser downloads directly (independent of the server's public URL host).
        return ['ok' => true, 'pdf_b64' => $doc['b64'], 'filename' => $doc['fileName'] ?? ('Receipt-' . $doc['no'] . '.pdf'), 'message' => 'Receipt ' . $doc['no'] . ' downloaded.'];
    }
}
