<?php

namespace App\Services\Sales;

use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\QuotationRevision;
use App\Models\Tenant;
use App\Services\Bot\QuotationService;
use Illuminate\Support\Facades\DB;

/**
 * The quotation revision engine — "never overwrite history".
 *
 * Applying a customer or sales change appends a NEW immutable revision to the ledger, then
 * regenerates the PDF via the FROZEN QuotationService (so portal, PDF and WhatsApp stay in sync)
 * and refreshes the current-revision projection (the quotations row + quotation_items). It does
 * NOT re-run the pricing engine on existing lines — a quotation is a frozen offer, so agreed unit
 * prices are preserved; only newly-added lines pull a catalogue price. All additive; no existing
 * service is modified.
 */
class QuotationReviseService
{
    /** Customer edit → new revision. Allowed ops: qty, remove, add, request (comment/price-review). */
    public function applyCustomerChange(Tenant $t, Quotation $q, array $ops, ?string $comment = null): array
    {
        return $this->apply($t, $q, $ops, 'customer', null, null, $comment, 'customer_revised');
    }

    /** Sales edit → new revision. Additionally allows: price, discount, remarks. */
    public function applySalesChange(Tenant $t, Quotation $q, array $ops, ?int $actorId, ?string $actorName, ?string $comment = null): array
    {
        return $this->apply($t, $q, $ops, 'sales', $actorId, $actorName, $comment, 'revised');
    }

    private function apply(Tenant $t, Quotation $q, array $ops, string $actorType, ?int $actorId, ?string $actorName, ?string $comment, string $newStatus): array
    {
        if (! $q->isOpen()) {
            return ['ok' => false, 'error' => 'This quotation is locked and can no longer be edited.'];
        }

        // Lazily materialise Revision 1 (the original) so the ledger is complete for any source.
        $this->ensureBaseline($t, $q);

        $prev  = $this->currentLines($q);
        $built = $this->applyOps($t, $prev, $ops, $actorType);
        $lines = $built['lines'];
        $meta  = $built['meta'];   // requests / remarks collected from non-line ops

        if (! $lines && ($actorType === 'customer')) {
            return ['ok' => false, 'error' => 'A quotation must keep at least one product line.'];
        }

        // Regenerate the PDF + authoritative total through the frozen engine (keeps PDF == portal).
        [$doc, $subtotal] = $this->render($t, $q, $lines);
        $grand = (float) ($doc['total'] ?? $subtotal);

        $summary = $this->diff($prev, $lines);
        if ($comment !== null && trim($comment) !== '') {
            $summary[] = ['type' => 'comment', 'by' => $actorType, 'text' => mb_substr(trim($comment), 0, 500)];
        }
        foreach ($meta['requests'] as $req) {
            $summary[] = ['type' => 'request', 'by' => 'customer', 'text' => $req];
        }
        if (isset($meta['remarks'])) {
            $summary[] = ['type' => 'remarks', 'text' => $meta['remarks']];
        }

        return DB::transaction(function () use ($t, $q, $lines, $subtotal, $grand, $summary, $doc, $actorType, $actorId, $actorName, $meta, $newStatus) {
            $revNo = (int) ($q->revisions()->max('rev_no') ?? 0) + 1;

            $rev = QuotationRevision::create([
                'tenant_id'      => $t->id,
                'quotation_id'   => $q->id,
                'rev_no'         => $revNo,
                'actor_type'     => $actorType,
                'actor_id'       => $actorId,
                'actor_name'     => $actorName,
                'status_at'      => $newStatus,
                'currency'       => (string) $q->currency,
                'subtotal'       => $subtotal,
                'discount_total' => max(0, $subtotal - $grand) > 0 && $grand < $subtotal ? ($subtotal - $grand) : 0,
                'tax_total'      => 0,
                'freight'        => 0,
                'grand_total'    => $grand,
                'snapshot'       => ['lines' => $lines, 'customer' => [
                    'name'    => (string) $q->customer_name,
                    'phone'   => (string) $q->customer_phone,
                    'address' => (string) $q->delivery_address,
                ]],
                'change_summary' => array_values($summary),
                'pdf_path'       => (string) ($doc['path'] ?? $q->pdf_path),
            ]);

            // Refresh the current-revision projection (fast path for portal + capability + list).
            $q->total       = $grand;
            $q->current_rev = $revNo;
            $q->status      = $newStatus;
            if (! empty($doc['path'])) {
                $q->pdf_path = (string) $doc['path'];
            }
            if (isset($meta['remarks'])) {
                $q->remarks = $meta['remarks'];
            }
            $q->save();

            $this->syncItems($t, $q, $lines);

            return [
                'ok'          => true,
                'revision'    => $revNo,
                'total'       => $grand,
                'currency'    => (string) $q->currency,
                'change_count' => count($summary),
            ];
        });
    }

