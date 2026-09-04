<?php

namespace App\Services\Bot;

use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;

/**
 * Deterministic order total. The LLM is good at *extracting* items + quantities but bad at
 * arithmetic, so it never computes the total — this does, in plain PHP, from catalogue prices.
 * That keeps the Pal's failure mode (LLM cart math) out of the manufacturer bot.
 */
class OrderCalculator
{
    public function __construct(private CatalogueMatcher $matcher) {}

    /**
     * @param array $items list of ['query'=>string,'qty'=>int]  →  ['lines'=>[], 'total'=>float, 'currency'=>string]
     * @param string|null $customerPhone v4.9 dealer programme 4a: when the conversing customer's phone is
     *                                   given and their profile is a dealer, every matched line resolves
     *                                   through PriceResolver (tier %/override, margin-floor guarded).
     */
    public function quote(Tenant $tenant, array $items, ?string $customerPhone = null, bool $skipOutOfStock = false): array
    {
        $products = $this->catalogue($tenant);
        $currency = (string) ($tenant->setting('currency', 'UGX'));
        $dec      = \App\Services\Pricing::decimalsForCurrency($currency);
        $moqMode  = strtolower((string) ($tenant->setting('moq_mode', 'off'))); // off | roundup
        // Loose / per-piece retail (same rule as the storefront's repriceCart): a carton item the
        // customer asked for by the piece is charged carton-price / pack * (1 + markup). Off unless
        // the tenant enabled it; the carton MOQ does not apply to a loose line.
        $looseOn  = (bool) $tenant->setting('loose_enabled', false);
        $loosePct = (float) $tenant->setting('loose_markup_pct', 20);
        $dealer   = \App\Services\Pricing\PriceResolver::profileFor($tenant, $customerPhone);
        $dealerPct = $dealer ? \App\Services\Pricing\PriceResolver::pctFor($tenant, $dealer) : null;
        $floorPct  = $dealer ? \App\Services\Pricing\PriceResolver::floorFor($tenant) : 0.0;
        $lines = [];
        $total = 0.0;

        foreach ($items as $it) {
            $query = trim((string) ($it['query'] ?? ''));
            $qty   = max(1, (int) ($it['qty'] ?? 1));
            if ($query === '') continue;

            // Trusted-price path (POS): the seller picked a real catalogue product, so the
            // cart price is authoritative. Use it directly — matching is only used to enrich
            // the product image, and can never block the quote ("nothing to quote").
            $given = isset($it['price']) ? (float) $it['price'] : 0.0;
            if ($given > 0) {
                $name = $query; $img = ''; $unit = ''; $moq = null; $pack = 0;
                $hits = $this->matcher->search($query, $products);
                $p    = $hits[0]['product'] ?? ($hits[0] ?? null);
                if (is_array($p) && mb_strtolower(trim((string) ($p['name'] ?? ''))) === mb_strtolower(trim($query))) {
                    $name = (string) $p['name'];
                    $img  = (string) ($p['image'] ?? '');
                    $unit = (string) ($p['unit_label'] ?? '');
                    $moq  = isset($p['moq']) ? (int) $p['moq'] : null;
                    $pack = (int) ($p['pack_size'] ?? 0);
                }
                $sum    = $given * $qty;
                $total += $sum;
                $lines[] = ['name' => $name, 'qty' => $qty, 'price' => $given, 'sum' => $sum, 'unit' => $unit, 'moq' => $moq, 'pack' => $pack, 'image' => $img, 'matched' => true];
                continue;
            }

            $hits = $this->matcher->search($query, $products);
            // Ambiguous generic term: the query matched several near-tied SKUs spanning a wide price
            // range (e.g. "aluminium foil" → $33…$155) with no distinguishing word. Do NOT guess a
            // price from hits[0] — flag it so the caller asks WHICH product instead of quoting the wrong
            // one. (POS trusted-price picks are handled above and never reach here.)
            $amb = $this->matcher->ambiguityCandidates($hits);
            if ($amb !== null) {
                $lines[] = ['name' => $query, 'qty' => $qty, 'price' => null, 'sum' => null, 'matched' => false, 'ambiguous' => true, 'candidates' => $amb];
                continue;
            }
            $p    = $hits[0]['product'] ?? null;
            if (! $p || ! isset($p['price'])) {
                $lines[] = ['name' => $query, 'qty' => $qty, 'price' => null, 'sum' => null, 'matched' => false];
                continue;
            }
            // Out of stock: on the bot quote path (skipOutOfStock=true) we do NOT price something we
            // can't supply — flag it unmatched+out_of_stock so the caller can tell the customer instead
            // of putting an unavailable item on a firm quotation. POS/panel pass false and are unaffected.
            $outOfStock = isset($p['stock']) && (float) $p['stock'] <= 0;
            if ($skipOutOfStock && $outOfStock) {
                $lines[] = ['name' => (string) ($p['name'] ?? $query), 'qty' => $qty, 'price' => null, 'sum' => null, 'matched' => false, 'out_of_stock' => true];
                continue;
            }
            $price = (float) $p['price'];
            $name  = (string) $p['name'];
            $grams = (int) ($it['weight_grams'] ?? 0);

            // Weighted item (e.g. "Fafda 250g"): price the requested weight via the SAME pricer the
            // deterministic path uses, instead of collapsing to the default (1 kg) variant.
            if ($grams > 0 && ! empty($p['sold_by_weight'])) {
                $res = \App\Services\Bot\Pricing\WeightPricer::price($grams, [
                    'reference_price'        => $p['reference_price'] ?? null,
                    'reference_weight_grams' => (int) ($p['reference_weight_grams'] ?? 1000),
                    'variants'               => $p['variants'] ?? [],
                ]);
                if ($res['ok'] ?? false) {
                    $price = (float) $res['price'];
                    $name  = \App\Services\Bot\Pricing\WeightParser::label($grams) . ' ' . $p['name'];
                }
            }

            if ($price <= 0) {
                // price-on-request item (e.g. jumbo reels): never total a zero — flag for the team.
                $lines[] = ['name' => ($p['name'] ?? $query), 'qty' => $qty, 'price' => null, 'sum' => null, 'matched' => false];
                continue;
            }
            // Dealer pricing (v4.9, 4a): tier %/per-product override through the ONE resolver, floor-guarded.
            // Weighted items are exempt in v1 (their price already came from WeightPricer variants).
            $isDealerLine = false;
            if ($dealer && ! ($grams > 0 && ! empty($p['sold_by_weight']))) {
                [$dp, $src] = \App\Services\Pricing\PriceResolver::compute(
                    $price, isset($p['cost']) ? (float) $p['cost'] : null,
                    isset($p['dealer_price']) ? (float) $p['dealer_price'] : null,
                    $dealerPct, $floorPct
                );
                if ($src !== 'retail') {
                    if ($src === 'clamped') {
                        \App\Support\BotTrace::log($tenant->id, 'dealer_clamp', (string) $customerPhone,
                            'price_floor', $name . ': ' . $price . ' → ' . $dp);
                    }
                    $price = $dp;
                    $isDealerLine = true;
                }
            }
            $moq      = isset($p['moq']) ? (int) $p['moq'] : null;
            $reqQty   = $qty;
            $adjusted = false;
            $pack     = (int) ($p['pack_size'] ?? 0);

            // Loose (per-piece) line: the customer asked for pieces of a carton-packed product and the
            // tenant enabled loose selling. Reprice per piece at the retail markup; the carton MOQ does
            // NOT apply (loose minimum is 1). Not for weighted items or dealer lines (dealers buy cartons).
            $loose = $looseOn && ! empty($it['loose']) && $pack > 1 && $price > 0
                     && ! ($grams > 0 && ! empty($p['sold_by_weight'])) && ! $isDealerLine;
            if ($loose) {
                $override = isset($p['piece_price']) && $p['piece_price'] !== null ? (float) $p['piece_price'] : null;
                $price = \App\Services\Pricing::piecePrice($override, $price, $pack, $loosePct, $dec);
            }

            // MOQ enforcement — bot/customer quotes only (the POS trusted-price branch above is exempt,
            // since the seller may legitimately sell below MOQ). 'roundup' raises a below-minimum
            // quantity up to the MOQ and flags it so the customer is told plainly. A loose line is
            // exempt — its minimum is one piece, not one carton.
            if (! $loose && $moqMode === 'roundup' && $moq && $qty < $moq) {
                $qty = $moq;
                $adjusted = true;
            }
            $sum   = $price * $qty;
            $total += $sum;
            $lines[] = [
                'name'         => $name,
                'qty'          => $qty,
                'price'        => $price,
                'sum'          => $sum,
                'weight_grams' => $grams ?: null,
                'unit'         => (string) ($p['unit_label'] ?? ''),
                'moq'          => $moq,
                'pack'         => $pack,
                'loose'        => $loose,
                'req_qty'      => $reqQty,
                'adjusted'     => $adjusted,
                'image'        => (string) ($p['image'] ?? ''),
                'matched'      => true,
                'dealer'       => $isDealerLine,
                'out_of_stock' => $outOfStock,
            ];
        }

        return ['lines' => $lines, 'total' => $total, 'currency' => $currency];
    }

