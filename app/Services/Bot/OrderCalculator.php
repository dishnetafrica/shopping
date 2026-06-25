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

    /** @param array $items list of ['query'=>string,'qty'=>int]  →  ['lines'=>[], 'total'=>float, 'currency'=>string] */
    public function quote(Tenant $tenant, array $items): array
    {
        $products = $this->catalogue($tenant);
        $currency = (string) ($tenant->setting('currency', 'UGX'));
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
                $name = $query; $img = ''; $unit = ''; $moq = null;
                $hits = $this->matcher->search($query, $products);
                $p    = $hits[0]['product'] ?? ($hits[0] ?? null);
                if (is_array($p) && mb_strtolower(trim((string) ($p['name'] ?? ''))) === mb_strtolower(trim($query))) {
                    $name = (string) $p['name'];
                    $img  = (string) ($p['image'] ?? '');
                    $unit = (string) ($p['unit_label'] ?? '');
                    $moq  = isset($p['moq']) ? (int) $p['moq'] : null;
                }
                $sum    = $given * $qty;
                $total += $sum;
                $lines[] = ['name' => $name, 'qty' => $qty, 'price' => $given, 'sum' => $sum, 'unit' => $unit, 'moq' => $moq, 'image' => $img, 'matched' => true];
                continue;
            }

            $hits = $this->matcher->search($query, $products);
            $p    = $hits[0]['product'] ?? null;
            if (! $p || ! isset($p['price'])) {
                $lines[] = ['name' => $query, 'qty' => $qty, 'price' => null, 'sum' => null, 'matched' => false];
                continue;
            }
            $price = (float) $p['price'];
            if ($price <= 0) {
                // price-on-request item (e.g. jumbo reels): never total a zero — flag for the team.
                $lines[] = ['name' => ($p['name'] ?? $query), 'qty' => $qty, 'price' => null, 'sum' => null, 'matched' => false];
                continue;
            }
            $sum   = $price * $qty;
            $total += $sum;
            $lines[] = [
                'name'    => (string) $p['name'],
                'qty'     => $qty,
                'price'   => $price,
                'sum'     => $sum,
                'unit'    => (string) ($p['unit_label'] ?? ''),
                'moq'     => isset($p['moq']) ? (int) $p['moq'] : null,
                'image'   => (string) ($p['image'] ?? ''),
                'matched' => true,
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
        $out = ["🧮 Order total (from our price list — please confirm):"];
        foreach ($quote['lines'] as $l) {
            if (! $l['matched']) {
                $out[] = "• {$l['qty']} × {$l['name']} — not on the list, team will confirm";
                continue;
            }
            $unit = $l['unit'] ? " {$l['unit']}" : '';
            $note = ($l['moq'] && $l['qty'] < $l['moq']) ? " (min order {$l['moq']})" : '';
            $out[] = "• {$l['qty']} × {$l['name']}{$unit} = {$cur} " . number_format($l['sum']) . $note;
        }
        $out[] = "————\n*Total: {$cur} " . number_format($quote['total']) . "*";
        return implode("\n", $out);
    }

    private function catalogue(Tenant $tenant): array
    {
        return Cache::remember("ai_calc_catalogue:{$tenant->id}", 60, function () use ($tenant) {
            return Product::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)->where('active', true)
                ->get()
                ->map(fn ($p) => [
                    'id' => $p->id, 'name' => (string) $p->name, 'price' => (float) ($p->base_price ?? $p->price ?? 0),
                    'category' => (string) ($p->category ?? ''), 'keywords' => (string) ($p->keywords ?? ''),
                    'unit_label' => (string) ($p->unit_label ?? ''), 'moq' => $p->moq ? (int) $p->moq : null,
                    'image' => (string) ($p->image_url ?? ''),
                ])->all();
        });
    }
}