    /** Create Revision 1 from the current items if the ledger is empty (idempotent). */
    private function ensureBaseline(Tenant $t, Quotation $q): void
    {
        if ($q->revisions()->exists()) {
            return;
        }
        $lines    = $this->currentLines($q);
        $subtotal = array_sum(array_map(fn ($l) => $l['qty'] * $l['price'], $lines));

        QuotationRevision::create([
            'tenant_id'    => $t->id,
            'quotation_id' => $q->id,
            'rev_no'       => 1,
            'actor_type'   => 'system',
            'actor_name'   => 'Original quotation',
            'status_at'    => (string) $q->status,
            'currency'     => (string) $q->currency,
            'subtotal'     => $subtotal,
            'grand_total'  => (float) $q->total,
            'snapshot'     => ['lines' => $lines, 'customer' => [
                'name'    => (string) $q->customer_name,
                'phone'   => (string) $q->customer_phone,
                'address' => (string) $q->delivery_address,
            ]],
            'change_summary' => [],
            'pdf_path'       => (string) $q->pdf_path,
        ]);
        if ((int) $q->current_rev < 1) {
            $q->forceFill(['current_rev' => 1])->save();
        }
    }

    /** Current line set — from the latest revision snapshot if present, else the live items. */
    private function currentLines(Quotation $q): array
    {
        $latest = $q->revisions()->orderByDesc('rev_no')->first();
        if ($latest && is_array($latest->snapshot['lines'] ?? null)) {
            return array_values($latest->snapshot['lines']);
        }
        return $q->items->map(fn (QuotationItem $it) => [
            'name'    => (string) $it->name,
            'brand'   => (string) ($it->brand ?? ''),
            'variant' => (string) ($it->variant ?? ''),
            'unit'    => (string) ($it->unit_label ?? ''),
            'qty'     => (int) $it->qty,
            'price'   => (float) $it->unit_price,
            'image'   => (string) ($it->image_url ?? ''),
            'disc'    => 0.0,
        ])->all();
    }

    /** Apply the change ops to the line set. Customers may only qty/remove/add/request. */
    private function applyOps(Tenant $t, array $lines, array $ops, string $actorType): array
    {
        // Index by lowercased name for stable matching.
        $byName = [];
        foreach ($lines as $l) {
            $byName[mb_strtolower($l['name'])] = $l;
        }
        $meta = ['requests' => []];

        foreach ((array) $ops as $op) {
            $type = (string) ($op['op'] ?? '');
            $name = trim((string) ($op['name'] ?? ''));
            $key  = mb_strtolower($name);

            switch ($type) {
                case 'qty':
                    if (isset($byName[$key])) {
                        $qty = (int) ($op['qty'] ?? 0);
                        if ($qty <= 0) {
                            unset($byName[$key]);
                        } else {
                            $byName[$key]['qty'] = $qty;
                        }
                    }
                    break;

                case 'remove':
                    unset($byName[$key]);
                    break;

                case 'add':
                    $qty = max(1, (int) ($op['qty'] ?? 1));
                    if (isset($byName[$key])) {
                        $byName[$key]['qty'] += $qty;
                        break;
                    }
                    $p = Product::where('tenant_id', $t->id)->where('name', $name)->first();
                    if ($p) {
                        $byName[$key] = [
                            'name'    => (string) $p->name,
                            'brand'   => '',
                            'variant' => '',
                            'unit'    => (string) ($p->unit_label ?? ''),
                            'qty'     => $qty,
                            'price'   => (float) ($p->base_price ?: $p->price),
                            'image'   => (string) ($p->image_url ?? ''),
                            'disc'    => 0.0,
                        ];
                    }
                    break;

                case 'request':
                    $txt = mb_substr(trim((string) ($op['text'] ?? '')), 0, 500);
                    if ($txt !== '') {
                        $meta['requests'][] = $txt;
                    }
                    break;

                // ---- sales-only ops ----
                case 'price':
                    if ($actorType === 'sales' && isset($byName[$key])) {
                        $byName[$key]['price'] = max(0, (float) ($op['price'] ?? $byName[$key]['price']));
                    }
                    break;

                case 'discount':
                    if ($actorType === 'sales' && isset($byName[$key])) {
                        $byName[$key]['disc'] = max(0.0, min(100.0, (float) ($op['disc'] ?? 0)));
                    }
                    break;

                case 'remarks':
                    if ($actorType === 'sales') {
                        $meta['remarks'] = mb_substr(trim((string) ($op['text'] ?? '')), 0, 800);
                    }
                    break;
            }
        }

        return ['lines' => array_values($byName), 'meta' => $meta];
    }

