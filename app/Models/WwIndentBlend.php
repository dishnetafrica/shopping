<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class WwIndentBlend extends Model
{
    use BelongsToTenant;
    protected $table = 'ww_indent_blends';
    protected $fillable = ['tenant_id','indent_id','line_no','material_id','material_name','pct_a','qty_a','pct_b','qty_b','pct_c','qty_c'];
    protected $casts = [
        'line_no'=>'integer',
        'pct_a'=>'decimal:3','qty_a'=>'decimal:3','pct_b'=>'decimal:3','qty_b'=>'decimal:3','pct_c'=>'decimal:3','qty_c'=>'decimal:3',
    ];
    public function indent() { return $this->belongsTo(WwIndent::class, 'indent_id'); }
}
