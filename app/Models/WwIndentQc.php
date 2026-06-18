<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class WwIndentQc extends Model
{
    use BelongsToTenant;
    protected $table = 'ww_indent_qc';
    protected $fillable = ['tenant_id','indent_id','process','production_at','supervisor_sign','supervisor_at','qc_sign','qc_at','sec_head_sign','sec_head_at','result'];
    protected $casts = ['production_at'=>'datetime','supervisor_at'=>'datetime','qc_at'=>'datetime','sec_head_at'=>'datetime'];
    public function indent() { return $this->belongsTo(WwIndent::class, 'indent_id'); }
}
