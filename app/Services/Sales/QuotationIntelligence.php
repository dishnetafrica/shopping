<?php

namespace App\Services\Sales;

use App\Models\Product;
use App\Models\Quotation;
use App\Models\Tenant;
use App\Services\Inventory\StockLedger;
use App\Services\ProductRecommendations;
use App\Services\Storefront\BuyAgain;
use App\Services\Storefront\BuyerContext;

/**
 * Quotation intelligence — deterministic, stock-and-history-grounded sales suggestions.
 *
 * Given a quotation it answers the questions a good salesperson asks: what can ship today vs what
 * needs the next Kampala truck, which lines are short on stock, what in-stock alternative to offer
 * for an unavailable line, what this customer usually buys, and whether they qualify for a volume
 * discount. It REUSES the existing engines (StockLedger, ProductRecommendations, BuyAgain,
 * BuyerContext) — nothing is duplicated — and every lookup is fail-soft, so a missing service or
 * data never breaks the quotation. No LLM: the suggestions are rules over real inventory + history.
 */
class QuotationIntelligence
{
    /** Full intelligence bundle for the sales team. */
    public function for(Tenant $t, Quotation $q): array
    {
        $q->loadMissing('items');
        $names    = $q->items->pluck('name')->all();
        $products = $this->resolveProducts($t, $names);
        // v4.64.2 — can we ship it? From the warehouse the goods are actually in.
        $kMap     = $this->safeMap(fn () => StockLedger::forTenant($t)->onHandMap(StockLedger::home($t)));

        $shipToday = [];
        $awaiting  = [];
        $lowStock  = [];
        $alts      = [];
        foreach ($q->items as $it) {
            $key    = mb_strtolower((string) $it->name);
            $p      = $products[$key] ?? null;
            $qty    = (int) $it->qty;
            $onHand = $p ? (float) ($kMap[$p->id] ?? 0) : 0.0;

            if ($p && $onHand >= $qty) {
                $shipToday[] = ['name' => (string) $it->name, 'qty' => $qty, 'on_hand' => $onHand];
            } else {
                $awaiting[]  = ['name' => (string) $it->name, 'qty' => $qty, 'on_hand' => $onHand, 'short' => max(0, $qty - $onHand)];
                $lowStock[]  = ['name' => (string) $it->name, 'qty' => $qty, 'on_hand' => $onHand];
                if ($p) {
                    $alt = $this->alternativeFor($t, $p, $kMap, $qty);
                    if ($alt) {
                        $alts[] = ['for' => (string) $it->name, 'suggested' => $alt];
                    }
                }
            }
        }

        $buyer = $this->safe(fn () => app(BuyerContext::class)->for($t, (string) $q->customer_phone), ['mode' => 'retail', 'segment' => '', 'credit' => null]);

        return [
            'ok'              => true,
            'dispatch'        => ['ship_today' => $shipToday, 'awaiting_stock' => $awaiting],
            'low_stock'       => $lowStock,
            'alternatives'    => $alts,
            'usual_items'     => $this->usualItems($t, $q, $names),
            'volume_discount' => $this->volumeDiscount($t, $q, $buyer),
            'segment'         => (string) ($buyer['segment'] ?? ''),
            'mode'            => (string) ($buyer['mode'] ?? 'retail'),
            'credit'          => $buyer['credit'] ?? null,
        ];
    }

    /** Customer-facing subset: only the in-stock alternatives for unavailable lines (Smart Alternatives). */
    public function customerAlternatives(Tenant $t, Quotation $q): array
    {
        $q->loadMissing('items');
        $products = $this->resolveProducts($t, $q->items->pluck('name')->all());
        // v4.64.2 — can we ship it? From the warehouse the goods are actually in.
        $kMap     = $this->safeMap(fn () => StockLedger::forTenant($t)->onHandMap(StockLedger::home($t)));

        $out = [];
        foreach ($q->items as $it) {
            $p   = $products[mb_strtolower((string) $it->name)] ?? null;
            $qty = (int) $it->qty;
            if (! $p) {
                continue;
            }
            $onHand = (float) ($kMap[$p->id] ?? 0);
            if ($onHand >= $qty) {
                continue; // available — no alternative needed
            }
            $alt = $this->alternativeFor($t, $p, $kMap, $qty);
            if ($alt) {
                $out[] = ['for' => (string) $it->name, 'suggested' => $alt];
            }
        }

        return $out;
    }

