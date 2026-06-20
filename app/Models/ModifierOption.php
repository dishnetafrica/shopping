<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModifierOption extends Model
{
    protected $fillable = ['modifier_group_id','name','price_delta','sort','active'];
    protected $casts = ['price_delta'=>'decimal:2','sort'=>'integer','active'=>'boolean'];

    public function group() { return $this->belongsTo(ModifierGroup::class, 'modifier_group_id'); }
}
