<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use BelongsToTenant;
    protected $fillable = ['tenant_id','name','description','sku','oem_number','category','brand','price','piece_price','base_price','cost_price','stock','barcode','keywords','image_url','gallery_1','gallery_2','gallery_3','active','sell_mode','serialized','warranty_manufacturer_months','warranty_company_months','is_kit','sold_by_weight','weight_unit','reference_weight_grams','reference_price','display_order','is_fresh_today','moq','pack_size','unit_label','unit_size','origin_country','hs_code','gross_weight_grams','archived_at','hidden','reorder_point'];

    /** Configurable product specifications (Asset Lifecycle · product_specs). Additive read relation. */
    public function specs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\ProductSpec::class);
    }

    /** Serial-tracked physical units (Asset Lifecycle · serialized_inventory). Additive read relation. */
    public function units(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\InventoryUnit::class);
    }

    /** The parts this product is made of, when it's a kit/bundle (Asset Lifecycle · S4a). Additive. */
    public function kitComponents(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\KitComponent::class, 'kit_product_id');
    }

    /**
     * The primary/featured photo for cards, listings and the catalogue feed — the ONE resolver
     * for "which photo shows first".
     *
     * A product's first photo may live in image_url (the hero) OR — when photos were uploaded into
     * the gallery slots without a hero being set — in gallery_1..3. The storefront product-detail
     * gallery already falls back through these columns (StorefrontProductController::gallery), but the
     * card/listing feed read image_url alone, so a product whose only photos were in the gallery
     * rendered a placeholder ("first photo not showing after multiple photos"). Both paths now resolve
     * the primary through here: the first non-empty of hero → gallery_1 → gallery_2 → gallery_3.
     *
     * Note: this is a DISPLAY resolver, not an authoring one — the seller panel deliberately shows the
     * raw hero/gallery columns so the owner can see and set each slot.
     */
    public function primaryImage(): ?string
    {
        return self::firstImage($this->image_url, $this->gallery_1, $this->gallery_2, $this->gallery_3);
    }

    /** First non-empty image among the given candidates (hero, then gallery slots), or null. Pure. */
    public static function firstImage(?string ...$candidates): ?string
    {
        foreach ($candidates as $c) {
            $c = trim((string) $c);
            if ($c !== '') {
                return $c;
            }
        }

        return null;
    }

    /**
     * Does this image reference point at a real stored file (or remote URL), rather than a bare
     * filename that resolves to a dead /storage/<name> path?
     *
     * Every photo the app stores lands under products/{tenant}/… (uploadImage, the CSV/ZIP importers,
     * gallery resolution) — so a usable reference is either an absolute URL, a root-relative path, or
     * a storage path that CONTAINS a slash. A bare "Diamond Solar Lawn Light.jpg" (no slash, not a URL)
     * is a broken value: imageUrl() turns it into /storage/Diamond Solar Lawn Light.jpg, which never
     * exists. This lets the photo re-importer treat such a value as "no real photo" and heal it, instead
     * of mistaking it for an existing main and skipping the product.
     */
    public static function isUsableImageRef(?string $v): bool
    {
        $v = trim((string) $v);
        if ($v === '') {
            return false;
        }

        return preg_match('#^https?://#i', $v) === 1 || str_contains($v, '/');
    }
    protected $casts = ['price'=>'decimal:2','piece_price'=>'decimal:4','base_price'=>'decimal:2','cost_price'=>'decimal:2','active'=>'boolean','serialized'=>'boolean','warranty_manufacturer_months'=>'integer','warranty_company_months'=>'integer','is_kit'=>'boolean','sold_by_weight'=>'boolean','reference_weight_grams'=>'integer','reference_price'=>'decimal:2','display_order'=>'integer','is_fresh_today'=>'boolean','archived_at'=>'datetime','hidden'=>'boolean','reorder_point'=>'integer'];

    /** E6.4 (prereq) — mirror catalog stock changes into the one stock ledger (gated on stock_ledger). */
    protected static function booted(): void
    {
        static::observe(\App\Observers\ProductStockObserver::class);

        // Price-drift fix (2026-07-18): base_price is a legacy mirror of the selling
        // price (ProductWriteService always wrote them together), but edit paths that
        // set `price` alone let them diverge — and storefront/bot preferred the stale
        // base_price, silently selling at OLD prices. Sync on every save: drift is now
        // structurally impossible, whichever screen edits the price.
        static::saving(function (self $p) {
            if ((float) $p->price > 0 && (float) $p->price !== (float) $p->base_price) {
                $p->base_price = $p->price;
            }
        });

        // Catalogue freshness (2026-07-30): any product change invalidates the cached PDF
        // catalogues (whole-shop + this product's category) and the bot's 60s grounded list,
        // so a newly added or re-priced product reaches customers on the very next ask —
        // no waiting for the 6h TTL, no manual catalogue:flush.
        $flush = function (self $p): void {
            try {
                $id = (int) $p->tenant_id;
                if (! $id) return;
                \Illuminate\Support\Facades\Cache::forget("ai_catalogue:{$id}");
                \Illuminate\Support\Facades\Cache::forget("catalog_pdf:{$id}:p");
                \Illuminate\Support\Facades\Cache::forget("catalog_pdf:{$id}:np");
                foreach (array_unique(array_filter([(string) $p->category, (string) $p->getOriginal('category')])) as $c) {
                    $h = md5(mb_strtolower(trim($c), 'UTF-8'));
                    \Illuminate\Support\Facades\Cache::forget("catalog_pdf:{$id}:cat:{$h}:p");
                    \Illuminate\Support\Facades\Cache::forget("catalog_pdf:{$id}:cat:{$h}:np");
                }

                // v4.54 — five more caches hold product lists, and none of them were being dropped.
                // It mattered less when the worst case was a stale price; it matters a great deal now
                // that hiding a product is a thing somebody can do. A product hidden at 9:00 that is
                // still on the storefront feed at 9:04 has not been hidden — it has been delayed.
                foreach ([
                    "catalogue:{$id}",                  // the public storefront feed (5 min)
                    "sf_cats:{$id}",                    // category chips, counts and /c/ pages (10 min)
                    "bot_catalog:{$id}",                // the external bot bridge dump (60 s)
                    "ai_catalogue_overview:{$id}",      // "here is what we sell" (5 min)
                    "ai_calc_catalogue:{$id}",          // pricing / cart maths (60 s)
                    "trending:{$id}",                   // the home rail (10 min)
                ] as $key) {
                    \Illuminate\Support\Facades\Cache::forget($key);
                }
                // The recommendation rails and category peers are keyed per product, so only this
                // product's own entries can be reached by name. The rest age out inside 15 minutes.
                foreach ([
                    "reco:similar:{$p->id}:12", "reco:also:{$p->id}:12",
                    "combo:catpeers:{$id}:{$p->id}",
                ] as $key) {
                    \Illuminate\Support\Facades\Cache::forget($key);
                }
            } catch (\Throwable $e) {
                // cache invalidation is best-effort — never block a product save
            }
        };
        static::saved($flush);
        static::deleted($flush);
    }

    /**
     * Effective on-hand at Kampala (E6.4). Reads the stock ledger projection when the tenant has
     * opted into ledger reads (stock_v2 + stock_ledger), otherwise the legacy `stock` column. When
     * the ledger is maintained the two agree; this makes the ledger the source of truth without
     * changing the number, so E6.5 can later drop the column.
     */
    public function onHand(): float
    {
        $t = $this->tenant;
        if ($t && \App\Services\Inventory\StockLedger::readsFromLedger($t)) {
            return \App\Services\Inventory\StockLedger::forTenant($t)
                // v4.64.2 — the shop's own warehouse, not Kampala by assumption. Otherwise a Juba
                // shop reads on-hand from a location nothing ever receives into.
                ->onHandFor($this->id, null, \App\Services\Inventory\StockLedger::home($t));
        }
        return (float) ($this->stock ?? 0);
    }

    public function weightVariants() { return $this->hasMany(\App\Models\ProductWeightVariant::class); }

    public function modifierGroups() { return $this->belongsToMany(ModifierGroup::class, 'product_modifier_group')->orderBy('product_modifier_group.sort'); }
}
