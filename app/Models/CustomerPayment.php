<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerPayment extends Model
{
    protected $fillable = [
        'tenant_id','receipt_no','customer_name','customer_phone','order_no','invoice_no','kind',
        'currency','amount','rate_to_usd','usd_amount','method','reference','receipt_path',
        'applied','order_cost_id','holder','holder_user_id','created_by','idempotency_key',
    ];
    protected $casts = [
        'amount'=>'decimal:2','rate_to_usd'=>'decimal:6','usd_amount'=>'decimal:2','applied'=>'boolean',
    ];
}
