<?php declare(strict_types=1);

namespace App\Services\Comms\Marketing;

use App\Models\Tenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 — the one place campaign audience predicates are defined, so `audienceQuery` (single channel)
 * and `cursorQuery` (the 'both' union) can never drift apart. Every predicate is a fragment ANDed onto
 * a `customer_profiles` query already scoped to the tenant; the caller owns tenant scoping, this owns
 * "who matches the segment".
 *
 * Purchase-based segments (bought a product / category, date ranges) read the sales history two ways —
 * the precise `order_items.product_id` join AND a delimiter-guarded scan of `orders.items_json` — to
 * catch buyers whose lines were never mirrored into order_items (a known gap; see JubaStockCapability).
 * The delimiter guard (`"product_id":123}` / `"product_id":123,`) means product 123 can never match
 * 1234, so the scan only ever ADDS true matches — it under-matches rather than over-matches. This is
 * why the UI must say "matched from AVAILABLE order data", never imply a complete historical count.
 */
final class AudienceSegments
{
    /** Segment types the UI offers. Value keys are what a campaign stores in comm_campaigns.segment. */
    public const TYPES = [
        'all'                     => 'All opted-in customers',
        'segment'                 => 'Customer tag',
        'city'                    => 'City',
        'customer_type'           => 'Customer type (wholesale / hotel / restaurant / retail)',
        'ordered_within_days'     => 'Recent buyers (ordered within N days)',
        'not_ordered_within_days' => 'Inactive (no order within N days)',
        'ordered_between'         => 'Ordered within a date range',
        'not_ordered_between'     => 'Did NOT order within a date range',
        'bought_product'          => 'Bought a specific product',
        'bought_category'         => 'Bought a specific category',
        'not_bought_category'     => 'Has NOT bought a category',
    ];

    /** Apply every recognised key in $segment to a customer_profiles query builder. */
    public static function apply(Builder $q, Tenant $tenant, array $segment): void
    {
        $tid = (int) $tenant->id;

        if (! empty($segment['segment'])) {
            $q->where('segment', $segment['segment']);
        }
        if (! empty($segment['city'])) {
            $q->where('city', $segment['city']);
        }
        if (! empty($segment['customer_type'])) {
            self::customerType($q, (string) $segment['customer_type']);
        }
        if (! empty($segment['ordered_within_days'])) {
            self::orderedSince($q, $tid, now()->subDays((int) $segment['ordered_within_days']), true);
        }
        if (! empty($segment['not_ordered_within_days'])) {
            self::orderedSince($q, $tid, now()->subDays((int) $segment['not_ordered_within_days']), false);
        }
        if (! empty($segment['ordered_between'])) {
            self::orderedBetween($q, $tid, (array) $segment['ordered_between'], true);
        }
        if (! empty($segment['not_ordered_between'])) {
            self::orderedBetween($q, $tid, (array) $segment['not_ordered_between'], false);
        }
        if (! empty($segment['bought_product'])) {
            self::boughtProducts($q, $tid, [(int) $segment['bought_product']], true);
        }
        if (! empty($segment['bought_category'])) {
            self::boughtCategory($q, $tid, (string) $segment['bought_category'], true);
        }
        if (! empty($segment['not_bought_category'])) {
            self::boughtCategory($q, $tid, (string) $segment['not_bought_category'], false);
        }
    }

    /**
     * Customer type. `dealer_tier` gives wholesale/dealer precisely; hotel/restaurant/retail come from
     * the free-form `segment` tag, so they only match customers the tenant actually tagged (a data
     * dependency, not a bug — surfaced in the plan).
     */
    private static function customerType(Builder $q, string $type): void
    {
        $type = strtolower(trim($type));
        if ($type === 'wholesale') {
            $q->where(function ($w) {
                $w->whereIn('dealer_tier', ['wholesale', 'dealer'])
                  ->orWhereRaw('lower(coalesce(segment, \'\')) = ?', ['wholesale']);
            });
            return;
        }
        $q->whereRaw('lower(coalesce(segment, \'\')) = ?', [$type]);
    }

