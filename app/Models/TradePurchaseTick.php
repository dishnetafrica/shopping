<?php

namespace App\Models;
use App\Models\Concerns\BelongsToTenant;

use Illuminate\Database\Eloquent\Model;

class TradePurchaseTick extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id','order_no','item_name','qty','created_by'];
    protected $casts = ['qty' => 'decimal:2'];
}
