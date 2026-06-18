<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class WwCustomer extends Model
{
    use BelongsToTenant;
    protected $table = 'ww_customers';
    protected $fillable = ['tenant_id','customer_code','name','credit_limit_days','ageing_balance','overdue_days','contact'];
    protected $casts = ['credit_limit_days'=>'integer','ageing_balance'=>'decimal:2','overdue_days'=>'integer'];

    public function indents() { return $this->hasMany(WwIndent::class, 'customer_id'); }

    /** SOP gate: overdue above 30 days needs MD approval. */
    public function needsMdApproval(): bool { return (int)$this->overdue_days > 30; }
}
