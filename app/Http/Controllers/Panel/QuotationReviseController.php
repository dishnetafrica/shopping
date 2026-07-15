<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Quotation;
use App\Services\Sales\QuotationReviseService;
use App\Services\WhatsApp\WhatsAppManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Sales-side quotation revisions (panel). Accepts the SAME payload the existing panel edit form
 * already posts (an `items` JSON line set + optional remarks), but routes it through the immutable
 * revision engine instead of overwriting — so every sales change becomes an auditable revision, and
 * the customer is notified. Reuses QuotationReviseService (which reprices via the frozen
 * QuotationService, keeping PDF + portal + WhatsApp in sync). Additive; the old quotation-update
 * endpoint is left intact.
 */
class QuotationReviseController extends Controller
{
    public function revise(Request $r)
    {
        $t = $r->user()?->tenant;
        if (! $t) {
            return response()->json(['ok' => false, 'error' => 'no_tenant'], 403);
        }
        app(\App\Support\TenantContext::class)->set($t->id);

        $q = Quotation::with('items')->where('tenant_id', $t->id)->find((int) $r->input('id'));
        if (! $q) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }
        if (! $q->isOpen()) {
            return response()->json(['ok' => false, 'error' => 'locked', 'message' => 'An accepted or converted quotation is read-only. Duplicate it to make changes.'], 422);
        }

        // Same shape as /papi/quotation-update: items may arrive as a JSON string or an array.
        $itemsRaw = $r->input('items', '[]');
        $items    = is_array($itemsRaw) ? $itemsRaw : (json_decode((string) $itemsRaw, true) ?: []);
        if (! is_array($items) || ! $items) {
            return response()->json(['ok' => false, 'error' => 'no_items', 'message' => 'Add at least one line.'], 422);
        }
        $remarks = $r->filled('remarks') ? (string) $r->input('remarks') : null;
        $comment = $r->filled('comment') ? (string) $r->input('comment') : null;

        $res = app(QuotationReviseService::class)->applySalesLines(
            $t, $q, $items, $remarks, $r->user()->id, (string) ($r->user()->name ?? 'Sales'), $comment
        );

        if (! ($res['ok'] ?? false)) {
            return response()->json(['ok' => false, 'error' => (string) ($res['error'] ?? 'Could not revise the quotation.')], 422);
        }

        // Notify the customer their quotation was updated by sales (best-effort WhatsApp with portal link).
        try {
            $phone = preg_replace('/[^0-9]/', '', (string) $q->customer_phone);
            if ($phone !== '' && $q->public_token) {
                $portal = url('/q/' . $q->public_token);
                $gw = app(WhatsAppManager::class)->forTenant($t);
                $gw->sendText($t->whatsapp_instance, $phone,
                    "Your quotation {$q->quote_no} has been updated (Revision {$res['revision']}).\nNew total: {$q->currency} " . number_format((float) $res['total']) . "\nReview & approve: {$portal}");
                if (method_exists($gw, 'sendDocument') && $q->pdf_path) {
                    $gw->sendDocument($t->whatsapp_instance, $phone, Storage::disk('public')->url($q->pdf_path), 'Quotation-' . $q->quote_no . '.pdf', 'Updated quotation ' . $q->quote_no);
                }
            }
        } catch (\Throwable $e) {
            // best-effort
        }

        $q->refresh();

        return response()->json([
            'ok'       => true,
            'id'       => $q->id,
            'quote_no' => (string) $q->quote_no,
            'revision' => (int) $res['revision'],
            'total'    => (float) $res['total'],
            'currency' => (string) $q->currency,
            'url'      => $q->pdf_path ? Storage::disk('public')->url($q->pdf_path) : '',
        ]);
    }
}
