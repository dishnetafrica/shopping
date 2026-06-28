<?php
namespace App\Services\Inventory;

use App\Models\InventorySnapshot;
use App\Models\Product;

/**
 * Inventory Confidence — a shared primitive over Inventory Snapshots (not a Product property).
 *
 * Three distinct things are kept apart: the FACT (quantity), the CONFIDENCE (how sure we are), and
 * the FRESHNESS (observed_at). Confidence DECAYS automatically with age, so the bot grows cautious
 * on its own without anyone updating anything:
 *
 *   exact  --(EXACT_HOURS)-->  estimated  --(ESTIMATED_HOURS)-->  unknown
 *
 * The bot's certainty therefore tracks what we actually know, and it stops promising availability it
 * can't stand behind.
 *
 * Future (documented, not built): confidence is expected to weigh the OBSERVATION METHOD too — a
 * manual count or warehouse scan is more trustworthy than an overnight ERP import or an AI estimate
 * — and a snapshot may carry an explicit `expires_at` so the decay windows become tenant config
 * rather than constants here. Consumers must depend only on the derived state(), never on how it is
 * computed, so these can change without touching any capability.
 */
class InventoryState
{
    public const EXACT = 'exact';
    public const ESTIMATED = 'estimated';
    public const UNKNOWN = 'unknown';

    public const EXACT_HOURS = 12;       // a confirmed count is "exact" for this long…
    public const ESTIMATED_HOURS = 72;   // …then "estimated" up to here, then "unknown"

    /** @var array<int,?InventorySnapshot> product_id => snapshot (default location), when primed */
    private array $primed = [];

    /** Preload default-location snapshots for many products in one query (e.g. the catalogue). */
    public function prime(iterable $products): void
    {
        $ids = collect($products)->pluck('id')->filter()->all();
        $this->primed = [];
        if (! $ids) return;
        $snaps = InventorySnapshot::query()->whereIn('product_id', $ids)->where('location', 'default')
            ->get()->keyBy('product_id');
        foreach ($ids as $id) $this->primed[$id] = $snaps->get($id);
    }

    /** @return array{state:string,qty:?int,observed_at:?\Illuminate\Support\Carbon,source:?string,line:string} */
    public function state(Product $p, string $location = 'default', ?int $exactHours = null, ?int $estimatedHours = null): array
    {
        $exactHours ??= self::EXACT_HOURS;
        $estimatedHours ??= self::ESTIMATED_HOURS;
        $snap = $this->snapshot($p, $location);

        if (! $snap) {
            return ['state' => self::UNKNOWN, 'qty' => null, 'observed_at' => null, 'source' => null,
                'line' => "I'll confirm before placing your order"];
        }

        $qty = $snap->quantity === null ? null : (int) $snap->quantity;
        $obs = $snap->observed_at;
        $within = fn (int $h) => $obs && $obs->gt(now()->subHours($h));

        // Automatic decay by age of the observation.
        $conf = match ($snap->confidence) {
            self::EXACT     => $within($exactHours) ? self::EXACT : ($within($estimatedHours) ? self::ESTIMATED : self::UNKNOWN),
            self::ESTIMATED => $within($estimatedHours) ? self::ESTIMATED : self::UNKNOWN,
            default         => self::UNKNOWN,
        };
        if ($conf !== self::UNKNOWN && $qty === null) $conf = self::UNKNOWN;

        $line = match (true) {
            $conf === self::EXACT && $qty !== null && $qty <= 0 => 'Out of stock',
            $conf === self::EXACT && $qty !== null              => "{$qty} available",
            $conf === self::ESTIMATED                           => 'Usually available' . ($obs ? ' (last checked ' . $obs->diffForHumans() . ')' : ''),
            default                                             => "I'll confirm before placing your order",
        };

        return ['state' => $conf, 'qty' => $qty, 'observed_at' => $obs, 'source' => $snap->source, 'line' => $line];
    }

    /** Compact tag for the AI catalogue so the model reflects confidence instead of inventing it. */
    public function botTag(Product $p, string $location = 'default'): string
    {
        $s = $this->state($p, $location);
        return match (true) {
            $s['state'] === self::EXACT && ($s['qty'] ?? 0) <= 0 => 'OUT OF STOCK',
            $s['state'] === self::EXACT                          => 'in stock: ' . $s['qty'],
            $s['state'] === self::ESTIMATED                      => 'usually available — confirm',
            default                                              => 'stock unknown — confirm before promising',
        };
    }

    // ---- mutators: the only writers of inventory belief (one snapshot per product+location) ----

    public function recordCount(Product $p, int $qty, string $source = 'count', string $location = 'default'): InventorySnapshot
    {
        return $this->write($p, $location, ['quantity' => max(0, $qty), 'confidence' => self::EXACT, 'source' => $source]);
    }

    public function markEstimated(Product $p, ?int $qty = null, string $source = 'owner', string $location = 'default'): InventorySnapshot
    {
        $vals = ['confidence' => self::ESTIMATED, 'source' => $source];
        if ($qty !== null) $vals['quantity'] = max(0, $qty);
        return $this->write($p, $location, $vals);
    }

    public function markUnknown(Product $p, string $location = 'default'): InventorySnapshot
    {
        return $this->write($p, $location, ['confidence' => self::UNKNOWN, 'source' => null]);
    }

    /** A sale reduces a KNOWN count and keeps it fresh; unknown stock stays unknown. */
    public function decrementOnSale(Product $p, int $by, string $location = 'default'): ?InventorySnapshot
    {
        $snap = $this->snapshot($p, $location);
        if ($snap && $snap->confidence === self::EXACT && $snap->quantity !== null) {
            return $this->write($p, $location, ['quantity' => max(0, (int) $snap->quantity - max(0, $by)), 'source' => 'sale']);
        }
        return $snap;
    }

    private function write(Product $p, string $location, array $vals): InventorySnapshot
    {
        unset($this->primed[$p->id]);   // invalidate any primed cache for this product
        return InventorySnapshot::updateOrCreate(
            ['tenant_id' => $p->tenant_id, 'product_id' => $p->id, 'location' => $location],
            array_merge($vals, ['observed_at' => now()]),
        );
    }

    private function snapshot(Product $p, string $location): ?InventorySnapshot
    {
        if ($location === 'default' && array_key_exists($p->id, $this->primed)) return $this->primed[$p->id];
        return InventorySnapshot::query()->where('product_id', $p->id)->where('location', $location)->first();
    }
}
