<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** A return / refund / store-credit event against an order. */
class ReturnRecord extends Model
{
    use BelongsToTenant;

    protected $table = 'order_returns';
    protected $fillable = ['tenant_id', 'order_id', 'customer_phone', 'customer_name', 'items_text', 'amount', 'resolution', 'reason'];
    protected $casts = ['amount' => 'float'];
}
