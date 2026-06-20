<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use BelongsToTenant;
    protected $fillable = ['tenant_id','name','description','sku','category','price','base_price','stock','barcode','keywords','image_url','active'];
    protected $casts = ['price'=>'decimal:2','base_price'=>'decimal:2','active'=>'boolean'];

    public function modifierGroups() { return $this->belongsToMany(ModifierGroup::class, 'product_modifier_group')->orderBy('product_modifier_group.sort'); }
}
