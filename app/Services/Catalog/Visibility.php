<?php declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;

/**
 * v4.54 — products that stay in the system but off the shelf.
 *
 * Some things are sold deliberately rather than displayed: tobacco, restricted lines, clearance
 * stock, anything promoted by targeted WhatsApp advert rather than by being browsed. They must be
 * fully present for staff — stock, cost, margin, reports, campaigns — and completely absent from
 * everything a customer can browse their way into.
 *
 * The model is an UNLISTED product, in the sense a video is unlisted: it does not appear in any
 * list, but a direct link works and a customer who names it gets served. That is the difference
 * between this and `active = false`, which also removes it from staff screens, from the bot, and
 * from being sellable at all.
 *
 * Two ways to mark one, because both are needed in practice:
 *   · per product   — `products.hidden`, for a one-off in an otherwise ordinary category
 *   · per category  — `categories.active = false`, so a whole department is hidden by default and
 *                     next month's new flavour is hidden without anybody remembering to tick it
 *
 * EVERY surface asks this class. That is the whole point: sixty-odd places read products, and the
 * only way a rule like this survives contact with the next feature is if there is exactly one
 * definition of it to find.
 */
final class Visibility
{
    /**
     * Categories this tenant hides wholesale, lowercased and trimmed.
     *
     * Read from `categories.active = false`, which ALREADY meant exactly this — the storefront feed
     * has dropped inactive departments for a long time. Rather than invent a second, parallel notion
     * of hidden, this extends the one that exists to every other surface that was still showing
     * them. The flag had no UI outside the Filament backend, which is most of why nobody knew.
     *
     * Cached briefly: this is asked on essentially every product query, and it is one small table.
     */
    public static function hiddenCategories(Tenant $t): array
    {
        try {
            return \Illuminate\Support\Facades\Cache::remember(
                'hidden_cats:' . $t->id, 120,
                fn () => \App\Models\Category::withoutGlobalScopes()
                    ->where('tenant_id', $t->id)->where('active', false)->pluck('name')
                    ->map(fn ($n) => mb_strtolower(trim((string) $n)))
                    ->filter(fn ($n) => $n !== '')->values()->all()
            );
        } catch (\Throwable $e) {
            return [];   // never let a visibility lookup take down a page
        }
    }

    /** Drop the category cache — called whenever a category is toggled. Takes a model or an id. */
    public static function forgetCategories($tenant): void
    {
        $id = $tenant instanceof Tenant ? (int) $tenant->id : (int) $tenant;
        try { \Illuminate\Support\Facades\Cache::forget('hidden_cats:' . $id); } catch (\Throwable $e) {}
    }

    /** Is this category hidden wholesale? */
    public static function categoryHidden(Tenant $t, ?string $category): bool
    {
        $c = mb_strtolower(trim((string) $category));

        return $c !== '' && in_array($c, self::hiddenCategories($t), true);
    }

    /**
     * PURE — the decision, given only the two inputs that make it. Separated from Eloquent so the
     * harness can hammer it, and so array-shaped catalogues (the bot builds several) can ask the
     * same question rows-in-a-database do.
     */
    public static function isHidden(?string $category, $flag, array $hiddenCategories): bool
    {
        if (self::truthy($flag)) {
            return true;
        }
        $c = mb_strtolower(trim((string) $category));

        return $c !== '' && in_array($c, $hiddenCategories, true);
    }

    /** Does this product row belong off the shelf? Accepts a model or a plain array/object row. */
    public static function hides(Tenant $t, $row): bool
    {
        $get = function ($k) use ($row) {
            if (is_array($row)) {
                return $row[$k] ?? null;
            }

            return is_object($row) ? ($row->$k ?? null) : null;
        };

        return self::isHidden((string) $get('category'), $get('hidden'), self::hiddenCategories($t));
    }