    /** Render the quote as a WhatsApp block, or '' if nothing matched. */
    public function render(array $quote): string
    {
        $matched = array_filter($quote['lines'], fn ($l) => $l['matched']);
        if (! $matched) return '';

        $cur = $quote['currency'];
        $dec = \App\Services\Pricing::decimalsForCurrency($cur);
        $out = ["🧮 Order total (from our price list — please confirm):"];
        foreach ($quote['lines'] as $l) {
            if (! $l['matched']) {
                $out[] = "• {$l['qty']} × {$l['name']} — not on the list, team will confirm";
                continue;
            }
            // A loose line is already priced per piece — say so plainly and never show the carton
            // unit label ("Carton") or a min-order note against it (its minimum is one piece).
            if (! empty($l['loose'])) {
                $out[] = "• {$l['qty']} × {$l['name']} (per piece) = {$cur} " . number_format($l['sum'], $dec);
                continue;
            }
            $unit = $l['unit'] ? " {$l['unit']}" : '';
            $note = '';
            if (! empty($l['adjusted'])) {
                $note = " _(min order {$l['moq']} — set to {$l['qty']})_";
            } elseif (! empty($l['moq']) && $l['qty'] < $l['moq']) {
                $note = " (min order {$l['moq']})";
            }
            // Loose / part-carton rate: when a product is sold by carton with a known pack size,
            // show the per-piece price so a customer who wants less than a full carton knows the rate.
            $per = '';
            if (! empty($l['pack']) && (int) $l['pack'] > 1 && $l['unit'] && ! empty($l['price'])) {
                $pp  = (float) $l['price'] / (int) $l['pack'];
                $per = " _(loose: {$cur} " . number_format($pp, $dec) . "/pc)_";
            }
            $out[] = "• {$l['qty']} × {$l['name']}{$unit} = {$cur} " . number_format($l['sum'], $dec) . $note . $per;
        }
        $out[] = "————\n*Total: {$cur} " . number_format($quote['total'], $dec) . "*";
        // v4.9 — dealers see plainly that their trade price is already applied.
        foreach ($quote['lines'] as $l) {
            if (! empty($l['dealer'])) { $out[] = '_✓ Your dealer price is applied._'; break; }
        }
        return implode("\n", $out);
    }

