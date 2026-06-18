<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\Winworld\Formula;
use Illuminate\Database\Eloquent\Model;

class WwItem extends Model
{
    use BelongsToTenant;
    protected $table = 'ww_items';
    protected $fillable = ['tenant_id','item_code','item_name','item_group','width_inch','length_inch','gauge','gram_per_pcs','status'];
    protected $casts = ['width_inch'=>'decimal:3','length_inch'=>'decimal:3','gauge'=>'decimal:3','gram_per_pcs'=>'decimal:4'];

    /** Recompute and store gram/pcs from dimensions (W x L x gauge / 3300). */
    public function recomputeGramPerPcs(): float
    {
        $g = Formula::gramPerPcs((float)$this->width_inch, (float)$this->length_inch, (float)$this->gauge);
        $this->gram_per_pcs = round($g, 4);
        return (float)$this->gram_per_pcs;
    }

    public function indents() { return $this->hasMany(WwIndent::class, 'item_id'); }
}