    /** Render the PDF + total for a line set through the frozen QuotationService. Returns [doc, subtotal]. */
    private function render(Tenant $t, Quotation $q, array $lines): array
    {
        $subtotal = 0.0;
        $quoteLines = [];
        foreach ($lines as $l) {
            $sum = (float) $l['qty'] * (float) $l['price'];
            $subtotal += $sum;
            $quoteLines[] = [
                'name'    => (string) $l['name'],
                'qty'     => (int) $l['qty'],
                'price'   => (float) $l['price'],
                'sum'     => $sum,
                'unit'    => (string) ($l['unit'] ?? ''),
                'image'   => (string) ($l['image'] ?? ''),
                'matched' => true,
                'disc'    => (float) ($l['disc'] ?? 0),
            ];
        }
        $quote = ['lines' => $quoteLines, 'currency' => (string) $q->currency, 'total' => $subtotal];

        $svc = app(QuotationService::class);
        $doc = $svc->available()
            ? $svc->generate($t, (string) $q->customer_phone, (string) $q->customer_name, $quote, (string) $q->quote_no)
            : null;

        if (! $doc) {
            $doc = ['total' => $subtotal, 'path' => (string) $q->pdf_path];
        }

        return [$doc, $subtotal];
    }

    /** Diff two line sets into a human-readable change summary for the "what changed" view. */
    private function diff(array $prev, array $next): array
    {
        $p = [];
        foreach ($prev as $l) {
            $p[mb_strtolower($l['name'])] = $l;
        }
        $n = [];
        foreach ($next as $l) {
            $n[mb_strtolower($l['name'])] = $l;
        }
        $out = [];

        foreach ($n as $k => $l) {
            if (! isset($p[$k])) {
                $out[] = ['type' => 'add', 'name' => $l['name'], 'qty' => (int) $l['qty']];
                continue;
            }
            $o = $p[$k];
            if ((int) $o['qty'] !== (int) $l['qty']) {
                $out[] = ['type' => 'qty', 'name' => $l['name'], 'from' => (int) $o['qty'], 'to' => (int) $l['qty']];
            }
            if (round((float) $o['price'], 2) !== round((float) $l['price'], 2)) {
                $out[] = ['type' => 'price', 'name' => $l['name'], 'from' => (float) $o['price'], 'to' => (float) $l['price']];
            }
            if (round((float) ($o['disc'] ?? 0), 2) !== round((float) ($l['disc'] ?? 0), 2)) {
                $out[] = ['type' => 'discount', 'name' => $l['name'], 'from' => (float) ($o['disc'] ?? 0), 'to' => (float) ($l['disc'] ?? 0)];
            }
        }
        foreach ($p as $k => $l) {
            if (! isset($n[$k])) {
                $out[] = ['type' => 'remove', 'name' => $l['name'], 'qty' => (int) $l['qty']];
            }
        }

        return $out;
    }

    /** Rewrite quotation_items to mirror the current revision (the fast projection). */
    private function syncItems(Tenant $t, Quotation $q, array $lines): void
    {
        $q->items()->delete();
        foreach ($lines as $l) {
            QuotationItem::create([
                'quotation_id' => $q->id,
                'tenant_id'    => $t->id,
                'name'         => (string) $l['name'],
                'qty'          => (int) $l['qty'],
                'unit_price'   => (float) $l['price'],
                'line_total'   => (float) $l['qty'] * (float) $l['price'],
                'unit_label'   => ((string) ($l['unit'] ?? '')) ?: null,
                'image_url'    => ((string) ($l['image'] ?? '')) ?: null,
                'matched'      => true,
            ]);
        }
    }
}
