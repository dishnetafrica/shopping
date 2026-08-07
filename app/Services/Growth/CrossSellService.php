<?php declare(strict_types=1);

namespace App\Services\Growth;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\Catalog\Visibility;
use Illuminate\Support\Facades\Cache;

/**
 * v4.56 — the cross-sell gaps, from the order book.
 *
 * Co-occurrence is counted at the CUSTOMER level, not the order level. A supermarket that buys foil
 * in January and cling film in February buys both, and an order-level count would never notice —
 * which is the difference between a wholesale relationship and a supermarket trolley. The existing
 * `ProductRecommendations::alsoBought` counts baskets and is right for the storefront; this is a
 * different question and gets its own answer.
 *
 * All arithmetic lives in CrossSell, which has no database and can be argued with in a harness.
 * This class only counts things and applies the shop's own rules about what may be suggested.
 */
final class CrossSellService
{
    /** A year of buying is the relationship; older than that is history. */
    private const WINDOW_DAYS = 365;

    /** One customer with a 300-line history would otherwise contribute 45,000 pairs on its own. */
    private const MAX_BASKET = 60;

    /** Never suggest more than a rep can hold in their head walking into a shop. */
    private const MAX_GAPS = 5;

    private const DEAD = ['cancelled', 'canceled', 'rejected', 'draft', 'void'];

    /**
     * Every customer's gaps, keyed by normalised phone.
     *
     * Built once per tenant and cached: the pair counts are shop-wide, so computing them per
     * customer would be the same work repeated 676 times.
     *
     * @return array<string, array<int,array>>
     */
    public function forTenant(Tenant $t, bool $fresh = false): array
    {
        $key = 'cross_sell:' . $t->id;
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, 3600, fn () => $this->build($t));
    }

    /** One customer's gaps, best first. */
    public function forCustomer(Tenant $t, ?string $phone): array
    {
        $digits = preg_replace('/[^0-9]/', '', (string) $phone);

        return $digits === '' ? [] : ($this->forTenant($t)[$digits] ?? []);
    }

    private function build(Tenant $t): array
    {
        $baskets = $this->baskets($t);
        if (count($baskets) < CrossSell::MIN_ANCHOR) {
            return [];                      // too few customers for any fraction to mean anything
        }

        // Shop-wide counts: how many customers buy each product, and how many buy each pair.
        $buyers = [];
        $pairs  = [];
        foreach ($baskets as $set) {
            $ids = array_slice(array_keys($set), 0, self::MAX_BASKET);
            foreach ($ids as $a) {
                $buyers[$a] = ($buyers[$a] ?? 0) + 1;
            }
            $n = count($ids);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    [$x, $y] = $ids[$i] < $ids[$j] ? [$ids[$i], $ids[$j]] : [$ids[$j], $ids[$i]];
                    $pairs[$x][$y] = ($pairs[$x][$y] ?? 0) + 1;
                }
            }
        }

        $total    = count($baskets);
        $sellable = $this->sellable($t, array_keys($buyers));
        $out      = [];

        foreach ($baskets as $digits => $set) {
            $has  = array_keys($set);
            $gaps = [];

            foreach ($sellable as $candId => $cand) {
                if (isset($set[$candId])) {
                    continue;                                   // they already buy it
                }
                $best = null;
                foreach ($has as $anchorId) {
                    $both = $pairs[min($anchorId, $candId)][max($anchorId, $candId)] ?? 0;
                    if ($both < CrossSell::MIN_SUPPORT) {
                        continue;
                    }
                    $j = CrossSell::judge($both, $buyers[$anchorId] ?? 0, $buyers[$candId] ?? 0, $total);
                    if ($j['ok'] && ($best === null || $j['confidence'] > $best['confidence'])) {
                        $best = $j + ['anchorId' => $anchorId];
                    }
                }
                if ($best === null) {
                    continue;
                }

                $anchorName = $sellable[$best['anchorId']]['name'] ?? ($this->nameOf($t, $best['anchorId']));
                $gap = $best + [
                    'product_id' => $candId,
                    'product'    => $cand['name'],
                    'anchor'     => $anchorName,
                    'value'      => $cand['price'],
                    'sentence'   => CrossSell::sentence($anchorName, $cand['name'], $best['both'], $best['anchorBuyers']),
                ];
                $gap['rank'] = CrossSell::rank($gap);
                $gaps[] = $gap;
            }

            if ($gaps) {
                usort($gaps, fn ($a, $b) => $b['rank'] <=> $a['rank']);
                $out[$digits] = array_slice($gaps, 0, self::MAX_GAPS);
            }
        }

        return $out;
    }

    /**
     * Customer → the product ids they have bought in the window, most recent first.
     *
     * Keyed by normalised phone, like every other customer lookup in this system. Lines with no
     * product_id are skipped: a free-typed line cannot be matched to a catalogue product without
     * guessing, and a guess here would put a product in a customer's history that they never bought.
     *
     * @return array<string, array<int,bool>>
     */
    private function baskets(Tenant $t): array
    {
        $orderIds = Order::withoutGlobalScopes()
            ->where('tenant_id', $t->id)
            ->whereNotIn('status', self::DEAD)
            ->where('created_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->pluck('customer_phone', 'id');

        $baskets = [];
        OrderItem::withoutGlobalScopes()
            ->where('tenant_id', $t->id)
            ->whereIn('order_id', $orderIds->keys())
            ->whereNotNull('product_id')
            ->orderByDesc('id')
            ->get(['order_id', 'product_id'])
            ->each(function ($it) use (&$baskets, $orderIds) {
                $digits = preg_replace('/[^0-9]/', '', (string) ($orderIds[$it->order_id] ?? ''));
                if ($digits === '') {
                    return;
                }
                $baskets[$digits][(int) $it->product_id] = true;
            });

        return $baskets;
    }

    /**
     * The products that may actually be suggested: in stock, priced, and not hidden.
     *
     * Suggesting something a customer cannot be sold wastes a rep's visit, and suggesting a hidden
     * line would walk straight around the switch added in v4.54.
     *
     * @return array<int,array{name:string,price:float}>
     */
    private function sellable(Tenant $t, array $ids): array
    {
        if (! $ids) {
            return [];
        }
        $out = [];
        Visibility::scope(
            Product::withoutGlobalScopes()->where('tenant_id', $t->id)
                ->whereIn('id', $ids)->where('active', true)->where('price', '>', 0)
                ->whereNull('archived_at'),
            $t
        )->get(['id', 'name', 'price'])->each(function ($p) use (&$out) {
            if ($p->stock === null || (int) $p->stock > 0) {
                $out[(int) $p->id] = ['name' => (string) $p->name, 'price' => (float) $p->price];
            }
        });

        return $out;
    }

    /** An anchor the customer buys may itself be hidden or delisted; it still names the pattern. */
    private function nameOf(Tenant $t, int $id): string
    {
        try {
            return (string) (Product::withoutGlobalScopes()->where('tenant_id', $t->id)
                ->where('id', $id)->value('name') ?: 'that product');
        } catch (\Throwable $e) {
            return 'that product';
        }
    }

    public static function forget(int $tenantId): void
    {
        try { Cache::forget('cross_sell:' . $tenantId); } catch (\Throwable $e) {}
    }
}
