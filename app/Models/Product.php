<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use BelongsToTenant;
    protected $fillable = ['tenant_id','name','description','sku','category','brand','price','base_price','cost_price','stock','barcode','keywords','image_url','gallery_1','gallery_2','gallery_3','active','sold_by_weight','weight_unit','reference_weight_grams','reference_price','display_order','is_fresh_today','moq','pack_size','unit_label','archived_at'];
    protected $casts = ['price'=>'decimal:2','base_price'=>'decimal:2','cost_price'=>'decimal:2','active'=>'boolean','sold_by_weight'=>'boolean','reference_weight_grams'=>'integer','reference_price'=>'decimal:2','display_order'=>'integer','is_fresh_today'=>'boolean','archived_at'=>'datetime'];

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
