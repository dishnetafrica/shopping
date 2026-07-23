<?php

namespace App\Models;
use App\Models\Concerns\BelongsToTenant;

use Illuminate\Database\Eloquent\Model;

class PartnerCapital extends Model
{
    use BelongsToTenant;

    protected $table = 'partner_capital';
    protected $fillable = ['tenant_id','partner_id','kind','currency','native_amount',
        'rate_to_usd','usd_amount','note','order_cost_id','created_by'];
    protected $casts = ['native_amount'=>'decimal:2','rate_to_usd'=>'decimal:6','usd_amount'=>'decimal:2'];
}
