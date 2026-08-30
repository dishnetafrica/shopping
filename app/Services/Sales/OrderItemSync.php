<?php

namespace App\Services\Sales;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Tenant;

/**
 * Mirrors an order's items_json blob into real order_items rows — the projection the V3 order
 * screen, COGS/margin, analytics, demand planning and recommendations all read.
 *
 * Why (v4.6.4, live bug #8): only POS checkout and the legacy BotBrain wrote OrderItem rows.
 * Quotation conversion, the panel's save-order path and AI-bot orders wrote ONLY items_json —
 * so every one of those orders showed "No line items recorded" on the V3 screen, had no
 * COGS/margin, and was invisible to every order_items-based report. (Customer documents were
 * unaffected: invoices, delivery notes and stock issuing read items_json directly.)
 *
 * Design: authoritative REBUILD (delete + insert) so it is idempotent and also handles item
 * edits; product_id kept when the blob carries it, else resolved by exact case-insensitive
 * name match against the tenant's catalogue (one query). Rows without a match still insert
 * (product_id null) — the screen shows them; only cost lookup is skipped. Callers wrap in
 * try/catch: syncing must never break order creation.
 */
final class OrderItemSync
{
    /** Rebuild order_items from items_json. Returns rows written (0 = blob empty, nothing touched). */
    public function sync(Tenant $t, Order $o): int
    {
        $raw = $o->items_json;
        $lines = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);
        $lines = array_values(array_filter($lines, fn ($l) => is_array($l) && trim((string) ($l['name'] ?? '')) !== ''));
        if (! $lines) {
            return 0;   // never wipe rows over an empty/absent blob (e.g. BotBrain modifier rows)
        }

        // Resolve product ids by exact name (case-insensitive) in ONE query for the unmatched lines.
        $need = [];
        foreach ($lines as $l) {
            if (empty($l['product_id'])) {
                $need[] = mb_strtolower(trim((string) $l['name']));
            }
        }
        $byName = [];
        if ($need) {
            $rows = Product::withoutGlobalScopes()->where('tenant_id', $t->id)
                ->whereIn(\DB::raw('lower(name)'), array_values(array_unique($need)))
                ->get(['id', 'name']);
            foreach ($rows as $p) {
                $byName[mb_strtolower(trim((string) $p->name))] = (int) $p->id;
            }
        }

        OrderItem::withoutGlobalScopes()->where('order_id', $o->id)->where('tenant_id', $t->id)->delete();
        $n = 0;
        foreach ($lines as $l) {
            $name = trim((string) $l['name']);
            // Normalise the loose signal to a single value on the row: a line is loose when the bot
            // pricer flagged it (loose=true) or the storefront stamped unit='piece'. COGS keys off this.
            $loose = ! empty($l['loose']) || strtolower(trim((string) ($l['unit'] ?? ''))) === 'piece';
            OrderItem::create([
                'tenant_id'  => $t->id,
                'order_id'   => $o->id,
                'product_id' => ! empty($l['product_id']) ? (int) $l['product_id'] : ($byName[mb_strtolower($name)] ?? null),
                'name'       => $name,
                'qty'        => (float) ($l['qty'] ?? 1),
                'price'      => (float) ($l['price'] ?? $l['unit_price'] ?? 0),
                'unit'       => $loose ? 'piece' : ((trim((string) ($l['unit'] ?? '')) !== '') ? trim((string) $l['unit']) : null),
            ]);
            $n++;
        }

        return $n;
    }
}