    /**
     * Constrain a query to what the public may browse.
     *
     * Written as `hidden IS NULL OR hidden = false` rather than `hidden = false` on purpose: rows
     * that existed before the column did read NULL, and `hidden = false` silently drops every one
     * of them — which would empty the storefront on the morning of the migration.
     *
     * @param  Tenant|int  $tenant  a model or just an id — half the call sites only have the id,
     *                              and making each of them look the tenant up would be four extra
     *                              queries per page for no gain.
     */
    public static function scope(Builder $q, $tenant): Builder
    {
        $t = self::tenant($tenant);
        if (! $t) {
            return $q;
        }
        if (self::column()) {
            $q->where(function ($w) {
                $w->whereNull('products.hidden')->orWhere('products.hidden', false);
            });
        }
        $hidden = self::hiddenCategories($t);
        if ($hidden) {
            // Raw rather than whereNotIn, because the comparison has to happen on the LOWERCASED,
            // trimmed value — "Shisha & Molasses" and "shisha & molasses " are the same department
            // to everyone except a database. Columns are table-qualified so this survives being
            // dropped into a query that joins order_items.
            $marks = implode(',', array_fill(0, count($hidden), '?'));
            $q->where(function ($w) use ($marks, $hidden) {
                $w->whereNull('products.category')
                  ->orWhereRaw("lower(trim(products.category)) not in ({$marks})", $hidden);
            });
        }

        return $q;
    }

    /** The same rule over an already-loaded list — the shape the bot's catalogue arrays come in. */
    public static function filterRows(Tenant $t, iterable $rows): array
    {
        return self::filterWith(self::hiddenCategories($t), $rows);
    }

    /**
     * PURE — the list filter with the hidden categories handed in. Keys are PRESERVED, because the
     * bot's catalogue arrays are sometimes indexed by product id and re-keying them would quietly
     * repoint every lookup that follows.
     */
    public static function filterWith(array $hiddenCategories, iterable $rows): array
    {
        $out = [];
        foreach ($rows as $k => $r) {
            $cat = is_array($r) ? ($r['category'] ?? null) : ($r->category ?? null);
            $flg = is_array($r) ? ($r['hidden'] ?? null) : ($r->hidden ?? null);
            if (! self::isHidden((string) $cat, $flg, $hiddenCategories)) {
                $out[$k] = $r;
            }
        }

        return $out;
    }

    /** How many of this tenant's products are off the shelf — for the settings screen to report. */
    public static function countHidden(Tenant $t): int
    {
        try {
            $all = Product::withoutGlobalScopes()->where('tenant_id', $t->id)->whereNull('archived_at')->count();
            $vis = self::scope(
                Product::withoutGlobalScopes()->where('tenant_id', $t->id)->whereNull('archived_at'),
                $t
            )->count();

            return max(0, $all - $vis);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Cached — Schema::hasColumn hits the information schema, and this is asked on every query. */
    private static ?bool $hasCol = null;

    public static function column(): bool
    {
        if (self::$hasCol === null) {
            try {
                self::$hasCol = \Illuminate\Support\Facades\Schema::hasColumn('products', 'hidden');
            } catch (\Throwable $e) {
                self::$hasCol = false;      // unmigrated box: category hiding still works
            }
        }

        return self::$hasCol;
    }

    /** Forget the column probe — tests and the migration itself. */
    public static function forget(): void
    {
        self::$hasCol = null;
        self::$tenants = [];
    }

    /** @var array<int,?Tenant> */
    private static array $tenants = [];

    /** Resolve a Tenant from a model or an id, once per request. */
    private static function tenant($t): ?Tenant
    {
        if ($t instanceof Tenant) {
            return $t;
        }
        $id = (int) $t;
        if ($id <= 0) {
            return null;
        }
        if (! array_key_exists($id, self::$tenants)) {
            try { self::$tenants[$id] = Tenant::find($id); } catch (\Throwable $e) { self::$tenants[$id] = null; }
        }

        return self::$tenants[$id];
    }

    /**
     * Of these product ids, the ones the public may see. For surfaces that arrive holding ids and
     * never touch the products table — the trending rail counts events, not rows, so it would
     * happily publish a hidden id that nobody can reach any other way.
     *
     * @param  array<int,int>  $ids
     * @return array<int,int>  the same ids, same order, minus the hidden ones
     */
    public static function visibleIds($tenant, array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (! $ids) {
            return [];
        }
        $t = self::tenant($tenant);
        if (! $t) {
            return $ids;
        }
        try {
            $ok = array_flip(self::scope(
                Product::withoutGlobalScopes()->where('tenant_id', $t->id)->whereIn('id', $ids), $t
            )->pluck('id')->map(fn ($x) => (int) $x)->all());

            return array_values(array_filter($ids, fn ($id) => isset($ok[$id])));
        } catch (\Throwable $e) {
            return $ids;
        }
    }

    /** '1', 1, true, 'yes', 'on' all mean hidden; null and '' mean visible. */
    private static function truthy($v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if ($v === null) {
            return false;
        }

        return in_array(mb_strtolower(trim((string) $v)), ['1', 'true', 'yes', 'y', 'on', 'hidden'], true);
    }
}