    private function catalogue(Tenant $tenant): array
    {
        return Cache::remember("ai_calc_catalogue:{$tenant->id}", 60, function () use ($tenant) {
            return Product::withoutGlobalScopes()->with('weightVariants')
                ->where('tenant_id', $tenant->id)->where('active', true)
                ->get()
                ->map(fn ($p) => [
                    'id' => $p->id, 'name' => (string) $p->name, 'price' => (float) ($p->price ?? $p->base_price ?? 0),   // price-drift fix: the BOT charges the panel's price
                    // v4.9 dealer programme: cost feeds the margin floor; dealer_price is the per-product
                    // override (attribute reads null pre-migration — fully guarded).
                    'cost' => $p->cost_price !== null ? (float) $p->cost_price : null,
                    'dealer_price' => isset($p->dealer_price) && $p->dealer_price !== null ? (float) $p->dealer_price : null,
                    'category' => (string) ($p->category ?? ''), 'keywords' => (string) ($p->keywords ?? ''),
                    'unit_label' => (string) ($p->unit_label ?? ''), 'moq' => $p->moq ? (int) $p->moq : null,
                    'pack_size' => $p->pack_size ? (int) $p->pack_size : 0,
                    'piece_price' => isset($p->piece_price) && $p->piece_price !== null ? (float) $p->piece_price : null,   // explicit per-piece retail override
                    // On-hand (home cache) — feeds the matcher's in-stock ranking bonus (until now the
                    // field was never supplied, so that bonus was dead) and lets bot quotes skip items
                    // we don't actually have.
                    'stock' => (float) ($p->stock ?? 0),
                    'image' => (string) ($p->image_url ?? ''),
                    'sold_by_weight' => (bool) ($p->sold_by_weight ?? false),
                    'reference_price' => $p->reference_price !== null ? (float) $p->reference_price : null,
                    'reference_weight_grams' => (int) ($p->reference_weight_grams ?: 1000),
                    'variants' => $p->weightVariants
                        ->mapWithKeys(fn ($v) => [(int) $v->weight_grams => (float) $v->price])->all(),
                ])->all();
        });
    }
}
