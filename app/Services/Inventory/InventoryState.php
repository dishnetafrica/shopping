<?php
namespace App\Services\Inventory;

use App\Models\Product;

/**
 * Inventory Confidence — a shared primitive (not a capability). It answers "do we actually KNOW
 * this is available?" rather than assuming yes. A product's stock has three confidences:
 *   exact     — counted/adjusted recently; we trust the number
 *   estimated — was known once but is now stale, or the owner says "usually yes"
 *   unknown   — never counted, or explicitly cleared
 * The bot's CONFIDENCE changes with the answer, so it stops pretending certainty it doesn't have.
 */
class InventoryState
{
    public const EXACT = 'exact';
    public const ESTIMATED = 'estimated';
    public const UNKNOWN = 'unknown';
    public const FRESH_HOURS = 24;

    /** @return array{state:string,qty:?int,checked_at:?\Illuminate\Support\Carbon,source:?string,line:string} */
    public function state(Product $p, ?int $freshHours = null): array
    {
        $freshHours ??= self::FRESH_HOURS;
        $conf    = (string) ($p->stock_confidence ?: self::UNKNOWN);
        $qty     = $p->stock === null ? null : (int) $p->stock;
        $checked = $p->stock_checked_at;
        $fresh   = $checked && $checked->gt(now()->subHours($freshHours));

        // A confirmed count that has gone stale degrades to "estimated", not "exact".
        if ($conf === self::EXACT && $qty !== null && ! $fresh) $conf = self::ESTIMATED;
        if ($conf === self::EXACT && $qty === null)            $conf = self::UNKNOWN;

        $line = match (true) {
            $conf === self::EXACT && $qty !== null && $qty <= 0 => 'Out of stock',
            $conf === self::EXACT && $qty !== null              => "{$qty} available",
            $conf === self::ESTIMATED                           => 'Usually available' . ($checked ? ' (last checked ' . $checked->diffForHumans() . ')' : ''),
            default                                             => "I'll confirm before placing your order",
        };

        return ['state' => $conf, 'qty' => $qty, 'checked_at' => $checked, 'source' => $p->stock_source, 'line' => $line];
    }

    /** Compact tag for the AI catalogue, so the model reflects confidence instead of inventing it. */
    public function botTag(Product $p): string
    {
        $s = $this->state($p);
        return match (true) {
            $s['state'] === self::EXACT && ($s['qty'] ?? 0) <= 0 => 'OUT OF STOCK',
            $s['state'] === self::EXACT                          => 'in stock: ' . $s['qty'],
            $s['state'] === self::ESTIMATED                      => 'usually available — confirm',
            default                                              => 'stock unknown — confirm before promising',
        };
    }

    // ---- mutators (the only writers of inventory confidence) ----

    public function recordCount(Product $p, int $qty, string $source = 'count'): Product
    {
        $p->forceFill(['stock' => max(0, $qty), 'stock_confidence' => self::EXACT,
            'stock_checked_at' => now(), 'stock_source' => $source])->save();
        return $p;
    }

    public function markEstimated(Product $p, ?int $qty = null, string $source = 'owner'): Product
    {
        $fill = ['stock_confidence' => self::ESTIMATED, 'stock_checked_at' => now(), 'stock_source' => $source];
        if ($qty !== null) $fill['stock'] = max(0, $qty);
        $p->forceFill($fill)->save();
        return $p;
    }

    public function markUnknown(Product $p): Product
    {
        $p->forceFill(['stock_confidence' => self::UNKNOWN, 'stock_source' => null])->save();
        return $p;
    }

    /** A sale reduces a KNOWN count and keeps it fresh; leaves unknown stock unknown. */
    public function decrementOnSale(Product $p, int $by): Product
    {
        if ($p->stock_confidence === self::EXACT && $p->stock !== null) {
            $p->forceFill(['stock' => max(0, (int) $p->stock - max(0, $by)),
                'stock_checked_at' => now(), 'stock_source' => 'sale'])->save();
        }
        return $p;
    }
}
