<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use BelongsToTenant;
    protected $fillable = ['tenant_id','name','description','sku','category','price','base_price','stock','barcode','keywords','image_url','gallery_1','gallery_2','gallery_3','active','sold_by_weight','weight_unit','reference_weight_grams','reference_price','display_order','is_fresh_today'];
    protected $casts = ['price'=>'decimal:2','base_price'=>'decimal:2','active'=>'boolean','sold_by_weight'=>'boolean','reference_weight_grams'=>'integer','reference_price'=>'decimal:2','display_order'=>'integer','is_fresh_today'=>'boolean'];

    public function weightVariants() { return $this->hasMany(\App\Models\ProductWeightVariant::class); }

    public function modifierGroups() { return $this->belongsToMany(ModifierGroup::class, 'product_modifier_group')->orderBy('product_modifier_group.sort'); }
}
