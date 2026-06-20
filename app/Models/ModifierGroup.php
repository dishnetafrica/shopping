<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ModifierGroup extends Model
{
    use BelongsToTenant;
    protected $fillable = ['tenant_id','name','required','min_select','max_select','free_qty','sort','active'];
    protected $casts = ['required'=>'boolean','active'=>'boolean','min_select'=>'integer','max_select'=>'integer','free_qty'=>'integer','sort'=>'integer'];

    public function options() { return $this->hasMany(ModifierOption::class)->orderBy('sort'); }
    public function products() { return $this->belongsToMany(Product::class, 'product_modifier_group'); }
}
