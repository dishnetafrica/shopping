<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TradePurchaseTick extends Model
{
    protected $fillable = ['tenant_id','order_no','item_name','qty','created_by'];
    protected $casts = ['qty' => 'decimal:2'];
}