    /** Set of line names that are currently short on Kampala stock (for the portal "unavailable" badge). */
    public function unavailableNames(Tenant $t, Quotation $q): array
    {
        $q->loadMissing('items');
        $products = $this->resolveProducts($t, $q->items->pluck('name')->all());
        // v4.64.2 — can we ship it? From the warehouse the goods are actually in.
        $kMap     = $this->safeMap(fn () => StockLedger::forTenant($t)->onHandMap(StockLedger::home($t)));
        $names    = [];
        foreach ($q->items as $it) {
            $p = $products[mb_strtolower((string) $it->name)] ?? null;
            if ($p && (float) ($kMap[$p->id] ?? 0) < (int) $it->qty) {
                $names[] = (string) $it->name;
            }
        }

        return $names;
    }

    // ---- internals ----

    private function resolveProducts(Tenant $t, array $names): array
    {
        if (! $names) {
            return [];
        }
        $rows = Product::where('tenant_id', $t->id)->whereIn('name', $names)->get();
        $out  = [];
        foreach ($rows as $p) {
            $out[mb_strtolower((string) $p->name)] = $p;
        }

        return $out;
    }

    /** First in-stock similar (or same-category) product that can cover the quantity. */
    private function alternativeFor(Tenant $t, Product $p, array $kMap, int $qty): ?array
    {
        $pick = function ($candidates) use ($p, $kMap, $qty) {
            foreach ($candidates as $c) {
                if (! $c || (int) $c->id === (int) $p->id) {
                    continue;
                }
                if ((float) ($kMap[$c->id] ?? 0) >= $qty) {
                    return [
                        'name'       => (string) $c->name,
                        'price'      => (float) $c->price,
                        'price_diff' => (float) $c->price - (float) $p->price,
                        'image'      => (string) ($c->image_url ?? ''),
                        'on_hand'    => (float) ($kMap[$c->id] ?? 0),
                    ];
                }
            }

            return null;
        };

        $alt = $pick($this->safe(fn () => ProductRecommendations::similar($p, 8), collect()));
        if ($alt) {
            return $alt;
        }

        return $pick($this->safe(fn () => ProductRecommendations::topInCategory($t->id, (string) $p->category, 8, $p->id), collect()));
    }

    /** What this customer usually buys (repeat purchases) that isn't already on the quotation. */
    private function usualItems(Tenant $t, Quotation $q, array $onQuoteNames): array
    {
        $on = array_map(fn ($n) => mb_strtolower((string) $n), $onQuoteNames);
        $rows = $this->safe(fn () => app(BuyAgain::class)->for($t->id, (string) $q->customer_phone, 12), []);
        $out = [];
        foreach ((array) $rows as $b) {
            $nm = (string) ($b['name'] ?? '');
            if ($nm === '' || in_array(mb_strtolower($nm), $on, true)) {
                continue;
            }
            $out[] = ['name' => $nm, 'times' => (int) ($b['times'] ?? 1)];
            if (count($out) >= 6) {
                break;
            }
        }

        return $out;
    }

    /** Volume-discount qualification for wholesale-type buyers, using configurable tiers. */
    private function volumeDiscount(Tenant $t, Quotation $q, array $buyer): array
    {
        $mode = (string) ($buyer['mode'] ?? 'retail');
        $subtotal = (float) $q->items->sum('line_total');
        if (! in_array($mode, ['wholesale', 'distributor', 'institutional'], true)) {
            return ['qualifies' => false, 'subtotal' => $subtotal];
        }

        // Tiers: [[minAmount, pct], …]; tenant-overridable via the quote_volume_tiers setting.
        $tiers = (array) $t->setting('quote_volume_tiers', []);
        if (! $tiers) {
            $tiers = [[10_000_000, 5], [5_000_000, 3], [2_000_000, 2]];
        }
        // Normalise + sort by amount desc.
        $norm = [];
        foreach ($tiers as $tier) {
            $amt = (float) ($tier[0] ?? ($tier['amount'] ?? 0));
            $pct = (float) ($tier[1] ?? ($tier['pct'] ?? 0));
            if ($amt > 0 && $pct > 0) {
                $norm[] = ['amount' => $amt, 'pct' => $pct];
            }
        }
        usort($norm, fn ($a, $b) => $b['amount'] <=> $a['amount']);

        foreach ($norm as $tier) {
            if ($subtotal >= $tier['amount']) {
                return ['qualifies' => true, 'pct' => $tier['pct'], 'threshold' => $tier['amount'], 'subtotal' => $subtotal];
            }
        }
        // Not yet — surface the nearest tier as a nudge ("spend X more for Y%").
        $next = null;
        foreach (array_reverse($norm) as $tier) {
            if ($subtotal < $tier['amount']) {
                $next = ['amount' => $tier['amount'], 'pct' => $tier['pct'], 'gap' => $tier['amount'] - $subtotal];
                break;
            }
        }

        return ['qualifies' => false, 'subtotal' => $subtotal, 'next' => $next];
    }

    private function safe(callable $fn, $default = null)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            return $default;
        }
    }

    private function safeMap(callable $fn): array
    {
        return (array) $this->safe($fn, []);
    }
}