    private static function orderedSince(Builder $q, int $tid, Carbon $since, bool $did): void
    {
        $sub = function ($s) use ($tid, $since) {
            $s->select(DB::raw(1))->from('orders')
              ->whereColumn('orders.customer_phone', 'customer_profiles.phone')
              ->where('orders.tenant_id', $tid)
              ->where('orders.created_at', '>=', $since);
        };
        $did ? $q->whereExists($sub) : $q->whereNotExists($sub);
    }

    private static function orderedBetween(Builder $q, int $tid, array $range, bool $did): void
    {
        [$from, $to] = self::range($range);
        if ($from === null && $to === null) {
            return;
        }
        $sub = function ($s) use ($tid, $from, $to) {
            $s->select(DB::raw(1))->from('orders')
              ->whereColumn('orders.customer_phone', 'customer_profiles.phone')
              ->where('orders.tenant_id', $tid);
            if ($from !== null) { $s->where('orders.created_at', '>=', $from); }
            if ($to !== null)   { $s->where('orders.created_at', '<=', $to); }
        };
        $did ? $q->whereExists($sub) : $q->whereNotExists($sub);
    }

    private static function boughtCategory(Builder $q, int $tid, string $category, bool $did): void
    {
        $ids = DB::table('products')->where('tenant_id', $tid)->where('category', $category)
            ->pluck('id')->map(fn ($x) => (int) $x)->all();
        if (! $ids) {
            // An empty category can't have buyers. "bought" -> nobody; "not bought" -> everybody.
            if ($did) { $q->whereRaw('1 = 0'); }
            return;
        }
        self::boughtProducts($q, $tid, $ids, $did);
    }

    /**
     * Bought (or not) any of these product ids — order_items join OR a delimiter-guarded items_json
     * scan, so a buyer counts whichever place the line was recorded.
     *
     * @param  int[]  $productIds
     */
    private static function boughtProducts(Builder $q, int $tid, array $productIds, bool $did): void
    {
        $ids = array_values(array_unique(array_filter($productIds, fn ($x) => $x > 0)));
        if (! $ids) {
            if ($did) { $q->whereRaw('1 = 0'); }
            return;
        }

        $predicate = function ($w) use ($tid, $ids) {
            $w->whereExists(function ($s) use ($tid, $ids) {
                $s->select(DB::raw(1))->from('order_items')
                  ->join('orders', 'orders.id', '=', 'order_items.order_id')
                  ->whereColumn('orders.customer_phone', 'customer_profiles.phone')
                  ->where('orders.tenant_id', $tid)
                  ->whereIn('order_items.product_id', $ids);
            });
            $w->orWhereExists(function ($s) use ($tid, $ids) {
                $s->select(DB::raw(1))->from('orders')
                  ->whereColumn('orders.customer_phone', 'customer_profiles.phone')
                  ->where('orders.tenant_id', $tid)
                  ->where(function ($j) use ($ids) {
                      foreach ($ids as $id) {
                          // Delimiters keep 123 from matching 1234. Laravel json_encode has no spaces.
                          $j->orWhere('orders.items_json', 'like', '%"product_id":' . (int) $id . '}%')
                            ->orWhere('orders.items_json', 'like', '%"product_id":' . (int) $id . ',%');
                      }
                  });
            });
        };

        $did ? $q->where($predicate) : $q->whereNot($predicate);
    }

    /** @return array{0:?Carbon,1:?Carbon} [from(startOfDay), to(endOfDay)] — nulls when unparseable. */
    private static function range(array $range): array
    {
        $parse = function ($v, bool $end) {
            $v = trim((string) $v);
            if ($v === '') { return null; }
            try {
                $d = Carbon::parse($v);
                return $end ? $d->endOfDay() : $d->startOfDay();
            } catch (\Throwable $e) {
                return null;
            }
        };

        return [$parse($range['from'] ?? null, false), $parse($range['to'] ?? null, true)];
    }
}
