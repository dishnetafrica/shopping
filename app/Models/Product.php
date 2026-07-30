<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use BelongsToTenant;
    protected $fillable = ['tenant_id','name','description','sku','category','brand','price','base_price','cost_price','stock','barcode','keywords','image_url','gallery_1','gallery_2','gallery_3','active','sell_mode','serialized','warranty_manufacturer_months','warranty_company_months','is_kit','sold_by_weight','weight_unit','reference_weight_grams','reference_price','display_order','is_fresh_today','moq','pack_size','unit_label','archived_at'];

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
    protected $casts = ['price'=>'decimal:2','base_price'=>'decimal:2','cost_price'=>'decimal:2','active'=>'boolean','serialized'=>'boolean','warranty_manufacturer_months'=>'integer','warranty_company_months'=>'integer','is_kit'=>'boolean','sold_by_weight'=>'boolean','reference_weight_grams'=>'integer','reference_price'=>'decimal:2','display_order'=>'integer','is_fresh_today'=>'boolean','archived_at'=>'datetime'];

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
                ->onHandFor($this->id, null, \App\Services\Inventory\StockLedger::LOC_KAMPALA);
        }
        return (float) ($this->stock ?? 0);
    }

    public function weightVariants() { return $this->hasMany(\App\Models\ProductWeightVariant::class); }

    public function modifierGroups() { return $this->belongsToMany(ModifierGroup::class, 'product_modifier_group')->orderBy('product_modifier_group.sort'); }
}
