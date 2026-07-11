<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SourcingRequest extends Model
{
    protected $fillable = [
        'tenant_id','item_name','note','demand','photo_path','status',
        'quoted_price','currency','supplier_name','availability','price_note','priced_by','priced_at',
        'requested_by','requested_by_user_id',
        'notified_at','last_reminded_at','reminder_count','product_id',
    ];
    protected $casts = [
        'quoted_price'=>'decimal:2','priced_at'=>'datetime',
        'notified_at'=>'datetime','last_reminded_at'=>'datetime','reminder_count'=>'integer',
    ];
}
