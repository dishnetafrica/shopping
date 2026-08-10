<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** A return / refund / store-credit event against an order. */
class ReturnRecord extends Model
{
    use BelongsToTenant;

    protected $table = 'order_returns';
    protected $fillable = ['tenant_id', 'order_id', 'customer_phone', 'customer_name', 'items_text', 'amount', 'resolution', 'reason',
        // POS returns (v4.0): structured lines, refund method, CN linkage, operator (shift math).
        'items_json', 'refund_method', 'credit_note_no', 'order_no', 'user_id', 'operator',
        // v4.89 (ADR-019): a return has a life — recorded, then received. `requested_json` is what
        // was promised; `items_json` is what actually came off the truck.
        'status', 'received_at', 'received_by', 'requested_json'];
    protected $casts = ['amount' => 'float', 'items_json' => 'array', 'requested_json' => 'array', 'received_at' => 'datetime'];

    public function isPending(): bool { return (string) $this->status === \App\Services\Sales\ReturnPolicy::PENDING; }
}
